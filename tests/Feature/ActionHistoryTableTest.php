<?php

namespace Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ActionHistoryTableTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->string('full_name');
        });

        Schema::create('academic_years', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->string('year_label');
            $table->date('start_date');
        });

        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('year_id')->nullable();
            $table->unsignedBigInteger('actor_user_id');
            $table->string('actor_name');
            $table->string('action_type');
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('summary_key');
            $table->json('changes')->nullable();
            $table->string('reason')->nullable();
            $table->string('request_id')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function test_admin_lists_newest_school_events_and_filter_options_without_leaking_other_schools(): void
    {
        $this->seedEvents();
        $this->actingAs($this->admin());

        $this->getJson('/admin/action-history/table')
            ->assertOk()
            ->assertJsonPath('count', 3)
            ->assertJsonPath('events.0.id', 3)
            ->assertJsonPath('events.0.actor_name', 'Second Admin')
            ->assertJsonPath('events.2.id', 1)
            ->assertJsonCount(2, 'actors')
            ->assertJsonCount(2, 'actions')
            ->assertJsonCount(2, 'entities')
            ->assertJsonCount(2, 'years')
            ->assertJsonMissing(['actor_name' => 'Other School Admin'])
            ->assertJsonMissing(['label' => 'Other year']);
    }

    public function test_admin_filters_action_history_by_actor_action_entity_year_date_and_search(): void
    {
        $this->seedEvents();
        $this->actingAs($this->admin());

        $this->getJson('/admin/action-history/table?actor_id=10&action_type=updated&entity_type=user&year_id=1&date_from=2026-01-02&date_to=2026-01-02&search=role')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('events.0.id', 2)
            ->assertJsonPath('events.0.summary_key', 'user.role_changed');
    }

    public function test_action_history_rejects_foreign_school_actor_or_year_filters(): void
    {
        $this->seedEvents();
        $this->actingAs($this->admin());

        $this->getJson('/admin/action-history/table?actor_id=30')->assertUnprocessable();
        $this->getJson('/admin/action-history/table?year_id=3')->assertUnprocessable();
    }

    public function test_action_history_rejects_a_reversed_date_range(): void
    {
        $this->seedEvents();
        $this->actingAs($this->admin());

        $this->getJson('/admin/action-history/table?date_from=2026-01-03&date_to=2026-01-02')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_to');
    }

    public function test_non_admin_cannot_access_action_history(): void
    {
        $this->actingAs(new GenericUser([
            'id' => 20,
            'school_id' => 1,
            'role' => 'teacher',
        ]));

        $this->getJson('/admin/action-history/table')->assertForbidden();
    }

    public function test_action_history_requires_authentication(): void
    {
        $this->getJson('/admin/action-history/table')->assertUnauthorized();
    }

    private function admin(): GenericUser
    {
        return new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]);
    }

    private function seedEvents(): void
    {
        DB::table('academic_years')->insert([
            ['id' => 1, 'school_id' => 1, 'year_label' => '2025-2026', 'start_date' => '2025-09-01'],
            ['id' => 2, 'school_id' => 1, 'year_label' => '2026-2027', 'start_date' => '2026-09-01'],
            ['id' => 3, 'school_id' => 2, 'year_label' => 'Other year', 'start_date' => '2026-09-01'],
        ]);

        DB::table('users')->insert([
            ['id' => 10, 'school_id' => 1, 'full_name' => 'Admin User'],
            ['id' => 11, 'school_id' => 1, 'full_name' => 'Second Admin'],
            ['id' => 30, 'school_id' => 2, 'full_name' => 'Other School Admin'],
        ]);

        DB::table('audit_events')->insert([
            [
                'id' => 1,
                'school_id' => 1,
                'year_id' => 1,
                'actor_user_id' => 10,
                'actor_name' => 'Admin User',
                'action_type' => 'exported',
                'entity_type' => 'payment',
                'entity_id' => null,
                'summary_key' => 'payment.table_exported',
                'reason' => null,
                'created_at' => '2026-01-01 09:00:00',
            ],
            [
                'id' => 2,
                'school_id' => 1,
                'year_id' => 1,
                'actor_user_id' => 10,
                'actor_name' => 'Admin User',
                'action_type' => 'updated',
                'entity_type' => 'user',
                'entity_id' => 20,
                'summary_key' => 'user.role_changed',
                'reason' => 'Role moved.',
                'created_at' => '2026-01-02 09:00:00',
            ],
            [
                'id' => 3,
                'school_id' => 1,
                'year_id' => null,
                'actor_user_id' => 11,
                'actor_name' => 'Second Admin',
                'action_type' => 'updated',
                'entity_type' => 'user',
                'entity_id' => 20,
                'summary_key' => 'user.status_changed',
                'reason' => 'Account deactivated.',
                'created_at' => '2026-01-03 09:00:00',
            ],
            [
                'id' => 4,
                'school_id' => 2,
                'year_id' => 3,
                'actor_user_id' => 30,
                'actor_name' => 'Other School Admin',
                'action_type' => 'created',
                'entity_type' => 'user',
                'entity_id' => 31,
                'summary_key' => 'user.created',
                'reason' => null,
                'created_at' => '2026-01-04 09:00:00',
            ],
        ]);
    }
}
