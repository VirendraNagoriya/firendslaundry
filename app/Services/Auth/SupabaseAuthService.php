<?php

namespace App\Services\Auth;

use App\Enums\Role;
use App\Exceptions\SupabaseAuthException;
use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Turns a verified Supabase session into a signed-in user of this app.
 *
 * Supabase answers "is this person who they say they are?". This class
 * answers the second, separate question: "which laundry are they signing
 * in to, and what may they do there?". The answer is deliberately never
 * taken from anything the person typed at the login screen other than the
 * laundry's own short code, and the role always comes from a row that an
 * Admin created in advance.
 *
 * A phone number on its own cannot answer it: the same person can be a
 * customer of two laundries, which is why `users.mobile` is not unique.
 * The short code on the business row is what disambiguates.
 */
class SupabaseAuthService
{
    public function __construct(private readonly SupabaseAuthClient $supabase)
    {
    }

    public function isConfigured(): bool
    {
        return (bool) config('supabase.enabled') && $this->supabase->isConfigured();
    }

    /**
     * Send a one-time code through Supabase.
     *
     * @throws SupabaseAuthException
     */
    public function sendLoginCode(string $identifier): void
    {
        $this->assertConfigured();

        $this->supabase->sendCode(
            $identifier,
            (string) config('supabase.country_code'),
        );
    }

    /**
     * Exchange the code for a session and sign the matching profile in.
     *
     * @param  string|null  $businessCode  Short code of the laundry, needed
     *                                     the first time a person signs in.
     * @param  string|null  $name  Name as typed. Used only when a customer
     *                             record has to be created.
     *
     * @throws SupabaseAuthException
     */
    public function completeLogin(
        string $identifier,
        string $code,
        ?string $businessCode = null,
        ?string $name = null,
    ): User {
        $this->assertConfigured();

        $session = $this->supabase->verifyCode(
            $identifier,
            $code,
            (string) config('supabase.country_code'),
        );

        $user = $this->resolveUser(
            supabaseUserId: $session['user_id'],
            mobile: $this->mobileFrom($session['phone'], $identifier),
            email: $session['email'],
            businessCode: $businessCode,
            name: $name,
        );

        if (! $user->isActive()) {
            throw new SupabaseAuthException(
                'This account has been deactivated. Please contact your laundry.'
            );
        }

        // A fresh session id on a privilege change, so a session id
        // captured before sign-in cannot be replayed afterwards. There is
        // no session store outside an HTTP request, so this is skipped in
        // a console or queue context.
        Auth::login($user, remember: false);

        if (request()->hasSession()) {
            request()->session()->regenerate(true);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return $user;
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
     * Find the profile that owns this Supabase account, creating one only
     * when the person is a genuine new customer of a known laundry.
     *
     * @throws SupabaseAuthException
     */
    private function resolveUser(
        string $supabaseUserId,
        ?string $mobile,
        ?string $email,
        ?string $businessCode,
        ?string $name,
    ): User {
        $existing = $this->findBySupabaseId($supabaseUserId);

        if ($existing !== null) {
            return $existing;
        }

        $business = $this->resolveBusiness($businessCode);

        if ($business === null) {
            throw new SupabaseAuthException(
                'We could not match this number to a laundry. Enter the short code printed on your bill or collection slip.',
                'business_required',
            );
        }

        // Staff accounts are created by an Admin, so a matching employee
        // row is adopted rather than replaced: the role stays as assigned.
        $employee = $this->findEmployee($business, $mobile);

        if ($employee !== null) {
            $employee->forceFill(['supabase_user_id' => $supabaseUserId])->save();

            return $employee;
        }

        $customer = $this->resolveCustomer($business, $mobile, $name);
        $mirror = $this->findCustomerUser($business, $mobile);

        if ($mirror !== null) {
            $mirror->forceFill(['supabase_user_id' => $supabaseUserId])->save();

            return $mirror;
        }

        return User::create([
            'business_id' => $business->id,
            'supabase_user_id' => $supabaseUserId,
            'name' => $customer->name,
            'mobile' => $customer->mobile,
            'email' => $email,
            'role' => Role::CUSTOMER,
            'status' => 'ACTIVE',
        ]);
    }

    private function findBySupabaseId(string $id): ?User
    {
        return User::query()
            ->withoutGlobalScopes()
            ->where('supabase_user_id', $id)
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
     * Customers are staff-created records. A new one is made here only when
     * somebody signs in for the first time with a laundry they name
     * themselves, and the number was not already known.
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
            throw new SupabaseAuthException(
                'This laundry accepts mobile numbers only at sign-in.',
                'mobile_required',
            );
        }

        $name = trim((string) $name);

        if ($name === '') {
            throw new SupabaseAuthException(
                'Please enter your name so the laundry knows who the order is for.',
                'name_required',
            );
        }

        return DB::transaction(fn () => Customer::create([
            'business_id' => $business->id,
            'name' => $name,
            'mobile' => $mobile,
            'status' => 'ACTIVE',
        ]));
    }

    private function resolveBusiness(?string $code): ?Business
    {
        $code = strtoupper(trim((string) $code));

        if ($code === '') {
            return null;
        }

        return Business::query()
            ->withoutGlobalScopes()
            ->where('slug', $code)
            ->where('status', 'ACTIVE')
            ->first();
    }

    private function mobileFrom(?string $fromSupabase, string $original): ?string
    {
        if (filled($fromSupabase)) {
            return $fromSupabase;
        }

        return filter_var($original, FILTER_VALIDATE_EMAIL) ? null : $original;
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new SupabaseAuthException(
                'Sign-in is not configured on this server yet. Set SUPABASE_URL, SUPABASE_ANON_KEY and SUPABASE_AUTH_ENABLED=true in .env.'
            );
        }
    }
}
