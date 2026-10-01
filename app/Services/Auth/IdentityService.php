<?php

namespace App\Services\Auth;

use App\Enums\Role;
use App\Exceptions\IdentityException;
use App\Models\Business;
use App\Models\Customer;
use App\Models\OAuthAccount;
use App\Models\User;
use App\Support\Identity;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Turns proof of identity into a signed-in user of this app.
 *
 * A provider (Google, or a password checked in this codebase) answers "is
 * this person who they say they are?". This service answers the second,
 * separate question: "which laundry are they signing in to, and what may
 * they do there?".
 *
 * Neither answer is ever taken from anything the person typed at the login
 * screen, except the laundry's own short code. In particular:
 *
 *  - the role always comes from a row an Admin created in advance, so it
 *    cannot be chosen or escalated at sign-in;
 *  - a staff account is *adopted*, not replaced, so an Admin's assignment
 *    survives the person joining with a different method;
 *  - a Google identity is matched on its provider id, never on an email
 *    address, because an Admin can type any email into a staff record and
 *    an unverified address must never be able to claim an account.
 */
class IdentityService
{
    /**
     * Resolve the profile for an identity, creating one only when the person
     * is a genuine new customer of a laundry they named themselves.
     *
     * @param  string|null  $businessCode  Short code, needed the first time.
     * @param  string|null  $mobile  E.164 number used to match an existing
     *                               staff or customer record.
     * @param  string|null  $name  Only used when a customer record has to be
     *                             created.
     *
     * @throws IdentityException
     */
    public function resolveUser(
        Identity $identity,
        ?string $businessCode = null,
        ?string $mobile = null,
        ?string $name = null,
    ): User {
        $mobile = $this->normaliseMobile($mobile ?? $identity->mobile);

        $existing = $this->findExistingUser($identity);

        if ($existing !== null) {
            // A linked identity is already bound to one laundry. If the
            // person names a different one, say so rather than silently
            // dropping them into the wrong tenant.
            $this->assertSameBusiness($existing, $businessCode);

            $this->link($existing, $identity);

            return $existing;
        }

        // A password account is found by its email; reaching here means the
        // address is free.
        if ($identity->provider === Identity::PASSWORD && $identity->email !== null) {
            $taken = $this->findByEmail($identity->email);

            if ($taken !== null) {
                throw IdentityException::emailTaken();
            }
        }

        $business = $this->resolveBusiness($businessCode);

        if ($business === null) {
            throw IdentityException::businessRequired();
        }

        // A staff record exists already, created by an Admin. It is adopted
        // so the assigned role is kept, not overwritten.
        $employee = $this->findEmployee($business, $mobile);

        if ($employee !== null) {
            $this->assertEmailFree($employee, $identity);
            $this->link($employee, $identity);

            return $employee;
        }

        $customer = $this->resolveCustomer($business, $mobile, $name ?? $identity->name);

        $mirror = $this->findCustomerUser($business, $mobile);

        if ($mirror !== null) {
            $this->assertEmailFree($mirror, $identity);
            $this->link($mirror, $identity);

            return $mirror;
        }

        return $this->createUser($identity, $business, $mobile, $customer);
    }

    /**
     * Refuse to create a password account on an address that is already in
     * use.
     *
     * Signing in can look an account up by email, because the password proves
     * it. Creating one cannot: anybody who knows an address could otherwise
     * register against it, be handed the existing profile, and have a password
     * set on it. Registration therefore requires the address to be unused, and
     * the way to take over an existing account is to sign in, not to re-register.
     *
     * @throws IdentityException
     */
    public function assertEmailAvailable(string $email): void
    {
        if ($this->findByEmail($email) !== null) {
            throw IdentityException::emailTaken();
        }
    }

    /**
     * Record the identity against the user, without touching their role.
     */
    public function link(User $user, Identity $identity): void
    {
        DB::transaction(function () use ($user, $identity) {
            // An email address is how a password account is found next time,
            // so it has to be recorded even when the row already existed as a
            // staff record. Whether the address is trustworthy enough to
            // record is the question `mayRecordEmail` answers.
            if ($user->email === null && $this->mayRecordEmail($identity)) {
                $user->forceFill([
                    'email' => $identity->email,
                    'email_verified_at' => $identity->emailVerified && $user->email_verified_at === null
                        ? now()
                        : $user->email_verified_at,
                ])->save();
            }

            if (! $identity->isExternal() || $identity->providerId === null) {
                return;
            }

            // Already linked: nothing to do. Deliberately not an update, so the
            // original link is never silently re-pointed.
            if ($this->findLink($identity) !== null) {
                return;
            }

            OAuthAccount::create([
                'user_id' => $user->id,
                'provider' => $identity->provider,
                'provider_id' => $identity->providerId,
                'avatar' => $identity->avatar ?? $user->avatar,
            ]);

            // Keep a copy on the user so the header can show a picture
            // without loading every provider row.
            if ($user->avatar === null && $identity->avatar !== null) {
                $user->forceFill(['avatar' => $identity->avatar])->save();
            }
        });
    }

    /**
     * Whether this identity's address may be recorded on an account that does
     * not have one yet.
     *
     * A password identity qualifies because the person chose this address and
     * this password in the very request being processed. A Google identity
     * qualifies only when Google reports the address as verified — an
     * unverified provider claim must never be written onto a staff record.
     */
    private function mayRecordEmail(Identity $identity): bool
    {
        return $identity->email !== null
            && ($identity->emailVerified || ! $identity->isExternal());
    }

