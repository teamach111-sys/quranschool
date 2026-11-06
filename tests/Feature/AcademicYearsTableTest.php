<?php

namespace Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AcademicYearsTableTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->string('full_name');
        });
        DB::table('users')->insert(['id' => 10, 'school_id' => 1, 'full_name' => 'Admin User']);

        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('year_id')->nullable();
            $table->unsignedBigInteger('actor_user_id');
            $table->string('actor_name', 120);
            $table->string('action_type', 48);
            $table->string('entity_type', 64);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('summary_key', 100);
            $table->json('changes')->nullable();
            $table->string('reason', 255)->nullable();
            $table->string('request_id', 100)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('academic_years', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->string('year_label');
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_current');
            $table->boolean('is_active');
            $table->timestamps();
        });

        DB::table('academic_years')->insert([
            [
                'school_id' => 1,
                'year_label' => '2026-2027',
                'start_date' => '2026-09-01',
                'end_date' => '2027-06-30',
                'is_current' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'school_id' => 1,
                'year_label' => '2025-2026',
                'start_date' => '2025-09-01',
                'end_date' => '2026-06-30',
                'is_current' => false,
                'is_active' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'school_id' => 2,
                'year_label' => '2026-2027',
                'start_date' => '2026-09-01',
                'end_date' => '2027-06-30',
                'is_current' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function test_admin_lists_only_their_school_years_with_management_access(): void
    {
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $this->getJson('/academic-years/table')
            ->assertOk()
            ->assertJsonCount(2, 'years')
            ->assertJsonPath('can_manage', true)
            ->assertJsonPath('current_id', 1)
            ->assertJsonPath('years.0.is_current', true)
            ->assertJsonPath('years.1.is_read_only', true);
    }

    public function test_teacher_can_read_school_years_but_cannot_manage_them(): void
    {
        $this->actingAs(new GenericUser([
            'id' => 11,
            'school_id' => 1,
            'role' => 'teacher',
        ]));

        $this->getJson('/academic-years/table')
            ->assertOk()
            ->assertJsonCount(2, 'years')
            ->assertJsonPath('can_manage', false)
            ->assertJsonPath('years.0.is_read_only', false)
            ->assertJsonPath('years.1.is_read_only', true);

        $this->patchJson('/admin/academic-years/2/status', ['action' => 'open'])
            ->assertForbidden();
    }

    public function test_open_historical_year_is_not_marked_read_only_for_a_teacher(): void
    {
        DB::table('academic_years')->where('id', 2)->update(['is_active' => true]);
        $this->actingAs(new GenericUser([
            'id' => 11,
            'school_id' => 1,
            'role' => 'teacher',
        ]));

        $this->getJson('/academic-years/table')
            ->assertOk()
            ->assertJsonPath('years.1.is_active', true)
            ->assertJsonPath('years.1.is_read_only', false);
    }

    public function test_admin_can_open_a_year_and_set_it_as_current(): void
    {
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $this->patchJson('/admin/academic-years/2/status', ['action' => 'open'])
            ->assertOk()
            ->assertJsonPath('years.1.is_active', true);

        $this->patchJson('/admin/academic-years/2/status', ['action' => 'set_current'])
            ->assertOk()
            ->assertJsonPath('years.1.is_current', true)
            ->assertJsonPath('years.1.is_active', true);

        $this->assertDatabaseHas('academic_years', [
            'id' => 1,
            'school_id' => 1,
            'is_current' => false,
        ]);
        $this->assertDatabaseHas('academic_years', [
            'id' => 2,
            'school_id' => 1,
            'is_current' => true,
            'is_active' => true,
        ]);
    }

    public function test_admin_cannot_close_the_current_year_or_change_another_school(): void
    {
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $this->patchJson('/admin/academic-years/1/status', ['action' => 'close'])
            ->assertUnprocessable();

        $this->patchJson('/admin/academic-years/3/status', ['action' => 'close'])
            ->assertNotFound();

        $this->assertDatabaseHas('academic_years', [
            'id' => 1,
            'school_id' => 1,
            'is_current' => true,
            'is_active' => true,
        ]);
    }
}
