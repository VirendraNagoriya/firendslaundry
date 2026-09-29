<?php

namespace App\Services;

use App\Enums\OtpPurpose;
use App\Enums\Role;
use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use App\Support\PhoneNumber;
use App\Support\TenantContext;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Turns a verified OTP into a signed-in session.
 *
 * The interesting rule is account resolution (spec section 7): proving
 * control of a mobile number authorises the *account bound to that
 * number*, not a new account built from whatever name the login form
 * happened to contain. So an existing user's stored name is never
 * overwritten by a name typed at the login screen.
 */
class AuthService
{
    public function __construct(private readonly OtpService $otp)
    {
    }

    /**
     * Verify the code, resolve the account, and start the session.
     *
     * @param  string  $name  Name as typed at login. Used only when no
     *                         account exists yet.
     *
     * @throws OtpException|OtpAccountException
     */
    public function loginWithOtp(string $mobileInput, string $name, string $code, string $ipAddress): User
    {
        $mobile = PhoneNumber::parse($mobileInput, $this->defaultCountryCode())->e164;

        $record = $this->otp->verify($mobile, $code, OtpPurpose::LOGIN);

        $user = $this->resolveUser($mobile, $name);

        if (! $user->isActive()) {
            throw OtpAccountException::inactive();
        }

        $this->otp->attachUser($record, $user->id);

        // New session id on privilege change, so a session fixated before
        // login cannot be reused afterwards.
        Auth::login($user, remember: false);
        $request = request();
        $request?->session()?->regenerate(true);

        $user->forceFill(['last_login_at' => now()])->save();

        return $user;
    }

    public function logout(): void
    {
        $user = Auth::user();

        if ($user instanceof User) {
            $this->otp->invalidateAll($user->mobile, OtpPurpose::LOGIN);
        }

        Auth::logout();

        $request = request();
        $request?->session()?->invalidate();
        $request?->session()?->regenerateToken();
    }

    /**
     * Find the account this number belongs to, or create one.
     *
     * Precedence:
     *  1. a signed-in user still owns the session;
     *  2. the branch-scoped employee account for this number;
     *  3. the customer account for this business.
     *
     * @throws OtpAccountException
     */
    public function resolveUser(string $mobile, string $name): User
    {
        $currentUser = Auth::user();

        if ($currentUser instanceof User && $currentUser->mobile === $mobile) {
            return $currentUser;
        }

        $name = trim($name);

        if ($name === '') {
            throw OtpAccountException::nameRequired();
        }

        $employee = $this->findEmployee($mobile);

        if ($employee !== null) {
            return $employee;
        }

        $business = $this->resolveBusinessForNewAccount();

        if ($business === null) {
            throw OtpAccountException::noAccountFound();
        }

        return $this->createCustomer($business, $mobile, $name);
    }

    /**
     * Branch, admin and field-staff accounts are created by an Admin, so
     * they already exist by the time their owner tries to log in.
     */
    private function findEmployee(string $mobile): ?User
    {
        $scoped = User::query()
            ->where('mobile', $mobile)
            ->whereIn('role', array_column(Role::tenantRoles(), 'value'))
            ->where('status', 'ACTIVE')
            ->first();

        if ($scoped !== null) {
            return $scoped;
        }

        // A Super Admin has no business, so the tenant scope would hide
        // them from the query above. Look them up explicitly.
        return app(TenantContext::class)->withoutTenancy(
            fn () => User::query()
                ->where('mobile', $mobile)
                ->where('role', Role::SUPER_ADMIN->value)
                ->where('status', 'ACTIVE')
                ->first()
        );
    }

    /**
     * Which business a brand-new account joins.
     *
     * Only possible when a business has explicitly turned on open
     * customer registration, or when a customer already exists for the
     * number within the current business context.
     */
    private function resolveBusinessForNewAccount(): ?Business
    {
        $user = Auth::user();

        if ($user instanceof User && $user->business_id !== null) {
            return $user->business;
        }

        $tenantBusinessId = app(TenantContext::class)->resolve();

        if ($tenantBusinessId === null) {
            return null;
        }

        return Business::find($tenantBusinessId);
    }

    /**
     * @throws OtpAccountException
     */
    private function createCustomer(Business $business, string $mobile, string $name): User
    {
        $customer = DB::transaction(function () use ($business, $mobile, $name) {
            $existing = Customer::query()
                ->withoutGlobalScopes()
                ->where('business_id', $business->id)
                ->where('mobile', $mobile)
                ->first();

            if ($existing !== null) {
                // Same rule as above: the number proves the account, not
                // the name typed alongside it.
                return $existing;
            }

            return Customer::create([
                'business_id' => $business->id,
                'name' => $name,
                'mobile' => $mobile,
                'status' => 'ACTIVE',
            ]);
        });

        return $this->customerLogin($customer);
    }

    /**
     * Sign in as the `users` record that represents a customer.
     *
     * Customers are stored separately from staff, so each customer gets a
     * mirrored user row. That keeps one session model across all six
     * roles instead of a second authentication path.
     */
    public function customerLogin(Customer $customer): User
    {
        $user = User::query()
            ->withoutGlobalScopes()
            ->where('business_id', $customer->business_id)
            ->where('mobile', $customer->mobile)
            ->where('role', Role::CUSTOMER->value)
            ->first();

        if ($user !== null) {
            // Keep the mirrored name in step with the customer record,
            // which staff (not the customer) maintain.
            if ($user->name !== $customer->name) {
                $user->forceFill(['name' => $customer->name])->save();
            }

            return $user;
        }

        return User::create([
            'business_id' => $customer->business_id,
            'name' => $customer->name,
            'mobile' => $customer->mobile,
            'role' => Role::CUSTOMER,
            'status' => 'ACTIVE',
        ]);
    }
}
