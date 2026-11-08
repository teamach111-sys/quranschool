<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PasswordResetTest extends TestCase
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
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('password_recovery_keys', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->primary();
            $table->string('key_hash');
            $table->timestamp('created_at');
        });

        DB::table('roles')->insert(['id' => 1, 'role_key' => 'teacher']);
        DB::table('users')->insert([
            'id' => 1,
            'school_id' => 1,
            'role_id' => 1,
            'full_name' => 'Test Teacher',
            'email' => 'teacher@example.test',
            'password_hash' => Hash::make('original-password-2026'),
            'remember_token' => 'old-remember-token',
        ]);
    }

    public function test_forgot_password_form_is_available_to_guests(): void
    {
        $this->get('/forgot-password')
            ->assertOk()
            ->assertViewIs('auth')
            ->assertViewHas('page', 'forgot-password');
    }

    public function test_reset_with_a_valid_recovery_key_changes_password_and_consumes_key(): void
    {
        $key = strtoupper(bin2hex(random_bytes(32)));
        DB::table('password_recovery_keys')->insert([
            'user_id' => 1,
            'key_hash' => Hash::make($key),
            'created_at' => now(),
        ]);

        $this->postJson('/forgot-password', [
            'email' => 'teacher@example.test',
            'recovery_key' => $key,
            'password' => 'new-strong-password-2026',
            'password_confirmation' => 'new-strong-password-2026',
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $user = DB::table('users')->where('id', 1)->first();
        $this->assertTrue(Hash::check('new-strong-password-2026', $user->password_hash));
        $this->assertNotSame('old-remember-token', $user->remember_token);
        $this->assertDatabaseMissing('password_recovery_keys', ['user_id' => 1]);

        $this->postJson('/forgot-password', [
            'email' => 'teacher@example.test',
            'recovery_key' => $key,
            'password' => 'another-strong-password-2026',
            'password_confirmation' => 'another-strong-password-2026',
        ])->assertUnprocessable();
    }

    public function test_invalid_key_does_not_change_password_or_consume_the_valid_key(): void
    {
        $key = strtoupper(bin2hex(random_bytes(32)));
        DB::table('password_recovery_keys')->insert([
            'user_id' => 1,
            'key_hash' => Hash::make($key),
            'created_at' => now(),
        ]);
        $originalHash = DB::table('users')->where('id', 1)->value('password_hash');

        $this->postJson('/forgot-password', [
            'email' => 'teacher@example.test',
            'recovery_key' => str_repeat('0', 64),
            'password' => 'new-strong-password-2026',
            'password_confirmation' => 'new-strong-password-2026',
        ])->assertUnprocessable();

        $this->assertSame($originalHash, DB::table('users')->where('id', 1)->value('password_hash'));
        $this->assertDatabaseHas('password_recovery_keys', ['user_id' => 1]);
    }

    public function test_reset_rejects_short_passwords_and_malformed_recovery_keys(): void
    {
        $this->postJson('/forgot-password', [
            'email' => 'teacher@example.test',
            'recovery_key' => 'invalid',
            'password' => 'too-short',
            'password_confirmation' => 'too-short',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['recovery_key', 'password'])
            ->assertJsonPath(
                'errors.recovery_key.0',
                'Le format de la clé de récupération est invalide. Vérifiez la clé téléchargée.',
            );
    }

    public function test_reset_does_not_disclose_unknown_emails_or_inactive_accounts(): void
    {
        $this->postJson('/forgot-password', [
            'email' => 'missing@example.test',
            'recovery_key' => str_repeat('A', 64),
            'password' => 'new-strong-password-2026',
            'password_confirmation' => 'new-strong-password-2026',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Adresse e-mail ou clé de récupération invalide.');

        DB::table('users')->where('id', 1)->update(['is_active' => false]);
        $this->postJson('/forgot-password', [
            'email' => 'teacher@example.test',
            'recovery_key' => str_repeat('A', 64),
            'password' => 'new-strong-password-2026',
            'password_confirmation' => 'new-strong-password-2026',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Adresse e-mail ou clé de récupération invalide.');
    }
}
