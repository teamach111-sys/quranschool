<?php

namespace Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AcademicYearSelectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('academic_years', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->string('year_label');
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_current');
            $table->boolean('is_active');
        });

        DB::table('academic_years')->insert([
            [
                'school_id' => 1,
                'year_label' => '2025-2026',
                'start_date' => '2025-09-01',
                'end_date' => '2026-06-30',
                'is_current' => false,
                'is_active' => true,
            ],
            [
                'school_id' => 1,
                'year_label' => '2024-2025',
                'start_date' => '2024-09-01',
                'end_date' => '2025-06-30',
                'is_current' => false,
                'is_active' => false,
            ],
            [
                'school_id' => 1,
                'year_label' => '2026-2027',
                'start_date' => '2026-09-01',
                'end_date' => '2027-06-30',
                'is_current' => true,
                'is_active' => true,
            ],
            [
                'school_id' => 2,
                'year_label' => '2026-2027-other-school',
                'start_date' => '2026-09-01',
                'end_date' => '2027-06-30',
                'is_current' => true,
                'is_active' => true,
            ],
            [
                'school_id' => 2,
                'year_label' => '2026-2027',
                'start_date' => '2026-08-01',
                'end_date' => '2027-07-31',
                'is_current' => true,
                'is_active' => true,
            ],
        ]);
    }

    public function test_admin_sees_open_historical_years_as_writable_and_closed_years_as_read_only(): void
    {
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $this->getJson('/academic-years')
            ->assertOk()
            ->assertJsonCount(3, 'years')
            ->assertJsonPath('current_id', 3)
            ->assertJsonPath('years.0.is_read_only', false)
            ->assertJsonPath('years.1.is_read_only', false)
            ->assertJsonPath('years.2.is_read_only', true);

        $this->getJson('/academic-years/table')
            ->assertOk()
            ->assertJsonPath('years.1.is_read_only', false)
            ->assertJsonPath('years.2.is_read_only', true);
    }

    public function test_teachers_and_secretaries_see_all_open_years_as_writable(): void
    {
        foreach (['teacher', 'secretary'] as $role) {
            $this->actingAs(new GenericUser([
                'id' => 10,
                'school_id' => 1,
                'role' => $role,
            ]));

            $this->getJson('/academic-years')
                ->assertOk()
                ->assertJsonCount(2, 'years')
                ->assertJsonPath('years.0.id', 3)
                ->assertJsonPath('years.0.is_read_only', false)
                ->assertJsonPath('years.1.id', 1)
                ->assertJsonPath('years.1.is_read_only', false);
        }
    }
}
