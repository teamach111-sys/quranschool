<?php

namespace Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminUsersTableTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('roles', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('role_key');
            $table->string('name_fr');
            $table->string('name_ar');
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedInteger('role_id');
            $table->string('full_name');
            $table->string('email');
            $table->string('password_hash');
            $table->string('phone')->nullable();
            $table->string('language');
            $table->boolean('is_active');
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('role_change_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('changed_by');
            $table->unsignedInteger('old_role_id')->nullable();
            $table->unsignedInteger('new_role_id');
            $table->string('reason', 255)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function test_admin_lists_only_same_school_users_and_never_exposes_password_hashes(): void
    {
        $this->seedUsers();
        $this->actingAs($this->admin());

        $response = $this->getJson('/admin/users/table');

        $response->assertOk()
            ->assertJsonCount(3, 'users')
            ->assertJsonCount(3, 'roles')
            ->assertJsonPath('users.0.full_name', 'Admin User')
            ->assertJsonPath('users.0.role_name_fr', 'Administrateur')
            ->assertJsonPath('users.0.is_self', true);
        $this->assertArrayNotHasKey('password_hash', $response->json('users.0'));
    }

    public function test_admin_deactivates_a_user_and_records_the_account_change(): void
    {
        $this->seedUsers();
        $this->actingAs($this->admin());

        $this->patchJson('/admin/users/21/status', ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('updated', true);

        $this->assertDatabaseHas('users', ['id' => 21, 'school_id' => 1, 'is_active' => false]);
        $this->assertDatabaseHas('role_change_history', [
            'user_id' => 21,
            'changed_by' => 10,
            'old_role_id' => 3,
            'new_role_id' => 3,
            'reason' => 'Account deactivated.',
        ]);
    }

    public function test_admin_reactivates_a_user(): void
    {
        $this->seedUsers();
        DB::table('users')->where('id', 21)->update(['is_active' => false]);
        $this->actingAs($this->admin());

        $this->patchJson('/admin/users/21/status', ['is_active' => true])->assertOk();

        $this->assertDatabaseHas('users', ['id' => 21, 'is_active' => true]);
        $this->assertDatabaseHas('role_change_history', [
            'user_id' => 21,
            'reason' => 'Account reactivated.',
        ]);
    }

    public function test_admin_cannot_deactivate_their_own_account(): void
    {
        $this->seedUsers();
        $this->actingAs($this->admin());

        $this->patchJson('/admin/users/10/status', ['is_active' => false])
            ->assertUnprocessable();

        $this->assertDatabaseHas('users', ['id' => 10, 'is_active' => true]);
        $this->assertDatabaseCount('role_change_history', 0);
    }

    public function test_admin_changes_a_user_role_and_records_the_reason(): void
    {
        $this->seedUsers();
        $this->actingAs($this->admin());

        $this->patchJson('/admin/users/20/role', [
            'role_id' => 3,
            'reason' => 'Moving to office administration.',
        ])->assertOk();

        $this->assertDatabaseHas('users', ['id' => 20, 'role_id' => 3]);
        $this->assertDatabaseHas('role_change_history', [
            'user_id' => 20,
            'changed_by' => 10,
            'old_role_id' => 2,
            'new_role_id' => 3,
            'reason' => 'Moving to office administration.',
        ]);
    }

    public function test_admin_cannot_change_their_own_role(): void
    {
        $this->seedUsers();
        $this->actingAs($this->admin());

        $this->patchJson('/admin/users/10/role', [
            'role_id' => 2,
            'reason' => 'Role update.',
        ])->assertUnprocessable();

        $this->assertDatabaseHas('users', ['id' => 10, 'role_id' => 1]);
        $this->assertDatabaseCount('role_change_history', 0);
    }

    public function test_admin_cannot_manage_a_user_from_another_school(): void
    {
        $this->seedUsers();
        $this->actingAs($this->admin());

        $this->patchJson('/admin/users/30/role', [
            'role_id' => 2,
            'reason' => 'Role update.',
        ])->assertNotFound();

        $this->assertDatabaseHas('users', ['id' => 30, 'school_id' => 2, 'role_id' => 1]);
    }

    public function test_admin_resets_a_user_password_without_returning_the_password(): void
    {
        $this->seedUsers();
        $this->actingAs($this->admin());

        $response = $this->putJson('/admin/users/20/access', [
            'password' => 'a-strong-password-2026',
            'password_confirmation' => 'a-strong-password-2026',
        ]);

        $response->assertOk()->assertJsonPath('updated', true);
        $this->assertTrue(Hash::check(
            'a-strong-password-2026',
            DB::table('users')->where('id', 20)->value('password_hash'),
        ));
        $response->assertJsonMissing(['password' => 'a-strong-password-2026']);
    }

    public function test_password_reset_requires_a_long_confirmed_password(): void
    {
        $this->seedUsers();
        $this->actingAs($this->admin());
        $originalHash = DB::table('users')->where('id', 20)->value('password_hash');

        $this->putJson('/admin/users/20/access', [
            'password' => 'short',
            'password_confirmation' => 'different',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertSame($originalHash, DB::table('users')->where('id', 20)->value('password_hash'));
    }

    public function test_non_admin_cannot_access_user_management(): void
    {
        $this->seedUsers();
        $this->actingAs(new GenericUser([
            'id' => 20,
            'school_id' => 1,
            'role' => 'teacher',
        ]));

        $this->getJson('/admin/users/table')->assertForbidden();
        $this->patchJson('/admin/users/21/status', ['is_active' => false])->assertForbidden();
    }

    public function test_user_management_requires_authentication(): void
    {
        $this->getJson('/admin/users/table')->assertUnauthorized();
    }

    private function admin(): GenericUser
    {
        return new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]);
    }

    private function seedUsers(): void
    {
        DB::table('roles')->insert([
            ['id' => 1, 'role_key' => 'admin', 'name_fr' => 'Administrateur', 'name_ar' => 'مدير النظام'],
            ['id' => 2, 'role_key' => 'teacher', 'name_fr' => 'Enseignant', 'name_ar' => 'معلم'],
            ['id' => 3, 'role_key' => 'secretary', 'name_fr' => 'Secrétariat', 'name_ar' => 'السكرتارية'],
        ]);

        DB::table('users')->insert([
            [
                'id' => 10,
                'school_id' => 1,
                'role_id' => 1,
                'full_name' => 'Admin User',
                'email' => 'admin@example.test',
                'password_hash' => Hash::make('original-admin-password'),
                'phone' => '+212 600 000 000',
                'language' => 'fr',
                'is_active' => true,
                'updated_at' => now(),
            ],
            [
                'id' => 20,
                'school_id' => 1,
                'role_id' => 2,
                'full_name' => 'Teacher User',
                'email' => 'teacher@example.test',
                'password_hash' => Hash::make('original-teacher-password'),
                'phone' => null,
                'language' => 'ar',
                'is_active' => true,
                'updated_at' => now(),
            ],
            [
                'id' => 21,
                'school_id' => 1,
                'role_id' => 3,
                'full_name' => 'Secretary User',
                'email' => 'secretary@example.test',
                'password_hash' => Hash::make('original-secretary-password'),
                'phone' => '+212 600 000 001',
                'language' => 'fr',
                'is_active' => true,
                'updated_at' => now(),
            ],
            [
                'id' => 30,
                'school_id' => 2,
                'role_id' => 1,
                'full_name' => 'Other School Admin',
                'email' => 'other-admin@example.test',
                'password_hash' => Hash::make('original-other-password'),
                'phone' => null,
                'language' => 'fr',
                'is_active' => true,
                'updated_at' => now(),
            ],
        ]);
    }
}
