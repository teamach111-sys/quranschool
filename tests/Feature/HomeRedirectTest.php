<?php

namespace Tests\Feature;

use Illuminate\Auth\GenericUser;
use Tests\TestCase;

class HomeRedirectTest extends TestCase
{
    public function test_guest_can_access_the_home_page(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertViewIs('welcome');
    }

    public function test_authenticated_user_visiting_home_is_sent_to_dashboard(): void
    {
        $this->actingAs(new GenericUser([
            'id' => 1,
            'role' => 'admin',
        ]));

        $this->get('/')
            ->assertRedirect(route('dashboard'));
    }

    public function test_guest_can_access_the_login_page(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertViewIs('auth');
    }

    public function test_authenticated_user_visiting_login_is_sent_to_dashboard(): void
    {
        $this->actingAs(new GenericUser([
            'id' => 1,
            'role' => 'admin',
        ]));

        $this->get('/login')
            ->assertRedirect(route('dashboard'));
    }
}
