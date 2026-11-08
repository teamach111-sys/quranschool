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

    public function test_dashboard_title_uses_the_configured_application_name(): void
    {
        config(['app.name' => 'Association Imam Muslim']);
        $this->actingAs(new GenericUser([
            'id' => 1,
            'name' => 'Test Admin',
            'email' => 'admin@example.test',
            'role' => 'admin',
        ]));

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee(
                '<title lang="fr" data-title-fr="Tableau de bord - Association Imam Muslim" data-title-ar="لوحة التحكم - جمعية الامام مسلم">Tableau de bord - Association Imam Muslim</title>',
                false,
            );
    }

    public function test_guest_can_access_the_login_page(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertViewIs('auth')
            ->assertSee(
                '<title lang="fr" data-title-fr="Connexion - Association Imam Muslim" data-title-ar="تسجيل الدخول - جمعية الامام مسلم">Connexion - Association Imam Muslim</title>',
                false,
            );
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
