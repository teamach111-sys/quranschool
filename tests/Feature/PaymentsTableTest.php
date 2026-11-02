<?php

namespace Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PaymentsTableTest extends TestCase
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

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->string('full_name');
        });

        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('year_id');
            $table->string('student_number')->nullable();
            $table->string('full_name');
            $table->string('guardian_phone')->nullable();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('year_id');
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('amount', 10, 2);
            $table->char('currency', 3);
            $table->string('status');
            $table->dateTime('paid_at')->nullable();
            $table->string('payment_method', 40)->nullable();
            $table->string('reference', 100)->nullable();
            $table->string('notes', 500)->nullable();
        });
    }

    public function test_admin_lists_school_payments_filtered_by_student_status_and_period_date(): void
    {
        $this->seedPayments();
        $this->actingAs($this->user(10, 1, 'admin'));

        $this->getJson('/payments/table?year_id=1&student_id=1&status=paid&period_date=2026-10-15')
            ->assertOk()
            ->assertJsonPath('year_id', 1)
            ->assertJsonPath('year_label', '2026-2027')
            ->assertJsonPath('currency', 'MAD')
            ->assertJsonPath('can_manage', true)
            ->assertJsonPath('can_record', true)
            ->assertJsonPath('is_secretary', false)
            ->assertJsonCount(1, 'payments')
            ->assertJsonPath('payments.0.id', 1)
            ->assertJsonPath('payments.0.student_name', 'Mariam Ali')
            ->assertJsonPath('payments.0.student_number', 'S-001')
            ->assertJsonPath('payments.0.recorded_by_name', 'Secretary User')
            ->assertJsonPath('payments.0.amount', '125.50')
            ->assertJsonPath('payments.0.status', 'paid')
            ->assertJsonMissingPath('payments.0.guardian_phone')
            ->assertJsonCount(2, 'students')
            ->assertJsonMissing(['student_name' => 'Other School Student']);
    }

    public function test_admin_can_view_a_closed_historical_year_without_payment_management_access(): void
    {
        $this->seedPayments();
        $this->actingAs($this->user(10, 1, 'admin'));

        $this->getJson('/payments/table?year_id=2')
            ->assertOk()
            ->assertJsonPath('year_label', '2025-2026')
            ->assertJsonPath('can_manage', false)
            ->assertJsonPath('can_record', false)
            ->assertJsonCount(1, 'payments')
            ->assertJsonPath('payments.0.student_name', 'Former Student');
    }

    public function test_secretary_can_view_current_year_payments_but_not_closed_historical_years(): void
    {
        $this->seedPayments();
        $this->actingAs($this->user(12, 1, 'secretary'));

        $this->getJson('/payments/table')
            ->assertOk()
            ->assertJsonPath('year_id', 1)
            ->assertJsonPath('can_manage', false)
            ->assertJsonPath('can_record', true)
            ->assertJsonPath('is_secretary', true)
            ->assertJsonCount(3, 'payments');

        $this->getJson('/payments/table?status=late')
            ->assertOk()
            ->assertJsonCount(1, 'payments')
            ->assertJsonPath('payments.0.guardian_phone', '0600000002');

        $this->getJson('/payments/table?year_id=2')->assertNotFound();
    }

    public function test_admin_can_manage_payments_in_an_open_noncurrent_year(): void
    {
        $this->seedPayments();
        DB::table('academic_years')->where('id', 2)->update(['is_active' => true]);
        $this->actingAs($this->user(10, 1, 'admin'));

        $this->getJson('/payments/table?year_id=2')
            ->assertOk()
            ->assertJsonPath('can_manage', true)
            ->assertJsonPath('can_record', true);
    }

    public function test_secretary_can_view_payments_for_an_open_noncurrent_year(): void
    {
        $this->seedPayments();
        DB::table('academic_years')->where('id', 2)->update(['is_active' => true]);
        $this->actingAs($this->user(12, 1, 'secretary'));

        $this->getJson('/payments/table')
            ->assertOk()
            ->assertJsonPath('year_id', 1);

        $this->getJson('/payments/table?year_id=2')
            ->assertOk()
            ->assertJsonPath('year_id', 2)
            ->assertJsonPath('can_record', true)
            ->assertJsonCount(1, 'payments');
    }

    public function test_teacher_cannot_access_payments(): void
    {
        $this->seedPayments();
        $this->actingAs($this->user(20, 1, 'teacher'));

        $this->getJson('/payments/table')->assertForbidden();
    }

    public function test_payments_table_requires_authentication(): void
    {
        $this->getJson('/payments/table')->assertUnauthorized();
    }

    public function test_payments_table_rejects_invalid_and_out_of_scope_filters(): void
    {
        $this->seedPayments();
        $this->actingAs($this->user(10, 1, 'admin'));

        $this->getJson('/payments/table?year_id=1&student_id=3&status=refunded&period_date=not-a-date')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['student_id', 'status', 'period_date']);
    }

    private function seedPayments(): void
    {
        DB::table('schools')->insert([
            ['id' => 1, 'currency' => 'MAD'],
            ['id' => 2, 'currency' => 'EUR'],
        ]);
        DB::table('academic_years')->insert([
            ['id' => 1, 'school_id' => 1, 'year_label' => '2026-2027', 'start_date' => '2026-09-01', 'is_current' => true, 'is_active' => true],
            ['id' => 2, 'school_id' => 1, 'year_label' => '2025-2026', 'start_date' => '2025-09-01', 'is_current' => false, 'is_active' => false],
            ['id' => 3, 'school_id' => 2, 'year_label' => '2026-2027', 'start_date' => '2026-09-01', 'is_current' => true, 'is_active' => true],
        ]);
        DB::table('users')->insert([
            ['id' => 10, 'school_id' => 1, 'full_name' => 'Admin User'],
            ['id' => 12, 'school_id' => 1, 'full_name' => 'Secretary User'],
            ['id' => 20, 'school_id' => 1, 'full_name' => 'Teacher User'],
            ['id' => 30, 'school_id' => 2, 'full_name' => 'Other School Admin'],
        ]);
        DB::table('students')->insert([
            ['id' => 1, 'school_id' => 1, 'year_id' => 1, 'student_number' => 'S-001', 'full_name' => 'Mariam Ali', 'guardian_phone' => '0600000001'],
            ['id' => 2, 'school_id' => 1, 'year_id' => 1, 'student_number' => 'S-002', 'full_name' => 'Zayd Ali', 'guardian_phone' => '0600000002'],
            ['id' => 3, 'school_id' => 1, 'year_id' => 2, 'student_number' => 'S-003', 'full_name' => 'Former Student', 'guardian_phone' => '0600000003'],
            ['id' => 4, 'school_id' => 2, 'year_id' => 3, 'student_number' => 'S-004', 'full_name' => 'Other School Student', 'guardian_phone' => '0600000004'],
        ]);
        $paymentDefaults = [
            'recorded_by' => null,
            'paid_at' => null,
            'payment_method' => null,
            'reference' => null,
            'notes' => null,
        ];
        $payments = [
            [
                'id' => 1,
                'student_id' => 1,
                'school_id' => 1,
                'year_id' => 1,
                'recorded_by' => 12,
                'period_start' => '2026-10-01',
                'period_end' => '2026-10-31',
                'amount' => '125.50',
                'currency' => 'MAD',
                'status' => 'paid',
                'paid_at' => '2026-10-05 10:30:00',
                'payment_method' => 'cash',
                'reference' => 'REC-001',
                'notes' => 'October payment',
            ],
            [
                'id' => 2,
                'student_id' => 1,
                'school_id' => 1,
                'year_id' => 1,
                'recorded_by' => null,
                'period_start' => '2026-11-01',
                'period_end' => '2026-11-30',
                'amount' => '125.50',
                'currency' => 'MAD',
                'status' => 'due',
            ],
            [
                'id' => 3,
                'student_id' => 2,
                'school_id' => 1,
                'year_id' => 1,
                'recorded_by' => 10,
                'period_start' => '2026-10-01',
                'period_end' => '2026-10-31',
                'amount' => '100.00',
                'currency' => 'MAD',
                'status' => 'late',
            ],
            [
                'id' => 4,
                'student_id' => 3,
                'school_id' => 1,
                'year_id' => 2,
                'recorded_by' => 10,
                'period_start' => '2025-10-01',
                'period_end' => '2025-10-31',
                'amount' => '90.00',
                'currency' => 'MAD',
                'status' => 'waived',
            ],
            [
                'id' => 5,
                'student_id' => 4,
                'school_id' => 2,
                'year_id' => 3,
                'recorded_by' => 30,
                'period_start' => '2026-10-01',
                'period_end' => '2026-10-31',
                'amount' => '90.00',
                'currency' => 'EUR',
                'status' => 'paid',
            ],
        ];
        DB::table('payments')->insert(array_map(
            fn (array $payment) => array_merge($paymentDefaults, $payment),
            $payments,
        ));
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
