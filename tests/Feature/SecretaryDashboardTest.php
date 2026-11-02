<?php

namespace Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SecretaryDashboardTest extends TestCase
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
            $table->boolean('is_current');
            $table->boolean('is_active');
        });

        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('year_id');
            $table->string('name');
        });

        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('year_id');
            $table->unsignedBigInteger('room_id')->nullable();
            $table->string('student_number')->nullable();
            $table->string('full_name');
            $table->string('guardian_phone')->nullable();
            $table->string('guardian_email')->nullable();
            $table->string('status');
            $table->timestamp('created_at');
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
            $table->timestamp('created_at');
        });
    }

    public function test_secretary_sees_school_and_year_scoped_operational_data(): void
    {
        $this->seedDashboardData();
        $this->travelTo('2026-11-02 12:00:00');
        $this->actingAs($this->user('secretary'));

        $this->getJson('/secretary/dashboard-summary?year_id=1')
            ->assertOk()
            ->assertJsonPath('year_id', 1)
            ->assertJsonPath('year_label', '2026-2027')
            ->assertJsonPath('currency', 'MAD')
            ->assertJsonPath('stats.active_students', 2)
            ->assertJsonPath('stats.new_students', 1)
            ->assertJsonPath('stats.incomplete_students', 3)
            ->assertJsonPath('stats.attendance_follow_up', 4)
            ->assertJsonPath('stats.payment_balances.due.count', 1)
            ->assertJsonPath('stats.payment_balances.due.amount', 100)
            ->assertJsonPath('stats.payment_balances.late.count', 1)
            ->assertJsonPath('stats.payment_balances.late.amount', 125.5)
            ->assertJsonCount(4, 'new_or_incomplete_students')
            ->assertJsonPath('new_or_incomplete_students.0.student_name', 'New Student')
            ->assertJsonPath('new_or_incomplete_students.0.is_new', true)
            ->assertJsonPath('new_or_incomplete_students.1.missing_fields.0', 'student_number')
            ->assertJsonPath('new_or_incomplete_students.2.missing_fields.0', 'guardian_email')
            ->assertJsonCount(4, 'attendance_follow_up_items')
            ->assertJsonPath('attendance_follow_up_items.0.status', 'late')
            ->assertJsonCount(5, 'recent_payments')
            ->assertJsonPath('recent_payments.0.id', 3)
            ->assertJsonPath('recent_payments.0.status', 'paid')
            ->assertJsonPath('recent_payments.0.created_at', '2026-11-02 11:00:00')
            ->assertJsonPath('alerts.late_payments', 1)
            ->assertJsonPath('alerts.repeated_absences', 1)
            ->assertJsonCount(1, 'repeated_absence_items')
            ->assertJsonPath('repeated_absence_items.0.student_id', 1)
            ->assertJsonPath('repeated_absence_items.0.absence_count', 4)
            ->assertJsonPath('repeated_absence_items.0.latest_attendance_date', '2026-10-31')
            ->assertJsonPath('alerts.incomplete_student_records', 3);
    }

    public function test_secretary_dashboard_defaults_to_the_current_school_year(): void
    {
        $this->seedDashboardData();
        $this->actingAs($this->user('secretary'));

        $this->getJson('/secretary/dashboard-summary')
            ->assertOk()
            ->assertJsonPath('year_id', 1)
            ->assertJsonPath('year_label', '2026-2027');
    }

    public function test_secretary_cannot_select_another_school_or_non_current_year(): void
    {
        $this->seedDashboardData();
        $this->actingAs($this->user('secretary'));

        $this->getJson('/secretary/dashboard-summary?year_id=2')->assertNotFound();
        $this->getJson('/secretary/dashboard-summary?year_id=3')->assertNotFound();
    }

    public function test_invalid_year_id_returns_validation_error(): void
    {
        $this->seedDashboardData();
        $this->actingAs($this->user('secretary'));

        $this->getJson('/secretary/dashboard-summary?year_id=invalid')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('year_id');
    }

    public function test_secretary_dashboard_requires_authentication(): void
    {
        $this->getJson('/secretary/dashboard-summary')->assertUnauthorized();
    }

    public static function nonSecretaryRoles(): array
    {
        return [
            'administrator' => ['admin'],
            'teacher' => ['teacher'],
        ];
    }

    #[DataProvider('nonSecretaryRoles')]
    public function test_returns_403_for_non_secretary_roles(string $role): void
    {
        $this->actingAs($this->user($role))
            ->getJson('/secretary/dashboard-summary')
            ->assertForbidden();
    }

    private function seedDashboardData(): void
    {
        DB::table('schools')->insert([
            ['id' => 1, 'currency' => 'MAD'],
            ['id' => 2, 'currency' => 'EUR'],
        ]);
        DB::table('academic_years')->insert([
            ['id' => 1, 'school_id' => 1, 'year_label' => '2026-2027', 'start_date' => '2026-09-01', 'is_current' => true, 'is_active' => true],
            ['id' => 2, 'school_id' => 2, 'year_label' => '2026-2027', 'start_date' => '2026-09-01', 'is_current' => true, 'is_active' => true],
            ['id' => 3, 'school_id' => 1, 'year_label' => '2025-2026', 'start_date' => '2025-09-01', 'is_current' => false, 'is_active' => false],
        ]);
        DB::table('rooms')->insert([
            ['id' => 1, 'school_id' => 1, 'year_id' => 1, 'name' => 'Alpha'],
            ['id' => 2, 'school_id' => 2, 'year_id' => 2, 'name' => 'Other School Room'],
        ]);
        DB::table('students')->insert([
            ['id' => 1, 'school_id' => 1, 'year_id' => 1, 'room_id' => 1, 'student_number' => 'S-001', 'full_name' => 'New Student', 'guardian_phone' => '0600000001', 'guardian_email' => 'new@example.test', 'status' => 'active', 'created_at' => '2026-10-30 13:00:00'],
            ['id' => 2, 'school_id' => 1, 'year_id' => 1, 'room_id' => 1, 'student_number' => 'S-002', 'full_name' => 'Mariam Ali', 'guardian_phone' => null, 'guardian_email' => 'mariam@example.test', 'status' => 'active', 'created_at' => '2026-08-01 09:00:00'],
            ['id' => 3, 'school_id' => 1, 'year_id' => 1, 'room_id' => 1, 'student_number' => null, 'full_name' => 'Paused Student', 'guardian_phone' => '0600000003', 'guardian_email' => 'paused@example.test', 'status' => 'paused', 'created_at' => '2026-10-01 09:00:00'],
            ['id' => 4, 'school_id' => 1, 'year_id' => 1, 'room_id' => 1, 'student_number' => null, 'full_name' => 'Withdrawn Student', 'guardian_phone' => null, 'guardian_email' => null, 'status' => 'withdrawn', 'created_at' => '2026-11-02 09:00:00'],
            ['id' => 5, 'school_id' => 2, 'year_id' => 2, 'room_id' => 2, 'student_number' => 'S-005', 'full_name' => 'Other School Student', 'guardian_phone' => '0600000005', 'guardian_email' => 'other@example.test', 'status' => 'active', 'created_at' => '2026-10-31 09:00:00'],
            ['id' => 6, 'school_id' => 1, 'year_id' => 3, 'room_id' => null, 'student_number' => 'S-006', 'full_name' => 'Former Student', 'guardian_phone' => '0600000006', 'guardian_email' => 'former@example.test', 'status' => 'active', 'created_at' => '2025-10-31 09:00:00'],
            ['id' => 7, 'school_id' => 1, 'year_id' => 1, 'room_id' => 1, 'student_number' => 'S-007', 'full_name' => 'Email Missing Student', 'guardian_phone' => '0600000007', 'guardian_email' => null, 'status' => 'paused', 'created_at' => '2026-09-10 09:00:00'],
        ]);
        DB::table('attendance')->insert([
            ['id' => 1, 'student_id' => 1, 'school_id' => 1, 'room_id' => 1, 'year_id' => 1, 'attendance_date' => '2026-10-29', 'status' => 'absent'],
            ['id' => 2, 'student_id' => 1, 'school_id' => 1, 'room_id' => 1, 'year_id' => 1, 'attendance_date' => '2026-10-30', 'status' => 'absent'],
            ['id' => 3, 'student_id' => 1, 'school_id' => 1, 'room_id' => 1, 'year_id' => 1, 'attendance_date' => '2026-10-31', 'status' => 'absent'],
            ['id' => 4, 'student_id' => 2, 'school_id' => 1, 'room_id' => 1, 'year_id' => 1, 'attendance_date' => '2026-11-01', 'status' => 'late'],
            ['id' => 5, 'student_id' => 1, 'school_id' => 1, 'room_id' => 1, 'year_id' => 1, 'attendance_date' => '2026-10-20', 'status' => 'absent'],
            ['id' => 6, 'student_id' => 4, 'school_id' => 1, 'room_id' => 1, 'year_id' => 1, 'attendance_date' => '2026-11-01', 'status' => 'absent'],
            ['id' => 7, 'student_id' => 5, 'school_id' => 2, 'room_id' => 2, 'year_id' => 2, 'attendance_date' => '2026-11-01', 'status' => 'absent'],
            ['id' => 8, 'student_id' => 6, 'school_id' => 1, 'room_id' => null, 'year_id' => 3, 'attendance_date' => '2026-11-01', 'status' => 'absent'],
        ]);
        DB::table('payments')->insert([
            ['id' => 1, 'student_id' => 1, 'school_id' => 1, 'year_id' => 1, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'amount' => '125.50', 'currency' => 'MAD', 'status' => 'late', 'created_at' => '2026-11-01 10:00:00'],
            ['id' => 2, 'student_id' => 2, 'school_id' => 1, 'year_id' => 1, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'amount' => '100.00', 'currency' => 'MAD', 'status' => 'due', 'created_at' => '2026-10-30 10:00:00'],
            ['id' => 3, 'student_id' => 1, 'school_id' => 1, 'year_id' => 1, 'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'amount' => '50.00', 'currency' => 'MAD', 'status' => 'paid', 'created_at' => '2026-11-02 11:00:00'],
            ['id' => 4, 'student_id' => 5, 'school_id' => 2, 'year_id' => 2, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'amount' => '90.00', 'currency' => 'EUR', 'status' => 'late', 'created_at' => '2026-11-01 12:00:00'],
            ['id' => 5, 'student_id' => 1, 'school_id' => 1, 'year_id' => 1, 'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'amount' => '25.00', 'currency' => 'MAD', 'status' => 'paid', 'created_at' => '2026-10-29 10:00:00'],
            ['id' => 6, 'student_id' => 1, 'school_id' => 1, 'year_id' => 1, 'period_start' => '2026-07-01', 'period_end' => '2026-07-31', 'amount' => '25.00', 'currency' => 'MAD', 'status' => 'paid', 'created_at' => '2026-10-28 10:00:00'],
            ['id' => 7, 'student_id' => 1, 'school_id' => 1, 'year_id' => 1, 'period_start' => '2026-06-01', 'period_end' => '2026-06-30', 'amount' => '25.00', 'currency' => 'MAD', 'status' => 'paid', 'created_at' => '2026-10-27 10:00:00'],
        ]);
    }

    private function user(string $role): GenericUser
    {
        return new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => $role,
        ]);
    }
}
