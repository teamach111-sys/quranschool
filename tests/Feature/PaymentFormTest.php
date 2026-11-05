<?php

namespace Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PaymentFormTest extends TestCase
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

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->string('full_name');
        });

        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->char('currency', 3);
        });

        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('year_id');
            $table->string('full_name');
            $table->string('student_number')->nullable();
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
            $table->timestamps();
        });
    }

    public function test_admin_records_a_payment_with_an_automatically_generated_receipt(): void
    {
        $this->seedPaymentData();
        $this->actingAs($this->user(10, 1, 'admin'));
        $this->travelTo('2026-11-03 12:00:00');

        $response = $this->postJson('/payments', [
            ...$this->validAdminPayment(),
            'period_start' => '2026-12-01',
            'period_end' => '2026-12-31',
            'status' => 'paid',
            'payment_method' => 'cash',
        ]);

        $response->assertCreated()->assertJsonStructure(['payment_id']);
        $this->assertDatabaseHas('payments', [
            'id' => $response->json('payment_id'),
            'student_id' => 1,
            'school_id' => 1,
            'year_id' => 1,
            'recorded_by' => 10,
            'period_start' => '2026-12-01',
            'period_end' => '2026-12-31',
            'amount' => '125.50',
            'currency' => 'MAD',
            'status' => 'paid',
            'paid_at' => '2026-11-03 12:00:00',
            'payment_method' => 'cash',
            'reference' => 'REC-2026-0002',
        ]);
    }

    public function test_admin_updates_a_payment_without_triggering_an_overlap_with_itself(): void
    {
        $this->seedPaymentData();
        $this->actingAs($this->user(10, 1, 'admin'));
        $this->travelTo('2026-11-03 12:00:00');

        $this->putJson('/payments/2', [
            ...$this->validAdminPayment(),
            'amount' => '130.00',
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
            'status' => 'paid',
            'payment_method' => 'cash',
        ])->assertOk()->assertJsonPath('updated', true);

        $this->assertDatabaseHas('payments', [
            'id' => 2,
            'status' => 'paid',
            'recorded_by' => 10,
            'amount' => '130.00',
            'paid_at' => '2026-11-03 12:00:00',
            'payment_method' => 'cash',
            'reference' => 'REC-2026-0002',
        ]);
    }

    public function test_admin_keeps_an_existing_receipt_when_editing_a_paid_payment(): void
    {
        $this->seedPaymentData();
        $this->actingAs($this->user(10, 1, 'admin'));
        $this->travelTo('2026-11-03 12:00:00');

        $this->putJson('/payments/1', [
            ...$this->validAdminPayment(),
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'amount' => '110.00',
            'status' => 'paid',
            'payment_method' => 'bank_transfer',
        ])->assertOk();

        $this->assertDatabaseHas('payments', [
            'id' => 1,
            'status' => 'paid',
            'paid_at' => '2026-10-05 10:30:00',
            'payment_method' => 'bank_transfer',
            'reference' => 'REC-2026-0001',
        ]);
    }

    public function test_admin_cannot_create_an_overlapping_billing_period_for_a_student(): void
    {
        $this->seedPaymentData();
        $this->actingAs($this->user(10, 1, 'admin'));

        $this->postJson('/payments', [
            ...$this->validAdminPayment(),
            'period_start' => '2026-10-31',
            'period_end' => '2026-11-30',
        ])->assertUnprocessable()->assertJsonValidationErrors('period_start');

        $this->assertDatabaseCount('payments', 3);
    }

    public function test_admin_cannot_move_a_billing_period_into_another_period(): void
    {
        $this->seedPaymentData();
        $this->actingAs($this->user(10, 1, 'admin'));

        $this->putJson('/payments/2', [
            ...$this->validAdminPayment(),
            'period_start' => '2026-10-15',
            'period_end' => '2026-11-15',
        ])->assertUnprocessable()->assertJsonValidationErrors('period_start');

        $this->assertDatabaseHas('payments', [
            'id' => 2,
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
            'status' => 'due',
        ]);
    }

    public function test_admin_can_create_a_due_period_without_a_paid_date(): void
    {
        $this->seedPaymentData();
        $this->actingAs($this->user(10, 1, 'admin'));

        $response = $this->postJson('/payments', [
            ...$this->validAdminPayment(),
            'period_start' => '2026-12-01',
            'period_end' => '2026-12-31',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('payments', [
            'id' => $response->json('payment_id'),
            'status' => 'due',
            'paid_at' => null,
        ]);
    }

    public function test_admin_can_create_a_due_payment_with_null_notes(): void
    {
        $this->seedPaymentData();
        $this->actingAs($this->user(10, 1, 'admin'));

        $response = $this->postJson('/payments', [
            ...$this->validAdminPayment(),
            'period_start' => '2026-12-01',
            'period_end' => '2026-12-31',
            'notes' => null,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('payments', [
            'id' => $response->json('payment_id'),
            'status' => 'due',
            'notes' => null,
        ]);
    }

    public function test_admin_must_provide_valid_period_amount_and_status(): void
    {
        $this->seedPaymentData();
        $this->actingAs($this->user(10, 1, 'admin'));

        $this->postJson('/payments', [
            ...$this->validAdminPayment(),
            'period_start' => '2026-12-01',
            'period_end' => '2026-11-30',
            'amount' => '-1.00',
            'status' => 'refunded',
            'paid_at' => '2026-11-01 09:00:00',
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'period_end',
            'amount',
            'status',
            'paid_at',
        ]);
    }

    public function test_admin_cannot_submit_payment_date_reference_or_currency(): void
    {
        $this->seedPaymentData();
        $this->actingAs($this->user(10, 1, 'admin'));

        $this->postJson('/payments', [
            ...$this->validAdminPayment(),
            'status' => 'paid',
            'payment_method' => 'cash',
            'paid_at' => '2026-11-02 10:30:00',
            'reference' => 'REC-2026-9999',
            'currency' => 'EUR',
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'paid_at',
            'reference',
            'currency',
        ]);

        $this->assertDatabaseCount('payments', 3);
    }

    public function test_admin_cannot_create_a_waived_payment_without_a_note(): void
    {
        $this->seedPaymentData();
        $this->actingAs($this->user(10, 1, 'admin'));

        $this->postJson('/payments', [
            ...$this->validAdminPayment(),
            'status' => 'waived',
        ])->assertUnprocessable()->assertJsonValidationErrors('notes');
    }

    public function test_secretary_records_a_due_payment_without_changing_its_billing_period(): void
    {
        $this->seedPaymentData();
        $this->actingAs($this->user(12, 1, 'secretary'));
        $this->travelTo('2026-11-03 12:00:00');

        $this->putJson('/payments/2', [
            'year_id' => 1,
            'student_id' => 2,
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'amount' => '1.00',
            'status' => 'paid',
            'payment_method' => 'cash',
        ])->assertOk()->assertJsonPath('updated', true);

        $this->assertDatabaseHas('payments', [
            'id' => 2,
            'student_id' => 1,
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
            'amount' => '125.50',
            'status' => 'paid',
            'paid_at' => '2026-11-03 12:00:00',
            'payment_method' => 'cash',
            'reference' => 'REC-2026-0002',
            'recorded_by' => 12,
        ]);
    }

    public function test_secretary_can_record_a_payment_as_paid_with_null_notes(): void
    {
        $this->seedPaymentData();
        $this->actingAs($this->user(12, 1, 'secretary'));
        $this->travelTo('2026-11-03 12:00:00');

        $this->putJson('/payments/2', [
            'year_id' => 1,
            'status' => 'paid',
            'payment_method' => 'cash',
            'notes' => null,
        ])->assertOk();

        $this->assertDatabaseHas('payments', [
            'id' => 2,
            'status' => 'paid',
            'notes' => null,
        ]);
    }

    public function test_secretary_can_waive_a_due_payment_with_a_note(): void
    {
        $this->seedPaymentData();
        $this->actingAs($this->user(12, 1, 'secretary'));

        $this->putJson('/payments/2', [
            'year_id' => 1,
            'status' => 'waived',
            'notes' => 'Scholarship approved.',
        ])->assertOk();

        $this->assertDatabaseHas('payments', [
            'id' => 2,
            'status' => 'waived',
            'notes' => 'Scholarship approved.',
            'paid_at' => null,
            'recorded_by' => 12,
        ]);
    }

    public function test_secretary_can_update_a_waived_payment_and_refresh_its_note(): void
    {
        $this->seedPaymentData();
        DB::table('payments')->where('id', 2)->update([
            'status' => 'waived',
            'notes' => 'Previous scholarship note.',
            'paid_at' => null,
            'payment_method' => null,
            'reference' => null,
        ]);
        $this->actingAs($this->user(12, 1, 'secretary'));

        $this->putJson('/payments/2', [
            'year_id' => 1,
            'status' => 'waived',
            'notes' => 'Updated scholarship note.',
        ])->assertOk();

        $this->assertDatabaseHas('payments', [
            'id' => 2,
            'status' => 'waived',
            'notes' => 'Updated scholarship note.',
            'recorded_by' => 12,
        ]);
    }

    public function test_secretary_can_mark_a_due_payment_late_without_payment_details(): void
    {
        $this->seedPaymentData();
        $this->actingAs($this->user(12, 1, 'secretary'));

        $this->putJson('/payments/2', [
            'year_id' => 1,
            'status' => 'late',
        ])->assertOk();

        $this->assertDatabaseHas('payments', [
            'id' => 2,
            'status' => 'late',
            'paid_at' => null,
            'payment_method' => null,
            'reference' => null,
            'recorded_by' => 12,
        ]);
    }

    public function test_secretary_sees_unpaid_balances_grouped_by_student(): void
    {
        $this->seedPaymentData();
        DB::table('payments')->insert([
            [
                'id' => 4,
                'student_id' => 2,
                'school_id' => 1,
                'year_id' => 1,
                'recorded_by' => 12,
                'period_start' => '2026-09-01',
                'period_end' => '2026-09-30',
                'amount' => '45.00',
                'currency' => 'MAD',
                'status' => 'due',
                'paid_at' => null,
                'payment_method' => null,
                'reference' => null,
                'notes' => null,
                'created_at' => '2026-09-10 08:00:00',
                'updated_at' => '2026-09-10 08:00:00',
            ],
            [
                'id' => 5,
                'student_id' => 1,
                'school_id' => 1,
                'year_id' => 1,
                'recorded_by' => 12,
                'period_start' => '2026-08-01',
                'period_end' => '2026-08-31',
                'amount' => '30.00',
                'currency' => 'MAD',
                'status' => 'late',
                'paid_at' => null,
                'payment_method' => null,
                'reference' => null,
                'notes' => null,
                'created_at' => '2026-08-10 08:00:00',
                'updated_at' => '2026-08-10 08:00:00',
            ],
        ]);

        $this->actingAs($this->user(12, 1, 'secretary'));

        $response = $this->getJson('/payments/table?year_id=1');

        $response->assertOk();
        $this->assertSame(2, count($response->json('unpaid_balances')));
        $this->assertSame(1, $response->json('unpaid_balances.0.student_id'));
        $this->assertSame(2, $response->json('unpaid_balances.0.period_count'));
        $this->assertSame('155.50', $response->json('unpaid_balances.0.total_amount'));
        $this->assertSame(2, $response->json('unpaid_balances.0.first_payment_id'));
    }

    public function test_secretary_can_record_a_late_payment_without_changing_its_billing_period(): void
    {
        $this->seedPaymentData();
        DB::table('payments')->where('id', 2)->update(['status' => 'late']);
        $this->actingAs($this->user(12, 1, 'secretary'));
        $this->travelTo('2026-11-03 12:00:00');

        $this->putJson('/payments/2', [
            'year_id' => 1,
            'status' => 'paid',
            'payment_method' => 'cash',
        ])->assertOk()->assertJsonPath('updated', true);

        $this->assertDatabaseHas('payments', [
            'id' => 2,
            'student_id' => 1,
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
            'amount' => '125.50',
            'status' => 'paid',
            'paid_at' => '2026-11-03 12:00:00',
            'payment_method' => 'cash',
            'reference' => 'REC-2026-0002',
            'recorded_by' => 12,
        ]);
    }

    public function test_secretary_cannot_waive_a_due_payment_without_a_note(): void
    {
        $this->seedPaymentData();
        $this->actingAs($this->user(12, 1, 'secretary'));

        $this->putJson('/payments/2', [
            'year_id' => 1,
            'status' => 'waived',
        ])->assertUnprocessable()->assertJsonValidationErrors('notes');

        $this->assertDatabaseHas('payments', ['id' => 2, 'status' => 'due']);
    }

    public function test_secretary_cannot_create_a_billing_period(): void
    {
        $this->seedPaymentData();
        $this->actingAs($this->user(12, 1, 'secretary'));

        $this->postJson('/payments', $this->validAdminPayment())
            ->assertForbidden();

        $this->assertDatabaseCount('payments', 3);
    }

    public function test_secretary_cannot_update_a_paid_payment(): void
    {
        $this->seedPaymentData();
        $this->actingAs($this->user(12, 1, 'secretary'));

        $this->putJson('/payments/1', [
            'year_id' => 1,
            'status' => 'late',
        ])->assertNotFound();

        $this->assertDatabaseHas('payments', ['id' => 1, 'status' => 'paid']);
    }

    public function test_payment_writes_return_404_for_another_school_payment(): void
    {
        $this->seedPaymentData();
        $this->actingAs($this->user(10, 1, 'admin'));

        $this->putJson('/payments/3', [
            'year_id' => 1,
            ...$this->validAdminPayment(),
        ])->assertNotFound();
    }

    public function test_unauthenticated_user_receives_401_when_creating_a_payment(): void
    {
        $this->seedPaymentData();

        $this->postJson('/payments', $this->validAdminPayment())->assertUnauthorized();
    }

    public function test_teacher_receives_403_when_creating_a_payment(): void
    {
        $this->seedPaymentData();

        $this->actingAs($this->user(20, 1, 'teacher'));
        $this->postJson('/payments', $this->validAdminPayment())->assertForbidden();
    }

    public function test_payment_writes_reject_a_closed_year(): void
    {
        $this->seedPaymentData();
        $this->actingAs($this->user(10, 1, 'admin'));

        $this->postJson('/payments', [
            ...$this->validAdminPayment(),
            'year_id' => 2,
        ])->assertUnprocessable()->assertJsonValidationErrors('year_id');
    }

    public function test_admin_creates_a_payment_in_an_open_noncurrent_year(): void
    {
        $this->seedPaymentData();
        DB::table('academic_years')->where('id', 2)->update(['is_active' => true]);
        DB::table('students')->insert([
            'id' => 4,
            'school_id' => 1,
            'year_id' => 2,
            'full_name' => 'Former Student',
        ]);
        $this->actingAs($this->user(10, 1, 'admin'));

        $response = $this->postJson('/payments', [
            ...$this->validAdminPayment(),
            'year_id' => 2,
            'student_id' => 4,
            'period_start' => '2025-11-01',
            'period_end' => '2025-11-30',
        ]);

        $response->assertCreated()->assertJsonStructure(['payment_id']);
        $this->assertDatabaseHas('payments', [
            'id' => $response->json('payment_id'),
            'student_id' => 4,
            'year_id' => 2,
            'period_start' => '2025-11-01',
            'period_end' => '2025-11-30',
        ]);
    }

    public function test_receipt_sequence_and_currency_are_scoped_to_the_school(): void
    {
        $this->seedPaymentData();
        $this->actingAs($this->user(30, 2, 'admin'));
        $this->travelTo('2026-11-03 12:00:00');

        $response = $this->postJson('/payments', [
            ...$this->validAdminPayment(),
            'year_id' => 3,
            'student_id' => 3,
            'status' => 'paid',
            'payment_method' => 'cash',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('payments', [
            'id' => $response->json('payment_id'),
            'school_id' => 2,
            'year_id' => 3,
            'currency' => 'EUR',
            'paid_at' => '2026-11-03 12:00:00',
            'reference' => 'REC-2026-0001',
        ]);
    }

    public function test_payment_table_suggests_the_students_last_used_payment_method(): void
    {
        $this->seedPaymentData();
        DB::table('payments')->insert([
            'id' => 4,
            'student_id' => 1,
            'school_id' => 1,
            'year_id' => 1,
            'recorded_by' => 12,
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'amount' => '100.00',
            'currency' => 'MAD',
            'status' => 'paid',
            'paid_at' => '2026-11-02 10:30:00',
            'payment_method' => 'bank_transfer',
            'reference' => 'REC-2026-0020',
        ]);
        $this->actingAs($this->user(12, 1, 'secretary'));

        $this->getJson('/payments/table?year_id=1')
            ->assertOk()
            ->assertJsonPath('students.0.last_payment_method', 'bank_transfer')
            ->assertJsonPath('students.1.last_payment_method', null);
    }

    /**
     * @return array<string, int|string>
     */
    private function validAdminPayment(): array
    {
        return [
            'year_id' => 1,
            'student_id' => 1,
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
            'amount' => '125.50',
            'status' => 'due',
        ];
    }

    private function seedPaymentData(): void
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
            ['id' => 1, 'school_id' => 1, 'year_id' => 1, 'full_name' => 'Mariam Ali'],
            ['id' => 2, 'school_id' => 1, 'year_id' => 1, 'full_name' => 'Zayd Ali'],
            ['id' => 3, 'school_id' => 2, 'year_id' => 3, 'full_name' => 'Other School Student'],
        ]);
        $paymentDefaults = [
            'recorded_by' => null,
            'paid_at' => null,
            'payment_method' => null,
            'reference' => null,
            'notes' => null,
            'created_at' => null,
            'updated_at' => null,
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
                'amount' => '100.00',
                'currency' => 'MAD',
                'status' => 'paid',
                'paid_at' => '2026-10-05 10:30:00',
                'payment_method' => 'cash',
                'reference' => 'REC-2026-0001',
            ],
            [
                'id' => 2,
                'student_id' => 1,
                'school_id' => 1,
                'year_id' => 1,
                'recorded_by' => 10,
                'period_start' => '2026-11-01',
                'period_end' => '2026-11-30',
                'amount' => '125.50',
                'currency' => 'MAD',
                'status' => 'due',
            ],
            [
                'id' => 3,
                'student_id' => 3,
                'school_id' => 2,
                'year_id' => 3,
                'recorded_by' => 30,
                'period_start' => '2026-10-01',
                'period_end' => '2026-10-31',
                'amount' => '90.00',
                'currency' => 'EUR',
                'status' => 'due',
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
