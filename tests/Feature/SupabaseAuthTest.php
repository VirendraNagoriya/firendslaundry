<?php

use App\Enums\Role;
use App\Exceptions\SupabaseAuthException;
use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use App\Services\Auth\SupabaseAuthClient;
use App\Services\Auth\SupabaseAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Sign-in through Supabase Auth.
 *
 * The properties worth protecting are not "does the code work" (Supabase
 * owns that) but:
 *
 *  - the service-role key never leaves the server;
 *  - a Supabase account is mapped to exactly one profile, and a profile's
 *    role is never taken from anything the person typed;
 *  - a phone number on its own cannot choose a tenant, because the same
 *    person can be a customer of two laundries;
 *  - a staff account created by an Admin keeps the role that was assigned,
 *    even when the same person signs in through a brand-new Supabase
 *    account.
 */
class SupabaseAuthTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '+919876543210';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'supabase.enabled' => true,
            'supabase.url' => 'https://project.supabase.co',
            'supabase.anon_key' => 'anon-key-abc',
            'supabase.service_role_key' => 'service-role-xyz',
            'supabase.country_code' => '91',
        ]);
    }

    // -----------------------------------------------------------------
    // Keys stay on the server
    // -----------------------------------------------------------------

    public function test_the_service_role_key_never_leaves_the_server(): void
    {
        Http::fake([
            'project.supabase.co/auth/v1/otp' => Http::response(['message_id' => 'm1'], 200),
        ]);

        app(SupabaseAuthService::class)->sendLoginCode(self::PHONE);

        // Signing a person in only needs the anon key. The service-role key
        // bypasses row-level security, so it must not be in play here.
        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer anon-key-abc')
                && ! str_contains($request->body(), 'service-role-xyz');
        });

        Http::assertNotSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer service-role-xyz')
                || str_contains($request->body(), 'service-role-xyz');
        });
    }

    public function test_no_response_ever_contains_a_key_or_a_code(): void
    {
        Http::fake([
            'project.supabase.co/auth/v1/otp' => Http::response(['message_id' => 'm1'], 200),
        ]);

        $this->postJson('/api/auth/send-code', ['mobile' => self::PHONE])
            ->assertOk()
            ->assertJsonMissing(['service-role-xyz']);

        $body = (string) $this->postJson('/api/auth/send-code', ['mobile' => self::PHONE])->getContent();

        $this->assertStringNotContainsString('service-role-xyz', $body);
        $this->assertStringNotContainsString('anon-key-abc', $body);
        $this->assertStringNotContainsString('access_token', $body);
    }

    // -----------------------------------------------------------------
    // Sign-in works
    // -----------------------------------------------------------------

    public function test_an_admin_signs_in_with_their_assigned_role(): void
    {
        $business = $this->business('ABC Laundry', 'ABC');
        $admin = User::factory()->admin($business)->create(['mobile' => self::PHONE]);

        $this->fakeAcceptedCode($userId = '11111111-1111-4111-8111-111111111111');

        $user = app(SupabaseAuthService::class)->completeLogin(self::PHONE, '123456', 'ABC');

        $this->assertSame($admin->id, $user->id);
        $this->assertSame(Role::ADMIN, $user->role);
        $this->assertSame($userId, $user->supabase_user_id);
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($admin->fresh()->last_login_at);
    }

    public function test_a_new_customer_of_a_known_laundry_gets_a_profile(): void
    {
        $this->business('ABC Laundry', 'ABC');

        $this->fakeAcceptedCode('22222222-2222-4222-8222-222222222222');

        $user = app(SupabaseAuthService::class)
            ->completeLogin(self::PHONE, '123456', 'abc', 'Asha Patil');

        $this->assertSame(Role::CUSTOMER, $user->role);
        $this->assertNotNull($user->business_id);

        $customer = Customer::withoutGlobalScopes()
            ->where('business_id', $user->business_id)
            ->where('mobile', self::PHONE)
            ->firstOrFail();

        $this->assertSame('Asha Patil', $customer->name);
    }

    public function test_a_known_customer_number_never_overwrites_the_stored_name(): void
    {
        $business = $this->business('ABC Laundry', 'ABC');

        $customer = Customer::factory()->forBusiness($business)->create([
            'mobile' => self::PHONE,
            'name' => 'Asha Patil',
        ]);

        $this->fakeAcceptedCode('33333333-3333-4333-8333-333333333333');

        $user = app(SupabaseAuthService::class)
            ->completeLogin(self::PHONE, '123456', 'ABC', 'Someone Else Entirely');

        $this->assertSame('Asha Patil', $customer->fresh()->name);
        $this->assertSame('Asha Patil', $user->name);
    }

    // -----------------------------------------------------------------
    // Tenant resolution
    // -----------------------------------------------------------------

    public function test_a_phone_number_alone_cannot_choose_a_tenant(): void
    {
        $this->business('ABC Laundry', 'ABC');
        $this->fakeAcceptedCode('44444444-4444-4444-8444-444444444444');

        $this->expectException(SupabaseAuthException::class);
        $this->expectExceptionMessageMatches('/short code/i');

        app(SupabaseAuthService::class)->completeLogin(self::PHONE, '123456', null, 'Asha');
    }

    public function test_the_same_number_can_belong_to_two_different_laundries(): void
    {
        $abc = $this->business('ABC Laundry', 'ABC');
        $xyz = $this->business('XYZ Laundry', 'XYZ');

        // The same human is a customer of both.
        Customer::factory()->forBusiness($abc)->create(['mobile' => self::PHONE, 'name' => 'Asha Patil']);
        Customer::factory()->forBusiness($xyz)->create(['mobile' => self::PHONE, 'name' => 'Asha Patil']);

        // Two separate Supabase accounts: one per laundry. A single fake
        // that hands back a different account each time it is called.
        $accounts = [
            '55555555-5555-4555-8555-555555555555',
            '66666666-6666-4666-8666-666666666666',
        ];

        // `use (&$accounts)`, not `fn ()`: an arrow function captures by
        // value, so every call would get the same first account back.
        Http::fake(function () use (&$accounts) {
            return Http::response([
                'access_token' => 'tok',
                'user' => [
                    'id' => array_shift($accounts),
                    'phone' => self::PHONE,
                ],
            ], 200);
        });

        $toAbc = app(SupabaseAuthService::class)->completeLogin(self::PHONE, '123456', 'ABC');
        $this->assertSame($abc->id, $toAbc->business_id);

        // Signing out and back in against the other laundry lands in the
        // other tenant, with a separate profile.
        app(SupabaseAuthService::class)->logout();

        $toXyz = app(SupabaseAuthService::class)->completeLogin(self::PHONE, '123456', 'XYZ');

        $this->assertSame($xyz->id, $toXyz->business_id);
        $this->assertNotSame($toAbc->id, $toXyz->id);
    }

    public function test_an_unknown_laundry_code_does_not_sign_anybody_in(): void
    {
        $this->business('ABC Laundry', 'ABC');
        $this->fakeAcceptedCode('77777777-7777-4777-8777-777777777777');

        $this->expectException(SupabaseAuthException::class);
        $this->expectExceptionMessageMatches('/short code/i');

        app(SupabaseAuthService::class)->completeLogin(self::PHONE, '123456', 'NOPE', 'Asha');
    }

    public function test_a_new_customer_needs_a_name(): void
    {
        $this->business('ABC Laundry', 'ABC');
        $this->fakeAcceptedCode('88888888-8888-4888-8888-888888888888');

        $this->expectException(SupabaseAuthException::class);
        $this->expectExceptionMessageMatches('/name/i');

        app(SupabaseAuthService::class)->completeLogin(self::PHONE, '123456', 'ABC', '  ');
    }

    // -----------------------------------------------------------------
    // Role is never taken from the request
    // -----------------------------------------------------------------

    public function test_a_customer_cannot_promote_themselves_by_asking_nicely(): void
    {
        $business = $this->business('ABC Laundry', 'ABC');
        User::factory()->admin($business)->create(['mobile' => self::PHONE]);

        $this->fakeAcceptedCode('99999999-9999-4999-8999-999999999999');

        $user = app(SupabaseAuthService::class)->completeLogin(
            self::PHONE,
            '123456',
            'ABC',
            'Asha',
        );

        // Still the Admin, because that is the row the Admin created.
        $this->assertSame(Role::ADMIN, $user->role);
        $this->assertSame(1, User::withoutGlobalScopes()->where('business_id', $business->id)->count());
    }

    public function test_a_deactivated_account_cannot_sign_in(): void
    {
        $business = $this->business('ABC Laundry', 'ABC');
        User::factory()->admin($business)->inactive()->create(['mobile' => self::PHONE]);

        $this->fakeAcceptedCode('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');

        $this->expectException(SupabaseAuthException::class);
        $this->expectExceptionMessageMatches('/deactivated/i');

        app(SupabaseAuthService::class)->completeLogin(self::PHONE, '123456', 'ABC');
    }

    public function test_the_same_supabase_account_always_maps_to_the_same_profile(): void
    {
        $business = $this->business('ABC Laundry', 'ABC');
        $user = User::factory()->admin($business)->create([
            'mobile' => self::PHONE,
            'supabase_user_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        ]);

        $this->fakeAcceptedCode('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb');

        // Signed in without a laundry code at all, because the Supabase
        // account is already bound to a profile.
        $again = app(SupabaseAuthService::class)->completeLogin(self::PHONE, '123456');

        $this->assertSame($user->id, $again->id);
    }

    // -----------------------------------------------------------------
    // Configuration and provider failures
    // -----------------------------------------------------------------

    public function test_sign_in_refuses_to_run_when_supabase_is_not_configured(): void
    {
        config(['supabase.enabled' => false]);

        $this->expectException(SupabaseAuthException::class);
        $this->expectExceptionMessageMatches('/not configured/i');

        app(SupabaseAuthService::class)->sendLoginCode(self::PHONE);
    }

    public function test_the_login_page_explains_a_missing_configuration_instead_of_failing(): void
    {
        config(['supabase.enabled' => false]);

        $this->get('/login')
            ->assertOk()
            ->assertSee('Sign-in is not configured yet', escape: false);
    }

    public function test_a_provider_error_is_reported_without_leaking_provider_detail(): void
    {
        Http::fake([
            'project.supabase.co/auth/v1/otp' => Http::response([
                'error_code' => 'over_request_rate_limit',
                'msg' => 'Too many requests, secret-provider-detail',
            ], 429),
        ]);

        try {
            app(SupabaseAuthService::class)->sendLoginCode(self::PHONE);
            $this->fail('The rate limit was not reported.');
        } catch (SupabaseAuthException $e) {
            $this->assertStringContainsString('Too many codes', $e->getMessage());
            $this->assertStringNotContainsString('secret-provider-detail', $e->getMessage());
        }
    }

    public function test_an_expired_code_is_reported_as_such(): void
    {
        Http::fake([
            'project.supabase.co/auth/v1/verify' => Http::response([
                'error_code' => 'otp_expired',
                'msg' => 'Token has expired',
            ], 403),
        ]);

        $this->business('ABC Laundry', 'ABC');

        try {
            app(SupabaseAuthService::class)->completeLogin(self::PHONE, '000000', 'ABC');
            $this->fail('An expired code was accepted.');
        } catch (SupabaseAuthException $e) {
            $this->assertStringContainsString('expired', $e->getMessage());
        }

        $this->assertGuest();
    }

    // -----------------------------------------------------------------
    // Number normalisation
    // -----------------------------------------------------------------

    public function test_numbers_are_sent_to_supabase_in_e164(): void
    {
        $client = new SupabaseAuthClient('https://p.supabase.co', 'anon', 'service');

        $this->assertSame('+919876543210', $client->toE164('9876543210'));
        $this->assertSame('+919876543210', $client->toE164('09876543210'));
        $this->assertSame('+919876543210', $client->toE164('919876543210'));
        $this->assertSame('+919876543210', $client->toE164('+91 98765 43210'));
        $this->assertSame('+447700900123', $client->toE164('+44 7700 900123'));
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function business(string $name, string $slug): Business
    {
        return Business::factory()->create([
            'business_name' => $name,
            'slug' => $slug,
        ]);
    }

    /**
     * Pretend Supabase accepted the code and returned this user id.
     */
    private function fakeAcceptedCode(string $userId): void
    {
        Http::fake([
            'project.supabase.co/auth/v1/verify' => Http::response([
                'access_token' => 'tok',
                'refresh_token' => 'ref',
                'user' => [
                    'id' => $userId,
                    'phone' => self::PHONE,
                ],
            ], 200),
        ]);
    }
}
