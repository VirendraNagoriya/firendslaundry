<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The root URL has one job: send a visitor to the right place.
 */
class RootRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_a_signed_in_user_is_sent_to_their_dashboard(): void
    {
        $this->actingAs(\App\Models\User::factory()->admin()->create())
            ->get('/')
            ->assertRedirect(route('dashboard'));
    }
}
