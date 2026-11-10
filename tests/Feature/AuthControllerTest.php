<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('role_key');
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('role_id');
            $table->string('full_name');
            $table->string('email')->unique();
            $table->string('password_hash');
            $table->string('remember_token')->nullable();
            $table->boolean('is_active')->default(true);
        });
    }

    public function test_successful_login_with_remember_me_stores_token_and_issues_cookie(): void
    {
        $this->createUser();

        $response = $this->postJson('/login', [
            'email' => 'teacher@example.test',
            'password' => 'correct-password',
            'remember' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertCookieNotExpired(Auth::guard()->getRecallerName());
        $this->assertNotEmpty(DB::table('users')->where('id', 1)->value('remember_token'));
    }

    public function test_successful_login_without_remember_me_does_not_store_token_or_issue_cookie(): void
    {
        $this->createUser();

        $this->postJson('/login', [
            'email' => 'teacher@example.test',
            'password' => 'correct-password',
            'remember' => false,
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertCookieMissing(Auth::guard()->getRecallerName());
        $this->assertDatabaseHas('users', [
            'id' => 1,
            'remember_token' => null,
        ]);
    }

    public function test_invalid_credentials_return_localizable_error_code(): void
    {
        $this->createUser();

        $this->postJson('/login', [
            'email' => 'teacher@example.test',
            'password' => 'incorrect-password',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_credentials');
    }

    public function test_inactive_account_returns_localizable_error_code(): void
    {
        $this->createUser(isActive: false);

        $this->postJson('/login', [
            'email' => 'teacher@example.test',
            'password' => 'correct-password',
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'inactive_account');
    }

    public function test_unapproved_role_returns_localizable_error_code(): void
    {
        $this->createUser(role: 'student');

        $this->postJson('/login', [
            'email' => 'teacher@example.test',
            'password' => 'correct-password',
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'role_not_allowed');
    }

    private function createUser(string $role = 'teacher', bool $isActive = true): void
    {
        DB::table('roles')->insert([
            'id' => 1,
            'role_key' => $role,
        ]);
        DB::table('users')->insert([
            'id' => 1,
            'school_id' => 1,
            'role_id' => 1,
            'full_name' => 'Test User',
            'email' => 'teacher@example.test',
            'password_hash' => Hash::make('correct-password'),
            'is_active' => $isActive,
        ]);
    }
}
