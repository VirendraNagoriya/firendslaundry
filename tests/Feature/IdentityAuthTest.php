<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Exceptions\IdentityException;
use App\Models\Business;
use App\Models\Customer;
use App\Models\OAuthAccount;
use App\Models\User;
use App\Services\Auth\IdentityService;
use App\Support\Identity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

/**
 * Sign-in: Google, and passwords.
 *
 * The properties worth protecting are not "does the button work". They are
 * the ones where a mistake hands somebody the wrong account:
 *
 *  - a Google account is matched on Google's permanent id, never on an email
 *    address, so it can never walk into a staff record an Admin typed an
 *    address into;
 *  - a staff record is *adopted*, so the role an Admin assigned survives
 *    somebody arriving with Google or with a password;
 *  - self-registration can only ever produce a CUSTOMER;
 *  - one Google account belongs to one laundry;
 *  - a deactivated account cannot sign in by either route.
 */
class IdentityAuthTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'signin.google.enabled' => true,
            'signin.google.client_id' => 'test-client-id',
            'signin.google.client_secret' => 'test-client-secret',
            'signin.password.enabled' => true,
            'signin.registration.open' => true,
            'signin.password.min_length' => 12,
        ]);
    }

    // -----------------------------------------------------------------
    // Password accounts
    // -----------------------------------------------------------------

    public function test_registering_creates_a_customer_of_the_named_laundry(): void
    {
        $business = Business::factory()->create(['slug' => 'ABC']);

        $response = $this->post('/register', [
            'name' => 'Asha Patil',
            'email' => 'asha@example.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'mobile' => '+919876500001',
            'business_code' => 'abc',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();

        $user = User::withoutGlobalScopes()->where('email', 'asha@example.com')->firstOrFail();

        $this->assertSame(Role::CUSTOMER, $user->role);
        $this->assertSame($business->id, $user->business_id);
        $this->assertTrue($user->usesPassword());
        $this->assertDatabaseHas('customers', [
            'business_id' => $business->id,
            'mobile' => '+919876500001',
            'name' => 'Asha Patil',
        ]);
    }

    /**
     * The role is set in code. A form field called `role` must not survive.
     */
    public function test_registering_cannot_be_used_to_award_a_role(): void
    {
        $business = Business::factory()->create(['slug' => 'ABC']);

        $this->post('/register', [
            'name' => 'Mallory',
            'email' => 'mallory@example.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'mobile' => '+919876500002',
            'business_code' => 'ABC',
            'role' => 'admin',
            'status' => 'ACTIVE',
            'business_id' => $business->id,
        ])->assertRedirect(route('dashboard'));

        $user = User::withoutGlobalScopes()->where('email', 'mallory@example.com')->firstOrFail();

        $this->assertSame(Role::CUSTOMER, $user->role);
    }

    public function test_registering_refuses_an_unknown_laundry_code(): void
    {
        $this->post('/register', [
            'name' => 'Asha Patil',
            'email' => 'asha@example.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'mobile' => '+919876500003',
            'business_code' => 'NOPE',
        ])->assertSessionHasErrors('business_code');

        $this->assertGuest();
    }

    public function test_registering_rejects_a_short_password(): void
    {
        Business::factory()->create(['slug' => 'ABC']);

        $this->post('/register', [
            'name' => 'Asha Patil',
            'email' => 'asha@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
            'mobile' => '+919876500004',
            'business_code' => 'ABC',
        ])->assertSessionHasErrors('password');

        $this->assertGuest();
    }

    public function test_self_registration_can_be_switched_off(): void
    {
        config(['signin.registration.open' => false]);

        Business::factory()->create(['slug' => 'ABC']);

        $this->get('/register')->assertForbidden();

        $this->post('/register', [
            'name' => 'Asha Patil',
            'email' => 'asha@example.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'mobile' => '+919876500005',
            'business_code' => 'ABC',
        ])->assertForbidden();
    }

    /**
     * An Admin created this row and chose the role. The person joining with a
     * password adopts the row; it must keep the role and not be downgraded.
     */
    public function test_a_staff_row_adopted_at_registration_keeps_its_role(): void
    {
        $business = Business::factory()->create(['slug' => 'ABC']);

        $branch = \App\Models\Branch::factory()->for($business)->create();

        User::factory()->admin($branch)->create([
            'business_id' => $business->id,
            'mobile' => '+919876500010',
            'email' => null,
        ]);

        $this->post('/register', [
            'name' => 'Ravi Owner',
            'email' => 'ravi@example.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'mobile' => '+919876500010',
            'business_code' => 'ABC',
        ])->assertRedirect(route('dashboard'));

        $user = User::withoutGlobalScopes()->where('email', 'ravi@example.com')->firstOrFail();

        $this->assertSame(Role::ADMIN, $user->role);
        $this->assertSame($business->id, $user->business_id);
    }

    public function test_password_sign_in_records_the_sign_in(): void
    {
        $user = $this->passwordUser();

        $this->post('/login', [
            'email' => 'staff@example.com',
            'password' => self::PASSWORD,
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    /**
     * A session id captured before sign-in must be worthless afterwards.
     */
    public function test_password_sign_in_rotates_the_session_id(): void
    {
        $this->passwordUser();

        $this->get('/login');

        $before = session()->getId();

        $this->post('/login', [
            'email' => 'staff@example.com',
            'password' => self::PASSWORD,
        ]);

        $this->assertNotSame($before, session()->getId());
    }

    /**
     * The two failures must be indistinguishable, or this form becomes a way
     * to find out which addresses hold accounts.
     */
    public function test_a_wrong_password_and_an_unknown_email_read_alike(): void
    {
        $this->passwordUser();

        $this->post('/login', [
            'email' => 'staff@example.com',
            'password' => 'not-the-password',
        ])->assertSessionHasErrors('email');

        $wrongPassword = session('errors')->first('email');

        $this->post('/login', [
            'email' => 'nobody@example.com',
            'password' => 'not-the-password',
        ])->assertSessionHasErrors('email');

        $unknownEmail = session('errors')->first('email');

        $this->assertSame($wrongPassword, $unknownEmail);
        $this->assertGuest();
    }

    /**
     * A Google-only account has a null password. Comparing against it must
     * read as a refused sign-in, not crash.
     */
    public function test_a_google_only_account_cannot_be_password_signed_in(): void
    {
        $user = User::factory()->customer()->create([
            'email' => 'googleonly@example.com',
            'mobile' => '+919876500020',
        ]);

        $this->assertFalse($user->usesPassword());

        $this->post('/login', [
            'email' => 'googleonly@example.com',
            'password' => self::PASSWORD,
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_deactivated_account_cannot_sign_in(): void
    {
        $user = $this->passwordUser();
        $user->forceFill(['status' => 'INACTIVE'])->save();

        $this->post('/login', [
            'email' => 'staff@example.com',
            'password' => self::PASSWORD,
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_password_sign_in_is_throttled(): void
    {
        config(['signin.throttle.login_attempts' => 2]);

        $this->passwordUser();

        foreach (range(1, 2) as $ignored) {
            $this->post('/login', [
                'email' => 'staff@example.com',
                'password' => 'wrong',
            ]);
        }

        $this->post('/login', [
            'email' => 'staff@example.com',
            'password' => self::PASSWORD,
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_signing_out_ends_the_session(): void
    {
        $this->passwordUser();

        $this->post('/login', [
            'email' => 'staff@example.com',
            'password' => self::PASSWORD,
        ]);

        $this->assertAuthenticated();

        $this->post('/logout')->assertRedirect(route('login'));

        $this->assertGuest();
    }

    // -----------------------------------------------------------------
    // Google
    // -----------------------------------------------------------------

    public function test_google_routes_are_absent_without_credentials(): void
    {
        config(['signin.google.client_id' => null, 'signin.google.client_secret' => null]);

        $this->get('/auth/google')->assertNotFound();
        $this->get('/auth/google/callback')->assertNotFound();
    }

    public function test_the_sign_in_page_offers_google_when_configured(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Continue with Google');
    }

    public function test_the_sign_in_page_hides_google_when_unconfigured(): void
    {
        config(['signin.google.client_id' => null, 'signin.google.client_secret' => null]);

        $this->get('/login')
            ->assertOk()
            ->assertDontSee('Continue with Google');
    }

    /**
     * The whole point of matching on provider id: an Admin typed this address
     * into a staff record, but nothing proves the Google account owns it. A
     * matching, Google-verified address is still not enough.
     */
    public function test_a_google_identity_cannot_claim_a_staff_account_by_email_alone(): void
    {
        $business = Business::factory()->create(['slug' => 'ABC']);

        User::factory()->admin($business)->create([
            'mobile' => '+919876500030',
            'email' => 'owner@example.com',
        ]);

        $this->fakeGoogle(sub: 'google-sub-new', email: 'owner@example.com', emailVerified: true);

        // Must not be signed in as the Admin. Should be asked which laundry.
        $this->get('/auth/google/callback')->assertRedirect(route('login.google.setup'));

        $this->assertGuest();
    }

    public function test_an_unverified_google_email_cannot_claim_an_account(): void
    {
        $business = Business::factory()->create(['slug' => 'ABC']);

        User::factory()->customer($business)->create([
            'mobile' => '+919876500031',
            'email' => 'person@example.com',
        ]);

        $this->fakeGoogle(sub: 'google-sub-unverified', email: 'person@example.com', emailVerified: false);

        $this->get('/auth/google/callback')->assertRedirect(route('login.google.setup'));

        $this->assertGuest();
    }

    public function test_an_unknown_google_identity_is_asked_which_laundry(): void
    {
        Business::factory()->create(['slug' => 'ABC']);

        $this->fakeGoogle(sub: 'google-sub-fresh', email: 'new@example.com', emailVerified: true);

        $this->get('/auth/google/callback')
            ->assertRedirect(route('login.google.setup'));

        $this->assertGuest();
    }

    public function test_google_setup_creates_a_customer(): void
    {
        $business = Business::factory()->create(['slug' => 'ABC']);

        $this->fakeGoogle(sub: 'google-sub-new-customer', email: 'new@example.com', emailVerified: true);

        $this->get('/auth/google/callback')->assertRedirect(route('login.google.setup'));

        $this->post('/auth/google/setup', [
            'business_code' => 'ABC',
            'mobile' => '+919876500040',
            'name' => 'Asha Patil',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();

        $user = User::withoutGlobalScopes()->where('email', 'new@example.com')->firstOrFail();

        $this->assertSame(Role::CUSTOMER, $user->role);
        $this->assertSame($business->id, $user->business_id);
        $this->assertNotNull($user->email_verified_at);

        $this->assertDatabaseHas('oauth_accounts', [
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_id' => 'google-sub-new-customer',
        ]);
    }

    /**
     * The staff record was made in advance by an Admin, who picked the role.
     * Signing in with Google must adopt it, not overwrite it.
     */
    public function test_google_setup_adopts_the_role_an_admin_assigned(): void
    {
        $business = Business::factory()->create(['slug' => 'ABC']);

        User::factory()->branchUser($business)->create([
            'mobile' => '+919876500050',
            'email' => null,
        ]);

        $this->fakeGoogle(sub: 'google-sub-staff', email: 'staff@example.com', emailVerified: true);

        $this->get('/auth/google/callback');

        $this->post('/auth/google/setup', [
            'business_code' => 'ABC',
            'mobile' => '+919876500050',
        ])->assertRedirect(route('dashboard'));

        $user = User::withoutGlobalScopes()->whereHas('oauthAccounts', function ($q) {
            $q->where('provider_id', 'google-sub-staff');
        })->firstOrFail();

        $this->assertSame(Role::BRANCH, $user->role);
        $this->assertSame($business->id, $user->business_id);
    }

    public function test_a_linked_identity_keeps_its_role_on_every_later_sign_in(): void
    {
        $business = Business::factory()->create(['slug' => 'ABC']);

        $user = User::factory()->pickupBoy($business)->create([
            'mobile' => '+919876500060',
        ]);

        OAuthAccount::create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_id' => 'google-sub-repeating',
        ]);

        $this->fakeGoogle(sub: 'google-sub-repeating', email: 'boy@example.com', emailVerified: true);

        // No laundry code on this second visit, because the link already says
        // which laundry this is.
        $this->get('/auth/google/callback')->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame(Role::PICKUP_BOY, $user->fresh()->role);
    }

    public function test_a_deactivated_account_cannot_sign_in_with_google(): void
    {
        $business = Business::factory()->create(['slug' => 'ABC']);

        $user = User::factory()->customer($business)->inactive()->create([
            'mobile' => '+919876500070',
        ]);

        OAuthAccount::create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_id' => 'google-sub-inactive',
        ]);

        $this->fakeGoogle(sub: 'google-sub-inactive', email: 'gone@example.com', emailVerified: true);

        $this->get('/auth/google/callback')->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_the_setup_page_needs_a_pending_identity(): void
    {
        $this->get('/auth/google/setup')->assertRedirect(route('login'));

        $this->post('/auth/google/setup', [
            'business_code' => 'ABC',
            'mobile' => '+919876500080',
        ])->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_cancelling_forgets_the_pending_identity(): void
    {
        Business::factory()->create(['slug' => 'ABC']);

        $this->fakeGoogle(sub: 'google-sub-cancel', email: 'cancel@example.com', emailVerified: true);

        $this->get('/auth/google/callback');

        $this->get('/auth/google/cancel')->assertRedirect(route('login'));

        // The abandoned attempt must not be resumable.
        $this->get('/auth/google/setup')->assertRedirect(route('login'));
    }

    /**
     * A person may be a customer of two laundries, so the provider identity
     * alone is not enough to decide the tenant. When they are already linked
     * to one laundry, naming another is refused rather than ignored.
     */
    public function test_one_google_account_cannot_be_used_for_two_laundries(): void
    {
        $abc = Business::factory()->create(['slug' => 'ABC']);
        $xyz = Business::factory()->create(['slug' => 'XYZ']);

        $abcUser = User::factory()->customer($abc)->create([
            'mobile' => '+919876500090',
        ]);

        OAuthAccount::create([
            'user_id' => $abcUser->id,
            'provider' => 'google',
            'provider_id' => 'google-sub-one-laundry',
        ]);

        $identity = Identity::fromGoogle(
            sub: 'google-sub-one-laundry',
            email: 'one@example.com',
            emailVerified: true,
            name: 'Asha Patil',
            avatar: null,
        );

        $this->expectException(IdentityException::class);
        $this->expectExceptionMessage('already registered');

        app(IdentityService::class)->resolveUser($identity, businessCode: 'XYZ');
    }

    /**
     * The same Google account signing in again without naming a laundry must
     * land in the same place, every time.
     */
    public function test_the_same_google_account_always_maps_to_the_same_profile(): void
    {
        $business = Business::factory()->create(['slug' => 'ABC']);

        $first = User::factory()->customer($business)->create([
            'mobile' => '+919876500100',
        ]);

        OAuthAccount::create([
            'user_id' => $first->id,
            'provider' => 'google',
            'provider_id' => 'google-sub-stable',
        ]);

        $identity = Identity::fromGoogle(
            sub: 'google-sub-stable',
            email: 'stable@example.com',
            emailVerified: true,
            name: 'Asha Patil',
            avatar: null,
        );

        $service = app(IdentityService::class);

        $again = $service->resolveUser($identity);

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, User::withoutGlobalScopes()->count());
    }

    /**
     * Linking must be idempotent. A repeated sign-in cannot create a second
     * row for the same provider account.
     */
    public function test_signing_in_twice_does_not_duplicate_the_link(): void
    {
        $business = Business::factory()->create(['slug' => 'ABC']);

        $this->fakeGoogle(sub: 'google-sub-twice', email: 'twice@example.com', emailVerified: true);

        $this->get('/auth/google/callback');
        $this->post('/auth/google/setup', [
            'business_code' => 'ABC',
            'mobile' => '+919876500110',
            'name' => 'Asha Patil',
        ]);

        $this->post('/logout');

        $this->get('/auth/google/callback')->assertRedirect(route('dashboard'));

        $this->assertSame(
            1,
            OAuthAccount::withoutGlobalScopes()->where('provider_id', 'google-sub-twice')->count(),
        );
    }

    /**
     * Nothing about a person can be created without a laundry that already
     * exists and is active.
     */
    public function test_a_suspended_laundry_cannot_be_joined(): void
    {
        Business::factory()->create(['slug' => 'ZZZ', 'status' => 'SUSPENDED']);

        $this->fakeGoogle(sub: 'google-sub-suspended', email: 'susp@example.com', emailVerified: true);

        $this->get('/auth/google/callback');

        $this->post('/auth/google/setup', [
            'business_code' => 'ZZZ',
            'mobile' => '+919876500120',
            'name' => 'Asha Patil',
        ])->assertSessionHasErrors('business_code');

        $this->assertGuest();
    }

    /**
     * If the laundry already has this mobile on file, reuse that customer
     * rather than making a duplicate.
     */
    public function test_an_existing_customer_record_is_reused(): void
    {
        $business = Business::factory()->create(['slug' => 'ABC']);

        Customer::factory()->forBusiness($business)->create([
            'mobile' => '+919876500130',
            'name' => 'Existing Name',
        ]);

        $this->fakeGoogle(sub: 'google-sub-existing', email: 'known@example.com', emailVerified: true);

        $this->get('/auth/google/callback');

        $this->post('/auth/google/setup', [
            'business_code' => 'ABC',
            'mobile' => '+919876500130',
        ])->assertRedirect(route('dashboard'));

        $this->assertSame(
            1,
            Customer::withoutGlobalScopes()->where('business_id', $business->id)->count(),
        );

        $this->assertSame(
            'Existing Name',
            User::withoutGlobalScopes()->firstOrFail()->name,
        );
    }

    /**
     * Adopting a staff row must not move an address that already belongs to
     * someone else onto it. The unique index would reject the write either
     * way; what matters is that it is reported as a refusal rather than
     * surfacing as a database error.
     */
    public function test_adopting_a_staff_row_refuses_an_address_that_belongs_to_someone_else(): void
    {
        $business = Business::factory()->create(['slug' => 'ABC']);

        User::factory()->customer($business)->create([
            'email' => 'taken@example.com',
            'mobile' => '+919876500200',
        ]);

        User::factory()->admin($business)->create([
            'email' => null,
            'mobile' => '+919876500201',
        ]);

        $this->post('/register', [
            'name' => 'Impostor',
            'email' => 'taken@example.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'mobile' => '+919876500201',
            'business_code' => 'ABC',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();

        // The staff row must not have been quietly reassigned either.
        $this->assertSame(
            Role::ADMIN,
            User::withoutGlobalScopes()->where('mobile', '+919876500201')->firstOrFail()->role,
        );
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function passwordUser(): User
    {
        $business = Business::factory()->create(['slug' => 'ABC']);

        $user = User::factory()->customer($business)->create([
            'email' => 'staff@example.com',
            'mobile' => '+919876500001',
        ]);

        $user->forceFill(['password' => self::PASSWORD])->save();

        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));

        return $user->fresh();
    }

    /**
     * Stand in for Google having verified the token. Only the claims this app
     * actually reads are supplied.
     */
    private function fakeGoogle(
        string $sub,
        ?string $email,
        bool $emailVerified,
        ?string $name = 'Asha Patil',
    ): void {
        $socialiteUser = new SocialiteUser;
        $socialiteUser->setRaw([
            'id' => $sub,
            'name' => $name,
            'email' => $email,
            'email_verified' => $emailVerified,
        ])->map([
            'id' => $sub,
            'name' => $name,
            'email' => $email,
            'email_verified' => $emailVerified,
        ]);

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($socialiteUser);
        $provider->shouldReceive('scopes')->andReturnSelf();
        $provider->shouldReceive('redirect')->andReturn(
            redirect('https://accounts.google.com/o/oauth2/v2/auth')
        );

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }
}