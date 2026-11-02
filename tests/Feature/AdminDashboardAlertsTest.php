<?php

namespace Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminDashboardAlertsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->char('currency', 3);
        });

        Schema::create('academic_years', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->string('year_label');
            $table->date('start_date');
            $table->date('end_date');
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

        Schema::create('attendance', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('room_id')->nullable();
            $table->unsignedBigInteger('year_id');
            $table->date('attendance_date');
            $table->string('status');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('year_id');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('amount', 10, 2);
            $table->char('currency', 3);
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
        });
    }

    public function test_admin_sees_only_alerts_for_the_selected_school_and_year(): void
    {
        $this->seedDashboardData();
        $this->travelTo('2026-10-10 12:00:00');
        $this->actingAs($this->user(10, 1, 'admin'));

        $response = $this->getJson('/admin/dashboard-summary?year_id=1');

        $response->assertOk()
            ->assertJsonCount(1, 'alerts.late_payments')
            ->assertJsonPath('alerts.late_payments.0.id', 1)
            ->assertJsonPath('alerts.late_payments.0.student_name', 'Mariam Ali')
            ->assertJsonPath('alerts.late_payments.0.period_end', '2026-10-01')
            ->assertJsonCount(1, 'alerts.repeated_absences')
            ->assertJsonPath('alerts.repeated_absences.0.student_id', 1)
            ->assertJsonPath('alerts.repeated_absences.0.student_name', 'Mariam Ali')
            ->assertJsonPath('alerts.repeated_absences.0.absence_count', 3)
            ->assertJsonPath('alerts.repeated_absences.0.room_name', 'Alpha')
            ->assertJsonCount(2, 'alerts.rooms_without_active_teacher')
            ->assertJsonPath('alerts.rooms_without_active_teacher.0.room_id', 2)
            ->assertJsonPath('alerts.rooms_without_active_teacher.0.room_name', 'Beta')
            ->assertJsonPath('alerts.rooms_without_active_teacher.1.room_name', 'Gamma')
            ->assertJsonCount(1, 'alerts.inactive_users_with_pending_items')
            ->assertJsonPath('alerts.inactive_users_with_pending_items.0.user_id', 21)
            ->assertJsonPath('alerts.inactive_users_with_pending_items.0.user_name', 'Inactive Teacher')
            ->assertJsonPath('alerts.inactive_users_with_pending_items.0.active_assignments', 1)
            ->assertJsonPath('alerts.inactive_users_with_pending_items.0.future_schedules', 2);
    }

    public function test_admin_sees_unmarked_schedule_dates_only_after_the_session_ends(): void
    {
        $this->seedDashboardData();
        DB::table('schedules')->delete();
        DB::table('schedules')->insert([
            [
                'id' => 10,
                'room_id' => 1,
                'teacher_id' => 20,
                'school_id' => 1,
                'year_id' => 1,
                'day_of_week' => 5,
                'starts_at' => '16:00:00',
                'ends_at' => '17:00:00',
                'valid_from' => '2026-10-02',
                'valid_until' => null,
            ],
            [
                'id' => 11,
                'room_id' => 2,
                'teacher_id' => 21,
                'school_id' => 1,
                'year_id' => 1,
                'day_of_week' => 6,
                'starts_at' => '10:00:00',
                'ends_at' => '11:00:00',
                'valid_from' => '2026-10-10',
                'valid_until' => null,
            ],
            [
                'id' => 12,
                'room_id' => 3,
                'teacher_id' => 20,
                'school_id' => 1,
                'year_id' => 1,
                'day_of_week' => 6,
                'starts_at' => '13:00:00',
                'ends_at' => '14:00:00',
                'valid_from' => '2026-10-10',
                'valid_until' => null,
            ],
            [
                'id' => 13,
                'room_id' => 5,
                'teacher_id' => 30,
                'school_id' => 2,
                'year_id' => 2,
                'day_of_week' => 5,
                'starts_at' => '16:00:00',
                'ends_at' => '17:00:00',
                'valid_from' => '2026-10-02',
                'valid_until' => null,
            ],
        ]);
        DB::table('attendance')->insert([
            'student_id' => 1,
            'school_id' => 1,
            'room_id' => 1,
            'year_id' => 1,
            'attendance_date' => '2026-10-02',
            'status' => 'present',
        ]);
        $this->travelTo('2026-10-10 12:00:00');
        $this->actingAs($this->user(10, 1, 'admin'));

        $this->getJson('/admin/dashboard-summary?year_id=1')
            ->assertOk()
            ->assertJsonCount(2, 'alerts.missed_attendance')
            ->assertJsonPath('alerts.missed_attendance.0.room_name', 'Alpha')
            ->assertJsonPath('alerts.missed_attendance.0.attendance_date', '2026-10-09')
            ->assertJsonPath('alerts.missed_attendance.1.room_name', 'Beta')
            ->assertJsonPath('alerts.missed_attendance.1.attendance_date', '2026-10-10');
    }

    public function test_non_admin_cannot_access_dashboard_alerts(): void
    {
        $this->actingAs($this->user(20, 1, 'teacher'));

        $this->getJson('/admin/dashboard-summary?year_id=1')->assertForbidden();
    }

    public function test_dashboard_alerts_require_authentication(): void
    {
        $this->getJson('/admin/dashboard-summary?year_id=1')->assertUnauthorized();
    }

    public function test_admin_cannot_read_another_schools_dashboard_year(): void
    {
        DB::table('academic_years')->insert([
            'id' => 2,
            'school_id' => 2,
            'year_label' => '2026-2027',
            'start_date' => '2026-09-01',
            'end_date' => '2027-06-30',
        ]);
        $this->actingAs($this->user(10, 1, 'admin'));

        $this->getJson('/admin/dashboard-summary?year_id=2')->assertNotFound();
    }

    private function seedDashboardData(): void
    {
        DB::table('schools')->insert([
            ['id' => 1, 'currency' => 'MAD'],
            ['id' => 2, 'currency' => 'EUR'],
        ]);
        DB::table('academic_years')->insert([
            ['id' => 1, 'school_id' => 1, 'year_label' => '2026-2027', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30'],
            ['id' => 2, 'school_id' => 2, 'year_label' => '2026-2027', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30'],
            ['id' => 3, 'school_id' => 1, 'year_label' => '2025-2026', 'start_date' => '2025-09-01', 'end_date' => '2026-06-30'],
        ]);
        DB::table('roles')->insert([
            ['id' => 1, 'role_key' => 'admin'],
            ['id' => 2, 'role_key' => 'teacher'],
            ['id' => 3, 'role_key' => 'secretary'],
        ]);
        DB::table('users')->insert([
            ['id' => 10, 'school_id' => 1, 'role_id' => 1, 'full_name' => 'Admin User', 'is_active' => true],
            ['id' => 20, 'school_id' => 1, 'role_id' => 2, 'full_name' => 'Active Teacher', 'is_active' => true],
            ['id' => 21, 'school_id' => 1, 'role_id' => 2, 'full_name' => 'Inactive Teacher', 'is_active' => false],
            ['id' => 22, 'school_id' => 1, 'role_id' => 2, 'full_name' => 'Former Teacher', 'is_active' => false],
            ['id' => 30, 'school_id' => 2, 'role_id' => 2, 'full_name' => 'Other School Teacher', 'is_active' => true],
        ]);
        DB::table('rooms')->insert([
            ['id' => 1, 'school_id' => 1, 'year_id' => 1, 'name' => 'Alpha', 'is_active' => true],
            ['id' => 2, 'school_id' => 1, 'year_id' => 1, 'name' => 'Beta', 'is_active' => true],
            ['id' => 3, 'school_id' => 1, 'year_id' => 1, 'name' => 'Gamma', 'is_active' => true],
            ['id' => 4, 'school_id' => 1, 'year_id' => 1, 'name' => 'Dormant', 'is_active' => false],
            ['id' => 5, 'school_id' => 2, 'year_id' => 2, 'name' => 'Other School Room', 'is_active' => true],
        ]);
        DB::table('room_teachers')->insert([
            ['room_id' => 1, 'teacher_id' => 20, 'school_id' => 1, 'year_id' => 1, 'assigned_from' => '2026-09-01', 'assigned_until' => null],
            ['room_id' => 2, 'teacher_id' => 21, 'school_id' => 1, 'year_id' => 1, 'assigned_from' => '2026-09-01', 'assigned_until' => null],
            ['room_id' => 3, 'teacher_id' => 22, 'school_id' => 1, 'year_id' => 1, 'assigned_from' => '2026-09-01', 'assigned_until' => '2026-10-09'],
        ]);
        DB::table('students')->insert([
            ['id' => 1, 'school_id' => 1, 'year_id' => 1, 'room_id' => 1, 'student_number' => 'S-001', 'full_name' => 'Mariam Ali', 'status' => 'active'],
            ['id' => 2, 'school_id' => 1, 'year_id' => 1, 'room_id' => 1, 'student_number' => 'S-002', 'full_name' => 'Zayd Ali', 'status' => 'active'],
            ['id' => 3, 'school_id' => 1, 'year_id' => 1, 'room_id' => 1, 'student_number' => 'S-003', 'full_name' => 'Paused Student', 'status' => 'paused'],
            ['id' => 4, 'school_id' => 2, 'year_id' => 2, 'room_id' => 5, 'student_number' => 'S-004', 'full_name' => 'Other School Student', 'status' => 'active'],
            ['id' => 5, 'school_id' => 1, 'year_id' => 3, 'room_id' => null, 'student_number' => 'S-005', 'full_name' => 'Former Student', 'status' => 'active'],
        ]);
        DB::table('attendance')->insert([
            ['id' => 1, 'student_id' => 1, 'school_id' => 1, 'year_id' => 1, 'attendance_date' => '2026-09-01', 'status' => 'absent'],
            ['id' => 2, 'student_id' => 1, 'school_id' => 1, 'year_id' => 1, 'attendance_date' => '2026-09-02', 'status' => 'absent'],
            ['id' => 3, 'student_id' => 1, 'school_id' => 1, 'year_id' => 1, 'attendance_date' => '2026-09-03', 'status' => 'absent'],
            ['id' => 4, 'student_id' => 1, 'school_id' => 1, 'year_id' => 1, 'attendance_date' => '2026-09-04', 'status' => 'late'],
            ['id' => 5, 'student_id' => 2, 'school_id' => 1, 'year_id' => 1, 'attendance_date' => '2026-09-01', 'status' => 'absent'],
            ['id' => 6, 'student_id' => 2, 'school_id' => 1, 'year_id' => 1, 'attendance_date' => '2026-09-02', 'status' => 'absent'],
            ['id' => 7, 'student_id' => 3, 'school_id' => 1, 'year_id' => 1, 'attendance_date' => '2026-09-01', 'status' => 'absent'],
            ['id' => 8, 'student_id' => 3, 'school_id' => 1, 'year_id' => 1, 'attendance_date' => '2026-09-02', 'status' => 'absent'],
            ['id' => 9, 'student_id' => 3, 'school_id' => 1, 'year_id' => 1, 'attendance_date' => '2026-09-03', 'status' => 'absent'],
            ['id' => 10, 'student_id' => 4, 'school_id' => 2, 'year_id' => 2, 'attendance_date' => '2026-09-01', 'status' => 'absent'],
            ['id' => 11, 'student_id' => 4, 'school_id' => 2, 'year_id' => 2, 'attendance_date' => '2026-09-02', 'status' => 'absent'],
            ['id' => 12, 'student_id' => 4, 'school_id' => 2, 'year_id' => 2, 'attendance_date' => '2026-09-03', 'status' => 'absent'],
            ['id' => 13, 'student_id' => 5, 'school_id' => 1, 'year_id' => 3, 'attendance_date' => '2025-09-01', 'status' => 'absent'],
            ['id' => 14, 'student_id' => 5, 'school_id' => 1, 'year_id' => 3, 'attendance_date' => '2025-09-02', 'status' => 'absent'],
            ['id' => 15, 'student_id' => 5, 'school_id' => 1, 'year_id' => 3, 'attendance_date' => '2025-09-03', 'status' => 'absent'],
        ]);
        DB::table('payments')->insert([
            ['id' => 1, 'student_id' => 1, 'school_id' => 1, 'year_id' => 1, 'period_start' => '2026-09-01', 'period_end' => '2026-10-01', 'amount' => '125.50', 'currency' => 'MAD', 'status' => 'late'],
            ['id' => 2, 'student_id' => 2, 'school_id' => 1, 'year_id' => 1, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'amount' => '100.00', 'currency' => 'MAD', 'status' => 'due'],
            ['id' => 3, 'student_id' => 4, 'school_id' => 2, 'year_id' => 2, 'period_start' => '2026-09-01', 'period_end' => '2026-10-01', 'amount' => '90.00', 'currency' => 'EUR', 'status' => 'late'],
            ['id' => 4, 'student_id' => 5, 'school_id' => 1, 'year_id' => 3, 'period_start' => '2025-09-01', 'period_end' => '2025-10-01', 'amount' => '90.00', 'currency' => 'MAD', 'status' => 'late'],
        ]);
        DB::table('schedules')->insert([
            ['id' => 1, 'room_id' => 2, 'teacher_id' => 21, 'school_id' => 1, 'year_id' => 1, 'day_of_week' => 1, 'starts_at' => '16:00:00', 'ends_at' => '17:00:00', 'valid_from' => '2026-09-01', 'valid_until' => null],
            ['id' => 2, 'room_id' => 2, 'teacher_id' => 21, 'school_id' => 1, 'year_id' => 1, 'day_of_week' => 3, 'starts_at' => '16:00:00', 'ends_at' => '17:00:00', 'valid_from' => '2026-09-01', 'valid_until' => null],
            ['id' => 3, 'room_id' => 3, 'teacher_id' => 22, 'school_id' => 1, 'year_id' => 1, 'day_of_week' => 5, 'starts_at' => '16:00:00', 'ends_at' => '17:00:00', 'valid_from' => '2026-09-01', 'valid_until' => '2026-10-09'],
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
