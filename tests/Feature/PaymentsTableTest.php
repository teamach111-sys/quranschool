<?php

namespace Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class PaymentsTableTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->char('currency', 3);
            $table->string('name_fr');
            $table->string('name_ar');
            $table->json('payment_methods')->nullable();
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
            ->assertJsonPath('can_export_payments', true)
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
            ->assertJsonCount(4, 'payments');

        $this->getJson('/payments/table?status=late')
            ->assertOk()
            ->assertJsonCount(1, 'payments')
            ->assertJsonPath('payments.0.guardian_phone', '0600000002');

        $this->getJson('/payments/table?status=waived')
            ->assertOk()
            ->assertJsonCount(1, 'payments')
            ->assertJsonPath('payments.0.status', 'waived');

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

    public function test_admin_downloads_a_school_scoped_paid_payment_receipt_and_audits_the_export(): void
    {
        $this->seedPayments();
        DB::table('schools')->where('id', 1)->update([
            'name_fr' => 'École de test',
            'name_ar' => 'مدرسة تجريبية',
        ]);
        DB::table('users')->where('id', 10)->update(['full_name' => 'Admin User']);
        $this->actingAs($this->user(10, 1, 'admin'));

        $response = $this->get('/payments/1/receipt?year_id=1&lang=fr');

        $response->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('content-disposition', 'attachment; filename="payment-receipt-1.pdf"');
        $pdfContents = $response->getContent();
        $this->assertStringStartsWith('%PDF-', $pdfContents);
        $this->assertSame(
            1,
            preg_match('/\/MediaBox\s*\[\s*0\s+0\s+[\d.]+\s+([\d.]+)\s*\]/', $pdfContents, $pageDimensions),
        );
        $this->assertLessThan(842, (float) $pageDimensions[1]);
        $event = DB::table('audit_events')->first();
        $this->assertDatabaseCount('audit_events', 1);
        $this->assertNotNull($event);
        $this->assertSame(1, (int) $event->school_id);
        $this->assertSame(1, (int) $event->year_id);
        $this->assertSame(10, (int) $event->actor_user_id);
        $this->assertSame('Admin User', $event->actor_name);
        $this->assertSame('exported', $event->action_type);
        $this->assertSame('payment', $event->entity_type);
        $this->assertSame(1, (int) $event->entity_id);
        $this->assertSame('payment.receipt_exported', $event->summary_key);
        $this->assertSame(
            ['format' => 'pdf', 'page' => 'payments', 'record_count' => 1],
            json_decode($event->changes, true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function test_secretary_can_download_an_arabic_receipt_but_unpaid_and_cross_school_records_are_hidden(): void
    {
        $this->seedPayments();
        DB::table('schools')->where('id', 1)->update([
            'name_fr' => 'Test School',
            'name_ar' => 'مدرسة تجريبية',
        ]);
        $this->actingAs($this->user(12, 1, 'secretary'));

        $this->get('/payments/1/receipt?year_id=1&lang=ar')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('content-disposition', 'attachment; filename="payment-receipt-1.pdf"');
        $this->assertDatabaseCount('audit_events', 1);

        $this->get('/payments/2/receipt?year_id=1&lang=fr')->assertNotFound();
        $this->get('/payments/6/receipt?year_id=1&lang=fr')->assertNotFound();
        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_teacher_cannot_download_payment_receipts(): void
    {
        $this->seedPayments();
        $this->actingAs($this->user(20, 1, 'teacher'));

        $this->get('/payments/1/receipt?year_id=1&lang=fr')->assertForbidden();
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_admin_exports_filtered_payments_as_a_safe_audited_excel_workbook(): void
    {
        $this->seedPayments();
        DB::table('schools')->where('id', 1)->update(['name_fr' => 'École de test']);
        DB::table('payments')->where('id', 1)->update([
            'reference' => '=HYPERLINK("https://example.invalid","Open")',
        ]);
        $this->actingAs($this->user(10, 1, 'admin'));

        $response = $this->get('/payments/export?year_id=1&student_id=1&status=paid&period_date=2026-10-15&search=Mariam&lang=fr');

        $response->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->assertHeader('content-disposition', "attachment; filename*=UTF-8''paiements.xlsx")
            ->assertHeader('cache-control', 'no-store, private')
            ->assertHeader('X-Export-Record-Count', '1');
        $content = $response->getContent();
        $rows = $this->readExcelRows($content);
        $this->assertSame('École de test', $rows[1][1]);
        $this->assertSame('2026-2027', $rows[2][1]);
        $this->assertSame('Élève', $rows[7][0]);
        $this->assertSame('Numéro d’élève', $rows[7][1]);
        $this->assertSame('Mariam Ali', $rows[8][0]);
        $this->assertSame('Payé', $rows[8][6]);
        $this->assertSame('=HYPERLINK("https://example.invalid","Open")', $rows[8][9]);
        $this->assertNotContains('Other School Student', array_column($rows, 0));
        $this->assertNotContains('Zayd Ali', array_column($rows, 0));

        $event = DB::table('audit_events')->first();
        $this->assertDatabaseCount('audit_events', 1);
        $this->assertNotNull($event);
        $this->assertSame(1, (int) $event->school_id);
        $this->assertSame(1, (int) $event->year_id);
        $this->assertSame(10, (int) $event->actor_user_id);
        $this->assertSame('Admin User', $event->actor_name);
        $this->assertSame('exported', $event->action_type);
        $this->assertSame('payment', $event->entity_type);
        $this->assertNull($event->entity_id);
        $this->assertSame('payment.table_exported', $event->summary_key);
        $this->assertSame([
            'format' => 'xlsx',
            'page' => 'payments',
            'record_count' => 1,
            'filters' => [
                'role_scope' => 'all_payments',
                'student_id' => '1',
                'payment_id' => null,
                'status' => 'paid',
                'period_date' => '2026-10-15',
                'search_applied' => true,
                'sort_by' => null,
                'sort_direction' => null,
            ],
        ], json_decode($event->changes, true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_secretary_excel_export_contains_only_operational_payments_and_contact_data(): void
    {
        $this->seedPayments();
        $this->actingAs($this->user(12, 1, 'secretary'));

        $response = $this->get('/payments/export?year_id=1&lang=fr');

        $response->assertOk();
        $rows = $this->readExcelRows($response->getContent());
        $this->assertSame('Téléphone du responsable', $rows[7][2]);
        $this->assertContains('0600000001', array_column($rows, 2));
        $this->assertNotContains('REC-001', array_column($rows, 10));
        $this->assertContains('À échéance', array_column($rows, 7));
        $this->assertContains('En retard', array_column($rows, 7));
        $this->assertContains('Exonéré', array_column($rows, 7));
        $event = DB::table('audit_events')->first();
        $this->assertDatabaseCount('audit_events', 1);
        $this->assertSame(3, json_decode($event->changes, true, 512, JSON_THROW_ON_ERROR)['record_count']);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => 12,
            'summary_key' => 'payment.table_exported',
        ]);
    }

    public function test_payment_excel_export_uses_arabic_report_and_status_labels_when_requested(): void
    {
        $this->seedPayments();
        $this->actingAs($this->user(12, 1, 'secretary'));

        $response = $this->get('/payments/export?year_id=1&lang=ar');

        $response->assertOk();
        $rows = $this->readExcelRows($response->getContent());
        $this->assertSame('تقرير المدفوعات', $rows[0][0]);
        $this->assertSame('هاتف ولي الأمر', $rows[7][2]);
        $this->assertContains('مستحق', array_column($rows, 7));
    }

    public function test_payment_excel_export_uses_the_requested_sort_order(): void
    {
        $this->seedPayments();
        $this->actingAs($this->user(10, 1, 'admin'));

        $response = $this->get('/payments/export?year_id=1&sort_by=student_name&sort_direction=desc');

        $response->assertOk()
            ->assertHeader('X-Export-Record-Count', '4')
            ->assertHeader('content-disposition', "attachment; filename*=UTF-8''paiements.xlsx");
        $rows = $this->readExcelRows($response->getContent());
        $this->assertSame('Zayd Ali', $rows[8][0]);
    }

    public function test_payment_csv_export_is_safe_localized_and_audited(): void
    {
        $this->seedPayments();
        DB::table('payments')->where('id', 1)->update([
            'reference' => '=HYPERLINK("https://example.invalid","Open")',
        ]);
        $this->actingAs($this->user(10, 1, 'admin'));

        $response = $this->get('/payments/export?year_id=1&format=csv&lang=fr');

        $response->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertHeader('content-disposition', "attachment; filename*=UTF-8''paiements.csv")
            ->assertHeader('X-Export-Record-Count', '4');
        $this->assertStringStartsWith("\xEF\xBB\xBF", $response->getContent());
        $this->assertStringContainsString('Élève', $response->getContent());
        $this->assertStringContainsString("'=HYPERLINK", $response->getContent());
        $this->assertDatabaseHas('audit_events', [
            'summary_key' => 'payment.table_exported',
        ]);
        $event = DB::table('audit_events')->first();
        $this->assertSame('csv', json_decode($event->changes, true, 512, JSON_THROW_ON_ERROR)['format']);
    }

    public function test_payment_pdf_export_returns_a_localized_audited_report(): void
    {
        $this->seedPayments();
        $this->actingAs($this->user(12, 1, 'secretary'));

        $response = $this->get('/payments/export?year_id=1&format=pdf&lang=ar');

        $response->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('content-disposition', "attachment; filename*=UTF-8''".rawurlencode('المدفوعات.pdf'))
            ->assertHeader('X-Export-Record-Count', '3');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $event = DB::table('audit_events')->first();
        $this->assertSame('pdf', json_decode($event->changes, true, 512, JSON_THROW_ON_ERROR)['format']);
    }

    public function test_payment_excel_export_rejects_unauthorized_users_invalid_sort_and_empty_results(): void
    {
        $this->seedPayments();

        $this->getJson('/payments/export')->assertUnauthorized();

        $this->actingAs($this->user(20, 1, 'teacher'));
        $this->get('/payments/export')->assertForbidden();

        $this->actingAs($this->user(10, 1, 'admin'));
        $this->getJson('/payments/export?year_id=1&format=xml')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['format']);
        $this->getJson('/payments/export?year_id=1&sort_by=payments.id')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sort_by']);
        $this->getJson('/payments/export?year_id=1&student_id=4')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['student_id']);
        $this->getJson('/payments/export?year_id=3')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['year_id']);
        $this->getJson('/payments/export?year_id=1&student_id=1&period_date=2027-01-01')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Aucun paiement ne correspond à cet export.');
        $this->getJson('/payments/export?year_id=1&search=%25')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Aucun paiement ne correspond à cet export.');
        $this->assertDatabaseCount('audit_events', 0);
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
            ['id' => 1, 'currency' => 'MAD', 'name_fr' => 'Test School', 'name_ar' => 'مدرسة الاختبار'],
            ['id' => 2, 'currency' => 'EUR', 'name_fr' => 'Other School', 'name_ar' => 'مدرسة أخرى'],
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
                'student_id' => 1,
                'school_id' => 1,
                'year_id' => 1,
                'recorded_by' => 12,
                'period_start' => '2026-08-01',
                'period_end' => '2026-08-31',
                'amount' => '35.00',
                'currency' => 'MAD',
                'status' => 'waived',
                'notes' => 'Family exemption',
            ],
            [
                'id' => 5,
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
                'id' => 6,
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

    /**
     * @return array<int, array<int, mixed>>
     */
    private function readExcelRows(string $contents): array
    {
        $temporaryFile = tempnam(sys_get_temp_dir(), 'quranschool-test-payments-');
        if ($temporaryFile === false) {
            throw new \RuntimeException('Unable to create the temporary Excel test file.');
        }

        try {
            if (file_put_contents($temporaryFile, $contents) === false) {
                throw new \RuntimeException('Unable to write the temporary Excel test file.');
            }

            return IOFactory::load($temporaryFile)->getActiveSheet()->toArray();
        } finally {
            if (is_file($temporaryFile) && ! unlink($temporaryFile)) {
                throw new \RuntimeException('Unable to clean up the temporary Excel test file.');
            }
        }
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
