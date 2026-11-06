<?php

namespace Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PaymentPeriodGenerationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->decimal('monthly_fee', 10, 2)->nullable();
            $table->char('currency', 3);
        });

        Schema::create('academic_years', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->string('year_label');
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_current');
            $table->boolean('is_active');
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->string('full_name');
        });

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

        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('year_id');
            $table->string('name');
            $table->string('level');
            $table->boolean('is_active');
        });

        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('year_id');
            $table->string('student_number')->nullable();
            $table->string('full_name');
            $table->unsignedBigInteger('room_id')->nullable();
            $table->date('enrollment_date');
            $table->string('status');
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
            $table->unique(['student_id', 'year_id', 'period_start', 'period_end']);
        });
    }

    public function test_options_include_only_active_students_from_the_current_school_year(): void
    {
        $this->seedGenerationData();
        $this->actingAs($this->user(10, 1, 'admin'));

        $this->getJson('/payments/generation-options?year_id=1')
            ->assertOk()
            ->assertJsonPath('monthly_fee', '125.50')
            ->assertJsonPath('currency', 'MAD')
            ->assertJsonPath('year_label', '2026-2027')
            ->assertJsonCount(3, 'students')
            ->assertJsonPath('students.0.id', 1)
            ->assertJsonPath('students.0.room_name', 'Groupe A')
            ->assertJsonPath('students.0.level', 'beginner')
            ->assertJsonCount(2, 'rooms')
            ->assertJsonMissing(['name' => 'Paused Student'])
            ->assertJsonMissing(['name' => 'Other School Student']);
    }

    public function test_preview_counts_due_existing_and_not_yet_enrolled_months(): void
    {
        $this->seedGenerationData();
        $this->actingAs($this->user(10, 1, 'admin'));

        $this->postJson('/payments/generation-preview', $this->generationRequest())
            ->assertOk()
            ->assertJsonPath('create_count', 4)
            ->assertJsonPath('existing_count', 1)
            ->assertJsonPath('overlap_count', 0)
            ->assertJsonPath('not_enrolled_count', 1)
            ->assertJsonPath('total_amount', '502.00')
            ->assertJsonPath('students.2.create_count', 2)
            ->assertJsonPath('students.1.not_enrolled_count', 1);

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_mid_month_enrollment_is_charged_for_the_full_enrollment_month(): void
    {
        $this->seedGenerationData();
        $this->actingAs($this->user(10, 1, 'admin'));

        $this->postJson('/payments/generation-preview', $this->generationRequest([2], '2026-10', '2026-10'))
            ->assertOk()
            ->assertJsonPath('create_count', 1)
            ->assertJsonPath('total_amount', '125.50')
            ->assertJsonPath('students.0.create_count', 1);
    }

    public function test_preview_skips_a_period_that_overlaps_an_existing_payment(): void
    {
        $this->seedGenerationData();
        DB::table('payments')->insert([
            'student_id' => 2,
            'school_id' => 1,
            'year_id' => 1,
            'period_start' => '2026-10-20',
            'period_end' => '2026-10-31',
            'amount' => '50.00',
            'currency' => 'MAD',
            'status' => 'paid',
        ]);
        $this->actingAs($this->user(10, 1, 'admin'));

        $this->postJson('/payments/generation-preview', $this->generationRequest([2], '2026-10', '2026-10'))
            ->assertOk()
            ->assertJsonPath('create_count', 0)
            ->assertJsonPath('overlap_count', 1)
            ->assertJsonPath('total_amount', '0.00');
    }

    public function test_secretary_generation_is_due_only_attributed_and_idempotent(): void
    {
        $this->seedGenerationData();
        $this->actingAs($this->user(12, 1, 'secretary'));
        $request = $this->generationRequest();
        $preview = $this->postJson('/payments/generation-preview', $request)
            ->assertOk()
            ->assertJsonPath('create_count', 4);

        $this->postJson('/payments/generate', [
            ...$request,
            'preview_token' => $preview->json('preview_token'),
        ])
            ->assertOk()
            ->assertJsonPath('created_count', 4)
            ->assertJsonPath('existing_count', 1)
            ->assertJsonPath('not_enrolled_count', 1)
            ->assertJsonPath('total_amount', '502.00');

        $this->assertDatabaseCount('payments', 5);
        $this->assertSame(
            4,
            DB::table('payments')->where('recorded_by', 12)->where('status', 'due')->count(),
        );
        $this->assertDatabaseHas('payments', [
            'student_id' => 2,
            'school_id' => 1,
            'year_id' => 1,
            'recorded_by' => 12,
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'amount' => '125.50',
            'currency' => 'MAD',
            'status' => 'due',
            'paid_at' => null,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'summary_key' => 'payment.periods_generated',
            'entity_type' => 'payment',
            'actor_user_id' => 12,
        ]);

        $secondPreview = $this->postJson('/payments/generation-preview', $request)
            ->assertOk()
            ->assertJsonPath('create_count', 0);

        $this->postJson('/payments/generate', [
            ...$request,
            'preview_token' => $secondPreview->json('preview_token'),
        ])
            ->assertOk()
            ->assertJsonPath('created_count', 0)
            ->assertJsonPath('existing_count', 5)
            ->assertJsonPath('total_amount', '0.00');

        $this->assertDatabaseCount('payments', 5);
    }

    public function test_october_generation_creates_18_of_22_active_students_and_skips_four_existing_periods(): void
    {
        $this->seedGenerationData();
        DB::table('students')->where('id', 3)->update(['enrollment_date' => '2026-09-01']);

        $additionalStudents = [];
        for ($studentId = 7; $studentId <= 25; $studentId++) {
            $additionalStudents[] = [
                'id' => $studentId,
                'school_id' => 1,
                'year_id' => 1,
                'student_number' => 'S-'.str_pad((string) $studentId, 3, '0', STR_PAD_LEFT),
                'full_name' => 'Student '.$studentId,
                'room_id' => null,
                'enrollment_date' => '2026-09-01',
                'status' => 'active',
            ];
        }
        DB::table('students')->insert($additionalStudents);
        DB::table('payments')->insert([
            [
                'student_id' => 7,
                'school_id' => 1,
                'year_id' => 1,
                'period_start' => '2026-10-01',
                'period_end' => '2026-10-31',
                'amount' => '125.50',
                'currency' => 'MAD',
                'status' => 'paid',
            ],
            [
                'student_id' => 8,
                'school_id' => 1,
                'year_id' => 1,
                'period_start' => '2026-10-01',
                'period_end' => '2026-10-31',
                'amount' => '125.50',
                'currency' => 'MAD',
                'status' => 'paid',
            ],
            [
                'student_id' => 9,
                'school_id' => 1,
                'year_id' => 1,
                'period_start' => '2026-10-01',
                'period_end' => '2026-10-31',
                'amount' => '125.50',
                'currency' => 'MAD',
                'status' => 'paid',
            ],
        ]);
        $studentIds = [1, 2, 3, ...range(7, 25)];
        $request = $this->generationRequest($studentIds, '2026-10', '2026-10');
        $this->actingAs($this->user(10, 1, 'admin'));

        $preview = $this->postJson('/payments/generation-preview', $request)
            ->assertOk()
            ->assertJsonPath('create_count', 18)
            ->assertJsonPath('existing_count', 4)
            ->assertJsonPath('total_amount', '2259.00');

        $this->postJson('/payments/generate', [
            ...$request,
            'preview_token' => $preview->json('preview_token'),
        ])
            ->assertOk()
            ->assertJsonPath('created_count', 18)
            ->assertJsonPath('existing_count', 4);

        $this->assertDatabaseCount('payments', 22);
        $this->assertSame(
            18,
            DB::table('payments')
                ->where('recorded_by', 10)
                ->whereNotNull('created_at')
                ->count(),
        );
    }

    public function test_generation_rejects_students_from_another_school_or_year(): void
    {
        $this->seedGenerationData();
        $this->actingAs($this->user(10, 1, 'admin'));

        $this->postJson('/payments/generate', $this->generationRequest([5]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('student_ids.0');

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_generation_rejects_a_noncurrent_academic_year(): void
    {
        $this->seedGenerationData();
        $this->actingAs($this->user(10, 1, 'admin'));

        $this->postJson('/payments/generate', $this->generationRequest([6], '2025-10', '2025-10', 2))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('year_id');

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_generation_rejects_months_outside_the_academic_year(): void
    {
        $this->seedGenerationData();
        $this->actingAs($this->user(10, 1, 'admin'));

        $this->postJson('/payments/generate', $this->generationRequest([1], '2027-07', '2027-07'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to_month');

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_generation_requires_a_configured_school_monthly_fee(): void
    {
        $this->seedGenerationData();
        DB::table('schools')->where('id', 1)->update(['monthly_fee' => null]);
        $this->actingAs($this->user(10, 1, 'admin'));

        $this->postJson('/payments/generation-preview', $this->generationRequest())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('monthly_fee');

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_generation_rejects_a_preview_that_changed_before_confirmation(): void
    {
        $this->seedGenerationData();
        $this->actingAs($this->user(10, 1, 'admin'));
        $request = $this->generationRequest();
        $preview = $this->postJson('/payments/generation-preview', $request)->assertOk();
        DB::table('schools')->where('id', 1)->update(['monthly_fee' => '130.00']);

        $this->postJson('/payments/generate', [
            ...$request,
            'preview_token' => $preview->json('preview_token'),
        ])->assertUnprocessable()->assertJsonValidationErrors('preview_token');

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_generation_requires_a_review_token(): void
    {
        $this->seedGenerationData();
        $this->actingAs($this->user(10, 1, 'admin'));

        $this->postJson('/payments/generate', $this->generationRequest())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('preview_token');

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_teacher_cannot_generate_payment_periods(): void
    {
        $this->seedGenerationData();
        $this->actingAs($this->user(20, 1, 'teacher'));

        $this->postJson('/payments/generate', $this->generationRequest())->assertForbidden();

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_unauthenticated_user_cannot_load_generation_options(): void
    {
        $this->seedGenerationData();

        $this->getJson('/payments/generation-options?year_id=1')->assertUnauthorized();
    }

    /**
     * @param  array<int>  $studentIds
     * @return array<string, int|string|array<int>>
     */
    private function generationRequest(
        array $studentIds = [1, 2, 3],
        string $fromMonth = '2026-10',
        string $toMonth = '2026-11',
        int $yearId = 1,
    ): array {
        return [
            'year_id' => $yearId,
            'from_month' => $fromMonth,
            'to_month' => $toMonth,
            'student_ids' => $studentIds,
        ];
    }

    private function seedGenerationData(): void
    {
        DB::table('schools')->insert([
            ['id' => 1, 'monthly_fee' => '125.50', 'currency' => 'MAD'],
            ['id' => 2, 'monthly_fee' => '90.00', 'currency' => 'EUR'],
        ]);
        DB::table('academic_years')->insert([
            [
                'id' => 1,
                'school_id' => 1,
                'year_label' => '2026-2027',
                'start_date' => '2026-09-01',
                'end_date' => '2027-06-30',
                'is_current' => true,
                'is_active' => true,
            ],
            [
                'id' => 2,
                'school_id' => 1,
                'year_label' => '2025-2026',
                'start_date' => '2025-09-01',
                'end_date' => '2026-06-30',
                'is_current' => false,
                'is_active' => true,
            ],
            [
                'id' => 3,
                'school_id' => 2,
                'year_label' => '2026-2027',
                'start_date' => '2026-09-01',
                'end_date' => '2027-06-30',
                'is_current' => true,
                'is_active' => true,
            ],
        ]);
        DB::table('users')->insert([
            ['id' => 10, 'school_id' => 1, 'full_name' => 'Admin User'],
            ['id' => 12, 'school_id' => 1, 'full_name' => 'Secretary User'],
            ['id' => 20, 'school_id' => 1, 'full_name' => 'Teacher User'],
        ]);
        DB::table('rooms')->insert([
            ['id' => 1, 'school_id' => 1, 'year_id' => 1, 'name' => 'Groupe A', 'level' => 'beginner', 'is_active' => true],
            ['id' => 2, 'school_id' => 1, 'year_id' => 1, 'name' => 'Groupe B', 'level' => 'intermediate', 'is_active' => true],
            ['id' => 3, 'school_id' => 2, 'year_id' => 3, 'name' => 'Other Group', 'level' => 'advanced', 'is_active' => true],
        ]);
        DB::table('students')->insert([
            [
                'id' => 1,
                'school_id' => 1,
                'year_id' => 1,
                'student_number' => 'S-001',
                'full_name' => 'Mariam Ali',
                'room_id' => 1,
                'enrollment_date' => '2026-09-01',
                'status' => 'active',
            ],
            [
                'id' => 2,
                'school_id' => 1,
                'year_id' => 1,
                'student_number' => 'S-002',
                'full_name' => 'Zayd Ali',
                'room_id' => 1,
                'enrollment_date' => '2026-10-15',
                'status' => 'active',
            ],
            [
                'id' => 3,
                'school_id' => 1,
                'year_id' => 1,
                'student_number' => 'S-003',
                'full_name' => 'Sara Ali',
                'room_id' => 2,
                'enrollment_date' => '2026-11-15',
                'status' => 'active',
            ],
            [
                'id' => 4,
                'school_id' => 1,
                'year_id' => 1,
                'student_number' => 'S-004',
                'full_name' => 'Paused Student',
                'room_id' => 1,
                'enrollment_date' => '2026-09-01',
                'status' => 'paused',
            ],
            [
                'id' => 5,
                'school_id' => 2,
                'year_id' => 3,
                'student_number' => 'S-005',
                'full_name' => 'Other School Student',
                'room_id' => 3,
                'enrollment_date' => '2026-09-01',
                'status' => 'active',
            ],
            [
                'id' => 6,
                'school_id' => 1,
                'year_id' => 2,
                'student_number' => 'S-006',
                'full_name' => 'Former Student',
                'room_id' => null,
                'enrollment_date' => '2025-09-01',
                'status' => 'active',
            ],
        ]);
        DB::table('payments')->insert([
            'student_id' => 1,
            'school_id' => 1,
            'year_id' => 1,
            'recorded_by' => 10,
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'amount' => '125.50',
            'currency' => 'MAD',
            'status' => 'due',
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
