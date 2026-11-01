<?php

namespace Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RoleChangeHistoryTableTest extends TestCase
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
            $table->string('email')->unique();
            $table->string('password_hash');
            $table->string('phone')->nullable();
            $table->string('language');
            $table->boolean('is_active');
            $table->timestamp('created_at')->nullable();
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

    public function test_admin_lists_joined_role_history_for_their_school_in_newest_first_order(): void
    {
        $this->seedSchoolUsers();

        DB::table('role_change_history')->insert([
            [
                'id' => 1,
                'school_id' => 1,
                'user_id' => 20,
                'changed_by' => 10,
                'old_role_id' => 2,
                'new_role_id' => 3,
                'reason' => 'Promotion.',
                'created_at' => '2026-01-01 09:00:00',
            ],
            [
                'id' => 2,
                'school_id' => 1,
                'user_id' => 20,
                'changed_by' => 10,
                'old_role_id' => null,
                'new_role_id' => 2,
                'reason' => 'Initial role assignment.',
                'created_at' => '2026-01-02 09:00:00',
            ],
            [
                'id' => 3,
                'school_id' => 2,
                'user_id' => 30,
                'changed_by' => 30,
                'old_role_id' => null,
                'new_role_id' => 1,
                'reason' => 'Other school entry.',
                'created_at' => '2026-01-03 09:00:00',
            ],
        ]);
        $this->actingAs($this->admin());

        $response = $this->getJson('/admin/role-change-history/table');

        $response->assertOk()
            ->assertJsonCount(2, 'history')
            ->assertJsonPath('history.0.id', 2)
            ->assertJsonPath('history.0.user_name', 'Teacher User')
            ->assertJsonPath('history.0.changed_by_name', 'Admin User')
            ->assertJsonPath('history.0.old_role_id', null)
            ->assertJsonPath('history.0.new_role_name_fr', 'Enseignant')
            ->assertJsonPath('history.0.reason', 'Initial role assignment.')
            ->assertJsonPath('history.1.old_role_name_ar', 'معلم')
            ->assertJsonPath('history.1.new_role_name_ar', 'السكرتارية')
            ->assertJsonCount(2, 'users')
            ->assertJsonMissing(['reason' => 'Other school entry.']);
    }

    public function test_non_admin_cannot_access_role_history(): void
    {
        $this->actingAs(new GenericUser([
            'id' => 20,
            'school_id' => 1,
            'role' => 'teacher',
        ]));

        $this->getJson('/admin/role-change-history/table')->assertForbidden();
    }

    public function test_role_history_requires_authentication(): void
    {
        $this->getJson('/admin/role-change-history/table')->assertUnauthorized();
    }

    private function admin(): GenericUser
    {
        return new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]);
    }

    private function seedSchoolUsers(): void
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
                'password_hash' => 'not-returned',
                'language' => 'fr',
                'is_active' => true,
            ],
            [
                'id' => 20,
                'school_id' => 1,
                'role_id' => 2,
                'full_name' => 'Teacher User',
                'email' => 'teacher@example.test',
                'password_hash' => 'not-returned',
                'language' => 'fr',
                'is_active' => true,
            ],
            [
                'id' => 30,
                'school_id' => 2,
                'role_id' => 1,
                'full_name' => 'Other School Admin',
                'email' => 'other-admin@example.test',
                'password_hash' => 'not-returned',
                'language' => 'fr',
                'is_active' => true,
            ],
        ]);
    }
}