    /**
     * Adopting an existing row must not quietly move an address that already
     * belongs to somebody else onto it. The unique index would reject the
     * write, but as an unhandled database error rather than as a refusal.
     */
    private function assertEmailFree(User $user, Identity $identity): void
    {
        if ($user->email !== null || ! $this->mayRecordEmail($identity)) {
            return;
        }

        if ($this->findByEmail((string) $identity->email) !== null) {
            throw IdentityException::emailTaken();
        }
    }

    /**
     * Sign a user in, rotating the session id.
     *
     * The rotation is what makes a session id captured before sign-in
     * useless afterwards. There is no session store outside an HTTP request,
     * so it is skipped in a console or queue context.
     */
    public function login(User $user): void
    {
        if (! $user->isActive()) {
            throw IdentityException::accountDeactivated();
        }

        Auth::login($user, remember: false);

        if (request()->hasSession()) {
            request()->session()->regenerate(true);
        }

        $user->forceFill(['last_login_at' => now()])->save();
    }

    public function logout(): void
    {
        Auth::logout();

        if (request()->hasSession()) {
            request()->session()->invalidate();
            request()->session()->regenerateToken();
        }
    }

    /**
     * Find the user behind an identity, if there is one.
     *
     * External identities are matched on provider id only. Email is never
     * used to attach an incoming sign-in to an existing account, because a
     * staff record's email is typed by an Admin rather than proved by the
     * person it belongs to, and an unverified provider email is worse still.
     */
    private function findExistingUser(Identity $identity): ?User
    {
        if ($identity->isExternal()) {
            return $this->findLink($identity)?->user;
        }

        if ($identity->email === null) {
            return null;
        }

        return $this->findByEmail($identity->email);
    }

    private function findLink(Identity $identity): ?OAuthAccount
    {
        if (! $identity->isExternal() || $identity->providerId === null) {
            return null;
        }

        return OAuthAccount::query()
            ->with('user')
            ->where('provider', $identity->provider)
            ->where('provider_id', $identity->providerId)
            ->first();
    }

    private function findByEmail(string $email): ?User
    {
        return User::query()
            ->withoutGlobalScopes()
            ->where('email', $email)
            ->first();
    }

    private function findEmployee(Business $business, ?string $mobile): ?User
    {
        if ($mobile === null) {
            return null;
        }

        return User::query()
            ->withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->where('mobile', $mobile)
            ->whereIn('role', array_column(Role::tenantRoles(), 'value'))
            ->first();
    }

    private function findCustomerUser(Business $business, ?string $mobile): ?User
    {
        if ($mobile === null) {
            return null;
        }

        return User::query()
            ->withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->where('mobile', $mobile)
            ->where('role', Role::CUSTOMER->value)
            ->first();
    }

    /**
     * Customers are staff-created records. One is created here only when
     * somebody signs in for the first time, names a laundry, and the number
     * was not already known to that laundry.
     */
    private function resolveCustomer(Business $business, ?string $mobile, ?string $name): Customer
    {
        if ($mobile !== null) {
            $known = Customer::query()
                ->withoutGlobalScopes()
                ->where('business_id', $business->id)
                ->where('mobile', $mobile)
                ->first();

            if ($known !== null) {
                return $known;
            }
        }

        if ($mobile === null) {
            throw IdentityException::mobileRequired();
        }

        $name = trim((string) $name);

        if ($name === '') {
            throw IdentityException::nameRequired();
        }

        return DB::transaction(fn () => Customer::create([
            'business_id' => $business->id,
            'name' => $name,
            'mobile' => $mobile,
            'status' => 'ACTIVE',
        ]));
    }

    private function createUser(
        Identity $identity,
        Business $business,
        ?string $mobile,
        Customer $customer,
    ): User {
        $mobile ??= $customer->mobile;

        return DB::transaction(function () use ($identity, $business, $mobile, $customer) {
            $user = User::create([
                'business_id' => $business->id,
                'name' => $customer->name,
                'mobile' => $mobile,
                'email' => $identity->email,
                'avatar' => $identity->avatar,
                'role' => Role::CUSTOMER,
                'status' => 'ACTIVE',
            ]);

            // Set explicitly rather than through the array above:
            // `email_verified_at` is not fillable, because it is a claim about
            // the person rather than something they supply. Mass-assigning it
            // would mean any caller could assert "this address is verified".
            if ($identity->emailVerified && $identity->email !== null) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            $this->link($user, $identity);

            return $user;
        });
    }

    private function resolveBusiness(?string $code): ?Business
    {
        $code = strtoupper(trim((string) $code));

        if ($code === '') {
            return null;
        }

        $business = Business::query()
            ->withoutGlobalScopes()
            ->where('slug', $code)
            ->where('status', 'ACTIVE')
            ->first();

        if ($business === null) {
            throw IdentityException::unknownBusiness();
        }

        return $business;
    }

    /**
     * A linked identity already belongs to one laundry. Naming a different
     * one is refused rather than quietly ignored.
     */
    private function assertSameBusiness(User $user, ?string $businessCode): void
    {
        $code = strtoupper(trim((string) $businessCode));

        if ($code === '' || $user->business_id === null) {
            return;
        }

        $business = Business::query()->withoutGlobalScopes()->find($user->business_id);

        if ($business !== null && strcasecmp((string) $business->slug, $code) !== 0) {
            throw IdentityException::alreadyRegisteredElsewhere($business->business_name);
        }
    }

    private function normaliseMobile(?string $mobile): ?string
    {
        if ($mobile === null || trim($mobile) === '') {
            return null;
        }

        return PhoneNumber::tryParse($mobile, (string) config('sms.default_country_code'))?->e164;
    }
}
