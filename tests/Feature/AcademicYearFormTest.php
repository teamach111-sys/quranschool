<?php

namespace Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AcademicYearFormTest extends TestCase
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
        $this->createAuditEventsTable();

        Schema::create('academic_years', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->string('year_label', 20);
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
                'is_active' => true,
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

    public function test_admin_can_create_a_current_year_without_clearing_it(): void
    {
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $this->postJson('/admin/academic-years', [
            'year_label' => '2027-2028',
            'start_date' => '2027-09-01',
            'end_date' => '2028-06-30',
            'is_active' => false,
            'is_current' => true,
        ])
            ->assertOk()
            ->assertJsonCount(3, 'years')
            ->assertJsonPath('years.0.is_current', true);

        $this->assertDatabaseHas('academic_years', [
            'school_id' => 1,
            'year_label' => '2027-2028',
            'is_current' => true,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('academic_years', [
            'id' => 1,
            'school_id' => 1,
            'is_current' => false,
        ]);
        $this->assertDatabaseHas('academic_years', [
            'id' => 3,
            'school_id' => 2,
            'is_current' => true,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'entity_type' => 'academic_year',
            'summary_key' => 'academic_year.created',
            'actor_user_id' => 10,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'entity_id' => 1,
            'summary_key' => 'academic_year.status_changed',
        ]);
    }

    public function test_admin_can_update_an_open_historical_year(): void
    {
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $this->putJson('/admin/academic-years/2', [
            'year_label' => '2025-2026 revised',
            'start_date' => '2025-09-01',
            'end_date' => '2026-06-30',
            'is_active' => true,
            'is_current' => false,
        ])
            ->assertOk()
            ->assertJsonPath('years.1.label', '2025-2026 revised');

        $this->assertDatabaseHas('academic_years', [
            'id' => 2,
            'school_id' => 1,
            'year_label' => '2025-2026 revised',
        ]);
    }

    public function test_invalid_dates_and_duplicate_school_labels_are_rejected(): void
    {
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $invalidDates = [
            'year_label' => '2028-2029',
            'start_date' => '2028-09-01',
            'end_date' => '2028-08-31',
            'is_active' => true,
            'is_current' => false,
        ];

        $this->postJson('/admin/academic-years', $invalidDates)->assertUnprocessable();

        $duplicateLabel = [
            ...$invalidDates,
            'year_label' => '2026-2027',
            'end_date' => '2029-06-30',
        ];

        $this->postJson('/admin/academic-years', $duplicateLabel)->assertUnprocessable();
    }

    public function test_non_admin_cannot_create_or_update_academic_years(): void
    {
        $this->actingAs(new GenericUser([
            'id' => 11,
            'school_id' => 1,
            'role' => 'teacher',
        ]));

        $payload = [
            'year_label' => '2027-2028',
            'start_date' => '2027-09-01',
            'end_date' => '2028-06-30',
            'is_active' => true,
            'is_current' => false,
        ];

        $this->postJson('/admin/academic-years', $payload)->assertForbidden();
        $this->putJson('/admin/academic-years/2', $payload)->assertForbidden();
    }

    private function createAuditEventsTable(): void
    {
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
    }
}
