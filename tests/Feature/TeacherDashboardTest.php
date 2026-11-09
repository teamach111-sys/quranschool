<?php

namespace Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TeacherDashboardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('academic_years', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->string('year_label');
            $table->date('start_date');
            $table->boolean('is_current');
            $table->boolean('is_active');
        });

        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('year_id');
            $table->string('name');
            $table->boolean('is_active');
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
            $table->string('status');
        });

        Schema::create('schedules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('room_id');
            $table->unsignedBigInteger('teacher_id');
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('year_id');
            $table->unsignedTinyInteger('day_of_week');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->string('label', 100);
        });

        Schema::create('attendance', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('room_id')->nullable();
            $table->unsignedBigInteger('year_id');
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->date('attendance_date');
            $table->string('status');
            $table->string('note', 500)->nullable();
        });

        Schema::create('memorization_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('room_id')->nullable();
            $table->unsignedBigInteger('teacher_id')->nullable();
            $table->unsignedBigInteger('year_id');
            $table->date('session_date');
            $table->string('portion_label');
            $table->string('evaluation');
            $table->timestamp('created_at')->nullable();
            $table->unsignedTinyInteger('surah_start')->nullable();
            $table->unsignedSmallInteger('ayah_start')->nullable();
            $table->unsignedTinyInteger('surah_end')->nullable();
            $table->unsignedSmallInteger('ayah_end')->nullable();
        });
    }

    public function test_teacher_sees_personal_groups_lessons_attendance_tasks_and_recent_sessions(): void
    {
        $this->seedDashboardData();
        $this->travelTo('2026-11-02 12:00:00');
        $this->actingAs($this->teacher());

        $this->getJson('/teacher/dashboard-summary?year_id=1')
            ->assertOk()
            ->assertJsonPath('year_id', 1)
            ->assertJsonPath('year_label', '2026-2027')
            ->assertJsonPath('today', '2026-11-02')
            ->assertJsonCount(2, 'groups')
            ->assertJsonPath('groups.0.name', 'Alpha')
            ->assertJsonPath('groups.0.active_student_count', 2)
            ->assertJsonPath('groups.1.name', 'Beta')
            ->assertJsonCount(1, 'today_lessons')
            ->assertJsonPath('today_lessons.0.room_name', 'Alpha')
            ->assertJsonPath('today_lessons.0.starts_at', '16:00')
            ->assertJsonPath('today_lessons.0.late_suggestion', false)
            ->assertJsonPath('today_focus_lesson_id', 1)
            ->assertJsonCount(2, 'today_lessons.0.students')
            ->assertJsonPath('today_lessons.0.students.0.student_name', 'Bilal Ali')
            ->assertJsonPath('today_lessons.0.students.0.status', null)
            ->assertJsonPath('today_lessons.0.students.0.can_edit', true)
            ->assertJsonPath('today_lessons.0.students.1.student_name', 'Mariam Ali')
            ->assertJsonPath('today_lessons.0.students.1.status', 'present')
            ->assertJsonPath('today_lessons.0.students.1.can_edit', true)
            ->assertJsonCount(3, 'upcoming_lessons')
            ->assertJsonPath('upcoming_lessons.0.lesson_date', '2026-11-03')
            ->assertJsonPath('upcoming_lessons.0.room_name', 'Alpha')
            ->assertJsonPath('upcoming_lessons.1.lesson_date', '2026-11-05')
            ->assertJsonPath('upcoming_lessons.1.label', 'Hifz')
            ->assertJsonPath('upcoming_lessons.2.lesson_date', '2026-11-09')
            ->assertJsonPath('attendance_tasks.count', 1)
            ->assertJsonCount(1, 'attendance_tasks.students')
            ->assertJsonCount(0, 'attendance_tasks.slots')
            ->assertJsonPath('attendance_tasks.students.0.student_name', 'Bilal Ali')
            ->assertJsonPath('recent_sessions.0.portion_label', 'Al-Baqarah, verses 6-10')
            ->assertJsonPath('recent_sessions.0.student_name', 'Mariam Ali')
            ->assertJsonCount(2, 'recent_sessions');
    }

    public function test_teacher_sees_unmarked_slots_scheduled_for_today(): void
    {
        $this->seedDashboardData();
        DB::table('schedules')->insert([
            'id' => 10,
            'room_id' => 2,
            'teacher_id' => 20,
            'school_id' => 1,
            'year_id' => 1,
            'day_of_week' => 1,
            'starts_at' => '18:00:00',
            'ends_at' => '19:00:00',
            'valid_from' => '2026-11-02',
            'valid_until' => null,
            'label' => 'Revision',
        ]);
        $this->travelTo('2026-11-02 12:00:00');
        $this->actingAs($this->teacher());

        $this->getJson('/teacher/dashboard-summary?year_id=1')
            ->assertOk()
            ->assertJsonCount(1, 'attendance_tasks.slots')
            ->assertJsonPath('attendance_tasks.slots.0.id', 10)
            ->assertJsonPath('attendance_tasks.slots.0.room_id', 2)
            ->assertJsonPath('attendance_tasks.slots.0.room_name', 'Beta')
            ->assertJsonPath('attendance_tasks.slots.0.label', 'Revision');
    }

    public function test_teacher_progress_charts_only_include_students_in_currently_assigned_rooms(): void
    {
        $this->seedDashboardData();
        $this->travelTo('2026-11-02 12:00:00');
        DB::table('memorization_sessions')->insert([
            ['id' => 10, 'student_id' => 1, 'school_id' => 1, 'room_id' => 1, 'teacher_id' => 20, 'year_id' => 1, 'session_date' => '2026-11-01', 'portion_label' => 'First range', 'evaluation' => 'good', 'surah_start' => 2, 'ayah_start' => 1, 'surah_end' => 2, 'ayah_end' => 5],
            ['id' => 11, 'student_id' => 1, 'school_id' => 1, 'room_id' => 1, 'teacher_id' => 20, 'year_id' => 1, 'session_date' => '2026-11-01', 'portion_label' => 'Overlapping range', 'evaluation' => 'good', 'surah_start' => 2, 'ayah_start' => 4, 'surah_end' => 2, 'ayah_end' => 8],
            ['id' => 12, 'student_id' => 2, 'school_id' => 1, 'room_id' => 1, 'teacher_id' => 21, 'year_id' => 1, 'session_date' => '2026-11-01', 'portion_label' => 'More verses', 'evaluation' => 'excellent', 'surah_start' => 2, 'ayah_start' => 1, 'surah_end' => 2, 'ayah_end' => 20],
            ['id' => 13, 'student_id' => 4, 'school_id' => 1, 'room_id' => 2, 'teacher_id' => 20, 'year_id' => 1, 'session_date' => '2026-11-01', 'portion_label' => 'Beta range', 'evaluation' => 'good', 'surah_start' => 3, 'ayah_start' => 1, 'surah_end' => 3, 'ayah_end' => 30],
            ['id' => 14, 'student_id' => 5, 'school_id' => 1, 'room_id' => 3, 'teacher_id' => 20, 'year_id' => 1, 'session_date' => '2026-11-01', 'portion_label' => 'Former room range', 'evaluation' => 'excellent', 'surah_start' => 4, 'ayah_start' => 1, 'surah_end' => 4, 'ayah_end' => 40],
        ]);
        $this->actingAs($this->teacher());

        $this->getJson('/teacher/dashboard-summary?year_id=1')
            ->assertOk()
            ->assertJsonPath('student_progress.distribution.started', 3)
            ->assertJsonPath('student_progress.distribution.none', 1)
            ->assertJsonCount(3, 'student_progress.top_students')
            ->assertJsonPath('student_progress.top_students.0.student_name', 'Zayd Ali')
            ->assertJsonPath('student_progress.top_students.0.percentage', 0.48);
    }

    public function test_new_wednesday_schedule_appears_with_its_students_in_attendance_on_that_wednesday(): void
    {
        $this->seedDashboardData();
        DB::table('schedules')->insert([
            'id' => 10,
            'room_id' => 2,
            'teacher_id' => 20,
            'school_id' => 1,
            'year_id' => 1,
            'day_of_week' => 3,
            'starts_at' => '16:00:00',
            'ends_at' => '17:00:00',
            'valid_from' => '2026-11-04',
            'valid_until' => null,
            'label' => 'Wednesday Revision',
        ]);
        $this->travelTo('2026-11-04 12:00:00');
        $this->actingAs($this->teacher());

        $this->getJson('/teacher/dashboard-summary?year_id=1')
            ->assertOk()
            ->assertJsonPath('today', '2026-11-04')
            ->assertJsonCount(1, 'today_lessons')
            ->assertJsonPath('today_lessons.0.id', 10)
            ->assertJsonPath('today_lessons.0.room_id', 2)
            ->assertJsonPath('today_lessons.0.label', 'Wednesday Revision')
            ->assertJsonPath('today_focus_lesson_id', 10)
            ->assertJsonCount(1, 'today_lessons.0.students')
            ->assertJsonPath('today_lessons.0.students.0.student_name', 'Zayd Ali')
            ->assertJsonPath('today_lessons.0.students.0.status', null)
            ->assertJsonPath('attendance_tasks.count', 1)
            ->assertJsonPath('attendance_tasks.students.0.student_name', 'Zayd Ali')
            ->assertJsonCount(1, 'attendance_tasks.slots')
            ->assertJsonPath('attendance_tasks.slots.0.id', 10);

        $this->assertDatabaseCount('attendance', 1);
    }

    public function test_teacher_has_no_pending_attendance_tasks_when_no_session_is_scheduled_today(): void
    {
        $this->seedDashboardData();
        $this->travelTo('2026-11-06 12:00:00');
        $this->actingAs($this->teacher());

        $this->getJson('/teacher/dashboard-summary?year_id=1')
            ->assertOk()
            ->assertJsonCount(0, 'today_lessons')
            ->assertJsonPath('attendance_tasks.count', 0)
            ->assertJsonCount(0, 'attendance_tasks.students')
            ->assertJsonCount(0, 'attendance_tasks.slots');
    }

    public function test_running_lesson_is_prioritized_over_a_later_slot_for_attendance(): void
    {
        $this->seedDashboardData();
        DB::table('schedules')->insert([
            'id' => 10,
            'room_id' => 2,
            'teacher_id' => 20,
            'school_id' => 1,
            'year_id' => 1,
            'day_of_week' => 1,
            'starts_at' => '17:00:00',
            'ends_at' => '18:00:00',
            'valid_from' => '2026-09-01',
            'valid_until' => null,
            'label' => 'Revision',
        ]);
        $this->travelTo('2026-11-02 16:30:00');
        $this->actingAs($this->teacher());

        $this->getJson('/teacher/dashboard-summary?year_id=1')
            ->assertOk()
            ->assertJsonCount(2, 'today_lessons')
            ->assertJsonPath('today_focus_lesson_id', 1);
    }

    public function test_dashboard_uses_an_unmarked_lesson_after_all_slots_have_ended(): void
    {
        $this->seedDashboardData();
        $this->travelTo('2026-11-02 18:00:00');
        $this->actingAs($this->teacher());

        $this->getJson('/teacher/dashboard-summary?year_id=1')
            ->assertOk()
            ->assertJsonPath('today_focus_lesson_id', 1)
            ->assertJsonPath('today_lessons.0.late_suggestion', true)
            ->assertJsonPath('today_lessons.0.ends_at', '17:00');

        $this->assertDatabaseCount('attendance', 1);
    }

    public function test_dashboard_does_not_suggest_late_before_a_lesson_ends(): void
    {
        $this->seedDashboardData();
        $this->travelTo('2026-11-02 16:59:00');
        $this->actingAs($this->teacher());

        $this->getJson('/teacher/dashboard-summary?year_id=1')
            ->assertOk()
            ->assertJsonPath('today_lessons.0.late_suggestion', false);

        $this->assertDatabaseCount('attendance', 1);
    }

    public function test_dashboard_marks_another_teachers_attendance_as_read_only(): void
    {
        $this->seedDashboardData();
        DB::table('attendance')->where('id', 1)->update(['recorded_by' => 21]);
        $this->travelTo('2026-11-02 12:00:00');
        $this->actingAs($this->teacher());

        $this->getJson('/teacher/dashboard-summary?year_id=1')
            ->assertOk()
            ->assertJsonPath('today_lessons.0.students.1.status', 'present')
            ->assertJsonPath('today_lessons.0.students.1.can_edit', false);
    }

    public function test_teacher_dashboard_requires_authentication(): void
    {
        $this->getJson('/teacher/dashboard-summary')->assertUnauthorized();
    }

    public function test_teacher_dashboard_defaults_to_the_current_school_year(): void
    {
        $this->seedDashboardData();
        $this->actingAs($this->teacher());

        $this->getJson('/teacher/dashboard-summary')
            ->assertOk()
            ->assertJsonPath('year_id', 1)
            ->assertJsonPath('year_label', '2026-2027');
    }

    public function test_non_teacher_cannot_access_teacher_dashboard(): void
    {
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $this->getJson('/teacher/dashboard-summary')->assertForbidden();
    }

    public function test_teacher_cannot_select_another_school_or_non_current_year(): void
    {
        $this->seedDashboardData();
        $this->actingAs($this->teacher());

        $this->getJson('/teacher/dashboard-summary?year_id=2')->assertNotFound();
        $this->getJson('/teacher/dashboard-summary?year_id=3')->assertNotFound();
        $this->getJson('/teacher/dashboard-summary?year_id=invalid')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('year_id');
    }

    private function seedDashboardData(): void
    {
        DB::table('academic_years')->insert([
            ['id' => 1, 'school_id' => 1, 'year_label' => '2026-2027', 'start_date' => '2026-09-01', 'is_current' => true, 'is_active' => true],
            ['id' => 2, 'school_id' => 2, 'year_label' => '2026-2027', 'start_date' => '2026-09-01', 'is_current' => true, 'is_active' => true],
            ['id' => 3, 'school_id' => 1, 'year_label' => '2025-2026', 'start_date' => '2025-09-01', 'is_current' => false, 'is_active' => false],
        ]);
        DB::table('rooms')->insert([
            ['id' => 1, 'school_id' => 1, 'year_id' => 1, 'name' => 'Alpha', 'is_active' => true],
            ['id' => 2, 'school_id' => 1, 'year_id' => 1, 'name' => 'Beta', 'is_active' => true],
            ['id' => 3, 'school_id' => 1, 'year_id' => 1, 'name' => 'Former Room', 'is_active' => true],
            ['id' => 4, 'school_id' => 1, 'year_id' => 1, 'name' => 'Archived Room', 'is_active' => false],
            ['id' => 5, 'school_id' => 2, 'year_id' => 2, 'name' => 'Other School Room', 'is_active' => true],
        ]);
        DB::table('room_teachers')->insert([
            ['room_id' => 1, 'teacher_id' => 20, 'school_id' => 1, 'year_id' => 1, 'assigned_from' => '2026-09-01', 'assigned_until' => null],
            ['room_id' => 2, 'teacher_id' => 20, 'school_id' => 1, 'year_id' => 1, 'assigned_from' => '2026-11-02', 'assigned_until' => '2026-11-04'],
            ['room_id' => 3, 'teacher_id' => 20, 'school_id' => 1, 'year_id' => 1, 'assigned_from' => '2026-09-01', 'assigned_until' => '2026-11-01'],
            ['room_id' => 4, 'teacher_id' => 20, 'school_id' => 1, 'year_id' => 1, 'assigned_from' => '2026-09-01', 'assigned_until' => null],
            ['room_id' => 5, 'teacher_id' => 30, 'school_id' => 2, 'year_id' => 2, 'assigned_from' => '2026-09-01', 'assigned_until' => null],
        ]);
        DB::table('students')->insert([
            ['id' => 1, 'school_id' => 1, 'year_id' => 1, 'room_id' => 1, 'student_number' => 'S-001', 'full_name' => 'Mariam Ali', 'status' => 'active'],
            ['id' => 2, 'school_id' => 1, 'year_id' => 1, 'room_id' => 1, 'student_number' => 'S-002', 'full_name' => 'Bilal Ali', 'status' => 'active'],
            ['id' => 3, 'school_id' => 1, 'year_id' => 1, 'room_id' => 1, 'student_number' => 'S-003', 'full_name' => 'Paused Student', 'status' => 'paused'],
            ['id' => 4, 'school_id' => 1, 'year_id' => 1, 'room_id' => 2, 'student_number' => 'S-004', 'full_name' => 'Zayd Ali', 'status' => 'active'],
            ['id' => 5, 'school_id' => 1, 'year_id' => 1, 'room_id' => 3, 'student_number' => 'S-005', 'full_name' => 'Former Room Student', 'status' => 'active'],
            ['id' => 6, 'school_id' => 2, 'year_id' => 2, 'room_id' => 5, 'student_number' => 'S-006', 'full_name' => 'Other School Student', 'status' => 'active'],
        ]);
        DB::table('schedules')->insert([
            ['id' => 1, 'room_id' => 1, 'teacher_id' => 20, 'school_id' => 1, 'year_id' => 1, 'day_of_week' => 1, 'starts_at' => '16:00:00', 'ends_at' => '17:00:00', 'valid_from' => '2026-09-01', 'valid_until' => null, 'label' => 'Hifz'],
            ['id' => 2, 'room_id' => 1, 'teacher_id' => 20, 'school_id' => 1, 'year_id' => 1, 'day_of_week' => 2, 'starts_at' => '16:00:00', 'ends_at' => '17:00:00', 'valid_from' => '2026-09-01', 'valid_until' => null, 'label' => 'Revision'],
            ['id' => 3, 'room_id' => 1, 'teacher_id' => 20, 'school_id' => 1, 'year_id' => 1, 'day_of_week' => 3, 'starts_at' => '16:00:00', 'ends_at' => '17:00:00', 'valid_from' => '2026-09-01', 'valid_until' => '2026-11-03', 'label' => 'Hifz'],
            ['id' => 4, 'room_id' => 1, 'teacher_id' => 20, 'school_id' => 1, 'year_id' => 1, 'day_of_week' => 4, 'starts_at' => '16:00:00', 'ends_at' => '17:00:00', 'valid_from' => '2026-11-05', 'valid_until' => null, 'label' => 'Hifz'],
            ['id' => 5, 'room_id' => 3, 'teacher_id' => 20, 'school_id' => 1, 'year_id' => 1, 'day_of_week' => 1, 'starts_at' => '18:00:00', 'ends_at' => '19:00:00', 'valid_from' => '2026-09-01', 'valid_until' => null, 'label' => 'Former assignment slot'],
            ['id' => 6, 'room_id' => 1, 'teacher_id' => 21, 'school_id' => 1, 'year_id' => 1, 'day_of_week' => 1, 'starts_at' => '19:00:00', 'ends_at' => '20:00:00', 'valid_from' => '2026-09-01', 'valid_until' => null, 'label' => 'Other teacher slot'],
            ['id' => 7, 'room_id' => 5, 'teacher_id' => 30, 'school_id' => 2, 'year_id' => 2, 'day_of_week' => 1, 'starts_at' => '16:00:00', 'ends_at' => '17:00:00', 'valid_from' => '2026-09-01', 'valid_until' => null, 'label' => 'Other school slot'],
            ['id' => 8, 'room_id' => 2, 'teacher_id' => 20, 'school_id' => 1, 'year_id' => 1, 'day_of_week' => 4, 'starts_at' => '17:00:00', 'ends_at' => '18:00:00', 'valid_from' => '2026-09-01', 'valid_until' => null, 'label' => 'After assignment ends'],
            ['id' => 9, 'room_id' => 1, 'teacher_id' => 20, 'school_id' => 1, 'year_id' => 1, 'day_of_week' => 1, 'starts_at' => '15:00:00', 'ends_at' => '16:00:00', 'valid_from' => '2026-09-01', 'valid_until' => '2026-11-01', 'label' => 'Expired today'],
        ]);
        DB::table('attendance')->insert([
            ['id' => 1, 'student_id' => 1, 'school_id' => 1, 'room_id' => 1, 'year_id' => 1, 'recorded_by' => 20, 'attendance_date' => '2026-11-02', 'status' => 'present', 'note' => null],
        ]);
        DB::table('memorization_sessions')->insert([
            ['id' => 1, 'student_id' => 1, 'school_id' => 1, 'room_id' => 1, 'teacher_id' => 20, 'year_id' => 1, 'session_date' => '2026-11-01', 'portion_label' => 'Al-Baqarah, verses 6-10', 'evaluation' => 'excellent', 'created_at' => '2026-11-01 10:00:00'],
            ['id' => 2, 'student_id' => 2, 'school_id' => 1, 'room_id' => 1, 'teacher_id' => 20, 'year_id' => 1, 'session_date' => '2026-10-30', 'portion_label' => 'Al-Baqarah, verses 1-5', 'evaluation' => 'good', 'created_at' => '2026-10-30 10:00:00'],
            ['id' => 3, 'student_id' => 2, 'school_id' => 1, 'room_id' => 1, 'teacher_id' => 21, 'year_id' => 1, 'session_date' => '2026-11-02', 'portion_label' => 'Another teacher session', 'evaluation' => 'good', 'created_at' => '2026-11-02 10:00:00'],
            ['id' => 4, 'student_id' => 6, 'school_id' => 2, 'room_id' => 5, 'teacher_id' => 30, 'year_id' => 2, 'session_date' => '2026-11-02', 'portion_label' => 'Other school session', 'evaluation' => 'good', 'created_at' => '2026-11-02 10:00:00'],
        ]);
    }

    private function teacher(): GenericUser
    {
        return new GenericUser([
            'id' => 20,
            'school_id' => 1,
            'role' => 'teacher',
        ]);
    }
}
