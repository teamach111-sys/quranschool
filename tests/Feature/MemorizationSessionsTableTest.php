<?php

namespace Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MemorizationSessionsTableTest extends TestCase
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

        Schema::create('roles', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('role_key');
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedInteger('role_id');
            $table->string('full_name');
        });

        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('year_id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
        });

        Schema::create('room_teachers', function (Blueprint $table) {
            $table->unsignedBigInteger('room_id');
            $table->unsignedBigInteger('teacher_id');
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('year_id');
            $table->date('assigned_from');
            $table->date('assigned_until')->nullable();
        });

        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('year_id');
            $table->unsignedBigInteger('room_id')->nullable();
            $table->string('student_number')->nullable();
            $table->string('full_name');
            $table->string('status')->default('active');
        });

        Schema::create('memorization_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('room_id')->nullable();
            $table->unsignedBigInteger('teacher_id')->nullable();
            $table->unsignedBigInteger('year_id');
            $table->date('session_date');
            $table->unsignedTinyInteger('surah_start')->nullable();
            $table->unsignedSmallInteger('ayah_start')->nullable();
            $table->unsignedTinyInteger('surah_end')->nullable();
            $table->unsignedSmallInteger('ayah_end')->nullable();
            $table->string('portion_label', 160);
            $table->string('evaluation');
            $table->text('notes')->nullable();
        });
    }

    public function test_admin_lists_and_filters_school_sessions_for_the_selected_year(): void
    {
        $this->seedMemorizationSessions();
        $this->actingAs($this->user(10, 1, 'admin'));

        $this->getJson('/memorization-sessions/table?year_id=1&room_id=2&teacher_id=21&student_id=2&session_date=2026-10-10')
            ->assertOk()
            ->assertJsonPath('can_filter_teacher', true)
            ->assertJsonCount(1, 'sessions')
            ->assertJsonPath('sessions.0.id', 3)
            ->assertJsonPath('sessions.0.student_name', 'Mariam Ali')
            ->assertJsonPath('sessions.0.room_name', 'Beta')
            ->assertJsonPath('sessions.0.teacher_name', 'Teacher Two')
            ->assertJsonPath('sessions.0.portion_label', 'Al-Baqarah, verses 1-5')
            ->assertJsonPath('sessions.0.evaluation', 'excellent')
            ->assertJsonCount(2, 'rooms')
            ->assertJsonCount(2, 'teachers')
            ->assertJsonCount(3, 'students');
    }

    public function test_admin_list_excludes_sessions_from_other_schools(): void
    {
        $this->seedMemorizationSessions();
        $this->actingAs($this->user(10, 1, 'admin'));

        $this->getJson('/memorization-sessions/table?year_id=1')
            ->assertOk()
            ->assertJsonCount(3, 'sessions')
            ->assertJsonMissing([
                'student_name' => 'Other Student',
                'portion_label' => 'Other school session',
            ]);
    }

    public function test_teacher_only_sees_sessions_for_rooms_assigned_on_the_session_date(): void
    {
        $this->seedMemorizationSessions();
        $this->actingAs($this->user(20, 1, 'teacher'));

        $this->getJson('/memorization-sessions/table?year_id=1')
            ->assertOk()
            ->assertJsonPath('can_filter_teacher', false)
            ->assertJsonCount(1, 'sessions')
            ->assertJsonPath('sessions.0.id', 1)
            ->assertJsonPath('sessions.0.student_name', 'Zayd Ali')
            ->assertJsonCount(1, 'rooms')
            ->assertJsonCount(0, 'teachers')
            ->assertJsonCount(1, 'students')
            ->assertJsonCount(2, 'form_options.students')
            ->assertJsonCount(1, 'form_options.rooms');
    }

    public function test_session_table_returns_404_when_non_admin_selects_closed_year(): void
    {
        $this->seedMemorizationSessions();
        $this->actingAs($this->user(20, 1, 'teacher'));

        $this->getJson('/memorization-sessions/table?year_id=3')->assertNotFound();
    }

    public function test_teacher_can_view_an_open_noncurrent_year(): void
    {
        $this->seedMemorizationSessions();
        DB::table('academic_years')->where('id', 3)->update(['is_active' => true]);
        $this->actingAs($this->user(20, 1, 'teacher'));

        $this->getJson('/memorization-sessions/table')
            ->assertOk()
            ->assertJsonPath('year_id', 1);

        $this->getJson('/memorization-sessions/table?year_id=3')
            ->assertOk()
            ->assertJsonPath('year_id', 3)
            ->assertJsonPath('can_create', true);
    }

    public function test_secretary_can_view_sessions_for_the_current_year(): void
    {
        $this->seedMemorizationSessions();
        $this->actingAs($this->user(12, 1, 'secretary'));

        $this->getJson('/memorization-sessions/table?year_id=1')
            ->assertOk()
            ->assertJsonPath('can_filter_teacher', true)
            ->assertJsonCount(3, 'sessions');
    }

    public function test_session_table_returns_401_without_authentication(): void
    {
        $this->getJson('/memorization-sessions/table')->assertUnauthorized();
    }

    public function test_session_table_returns_422_for_an_invalid_filter_date(): void
    {
        $this->seedMemorizationSessions();
        $this->actingAs($this->user(10, 1, 'admin'));

        $this->getJson('/memorization-sessions/table?year_id=1&session_date=not-a-date')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('session_date');
    }

    private function seedMemorizationSessions(): void
    {
        DB::table('academic_years')->insert([
            ['id' => 1, 'school_id' => 1, 'year_label' => '2026-2027', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'is_current' => true, 'is_active' => true],
            ['id' => 2, 'school_id' => 2, 'year_label' => '2026-2027', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'is_current' => true, 'is_active' => true],
            ['id' => 3, 'school_id' => 1, 'year_label' => '2025-2026', 'start_date' => '2025-09-01', 'end_date' => '2026-06-30', 'is_current' => false, 'is_active' => false],
        ]);
        DB::table('roles')->insert([
            ['id' => 1, 'role_key' => 'admin'],
            ['id' => 2, 'role_key' => 'teacher'],
            ['id' => 3, 'role_key' => 'secretary'],
        ]);
        DB::table('users')->insert([
            ['id' => 10, 'school_id' => 1, 'role_id' => 1, 'full_name' => 'Admin'],
            ['id' => 12, 'school_id' => 1, 'role_id' => 3, 'full_name' => 'Secretary'],
            ['id' => 20, 'school_id' => 1, 'role_id' => 2, 'full_name' => 'Teacher One'],
            ['id' => 21, 'school_id' => 1, 'role_id' => 2, 'full_name' => 'Teacher Two'],
            ['id' => 30, 'school_id' => 2, 'role_id' => 2, 'full_name' => 'Other School Teacher'],
        ]);
        DB::table('rooms')->insert([
            ['id' => 1, 'school_id' => 1, 'year_id' => 1, 'name' => 'Alpha'],
            ['id' => 2, 'school_id' => 1, 'year_id' => 1, 'name' => 'Beta'],
            ['id' => 3, 'school_id' => 2, 'year_id' => 2, 'name' => 'Other School Room'],
        ]);
        DB::table('room_teachers')->insert([
            ['room_id' => 1, 'teacher_id' => 20, 'school_id' => 1, 'year_id' => 1, 'assigned_from' => '2026-10-01', 'assigned_until' => '2026-10-10'],
            ['room_id' => 2, 'teacher_id' => 21, 'school_id' => 1, 'year_id' => 1, 'assigned_from' => '2026-09-01', 'assigned_until' => null],
        ]);
        DB::table('students')->insert([
            ['id' => 1, 'school_id' => 1, 'year_id' => 1, 'room_id' => 1, 'student_number' => 'S-001', 'full_name' => 'Zayd Ali'],
            ['id' => 2, 'school_id' => 1, 'year_id' => 1, 'room_id' => 2, 'student_number' => 'S-002', 'full_name' => 'Mariam Ali'],
            ['id' => 3, 'school_id' => 2, 'year_id' => 2, 'room_id' => 3, 'student_number' => 'S-003', 'full_name' => 'Other Student'],
            ['id' => 4, 'school_id' => 1, 'year_id' => 1, 'room_id' => 1, 'student_number' => 'S-004', 'full_name' => 'New Student'],
        ]);
        DB::table('memorization_sessions')->insert([
            [
                'id' => 1,
                'student_id' => 1,
                'school_id' => 1,
                'room_id' => 1,
                'teacher_id' => 20,
                'year_id' => 1,
                'session_date' => '2026-10-10',
                'surah_start' => 1,
                'ayah_start' => 1,
                'surah_end' => 1,
                'ayah_end' => 5,
                'portion_label' => 'Al-Fatiha, verses 1-5',
                'evaluation' => 'good',
                'notes' => null,
            ],
            [
                'id' => 2,
                'student_id' => 1,
                'school_id' => 1,
                'room_id' => 1,
                'teacher_id' => 21,
                'year_id' => 1,
                'session_date' => '2026-10-11',
                'surah_start' => 1,
                'ayah_start' => 1,
                'surah_end' => 1,
                'ayah_end' => 7,
                'portion_label' => 'Al-Fatiha',
                'evaluation' => 'needs_review',
                'notes' => 'Repeat next class',
            ],
            [
                'id' => 3,
                'student_id' => 2,
                'school_id' => 1,
                'room_id' => 2,
                'teacher_id' => 21,
                'year_id' => 1,
                'session_date' => '2026-10-10',
                'surah_start' => 2,
                'ayah_start' => 1,
                'surah_end' => 2,
                'ayah_end' => 5,
                'portion_label' => 'Al-Baqarah, verses 1-5',
                'evaluation' => 'excellent',
                'notes' => 'Fluent recitation',
            ],
            [
                'id' => 4,
                'student_id' => 3,
                'school_id' => 2,
                'room_id' => 3,
                'teacher_id' => 30,
                'year_id' => 2,
                'session_date' => '2026-10-10',
                'surah_start' => null,
                'ayah_start' => null,
                'surah_end' => null,
                'ayah_end' => null,
                'portion_label' => 'Other school session',
                'evaluation' => 'good',
                'notes' => null,
            ],
        ]);
    }

    private function user(int $id, int $schoolId, string $role): GenericUser
    {
        return new GenericUser([
            'id' => $id,
            'school_id' => $schoolId,
            'role' => $role,
        ]);
    }
}
