<?php

namespace App\Http\Controllers;

use App\Support\AuditEventLogger;
use App\Support\PaymentMethods;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

class SchoolSettingsController extends Controller
{
    public function receiptTemplate(Request $request): Response
    {
        $schoolId = $this->schoolId();
        $language = $request->validate([
            'lang' => ['nullable', Rule::in(['fr', 'ar'])],
        ])['lang'] ?? data_get(Auth::user(), 'language', 'fr');
        $language = in_array($language, ['fr', 'ar'], true) ? $language : 'fr';
        $school = DB::table('schools')
            ->where('id', $schoolId)
            ->first(['name_fr', 'name_ar']);
        abort_unless($school, 404);

        $labels = $language === 'ar'
            ? [
                'title' => 'إيصال الدفع',
                'paymentConfirmation' => 'يؤكد هذا المستند دفعة مسجلة فقط.',
                'student' => 'الطالب',
                'academicYear' => 'السنة الدراسية',
                'billingPeriod' => 'فترة الاستحقاق',
                'amountReceived' => 'المبلغ المستلم',
                'paidAt' => 'تاريخ الدفع',
                'paymentMethod' => 'طريقة الدفع',
                'reference' => 'المرجع',
                'generatedAt' => 'تاريخ الإنشاء',
            ]
            : [
                'title' => 'Reçu de paiement',
                'paymentConfirmation' => 'Ce document confirme uniquement un paiement enregistré.',
                'student' => 'Élève',
                'academicYear' => 'Année scolaire',
                'billingPeriod' => 'Période de paiement',
                'amountReceived' => 'Montant reçu',
                'paidAt' => 'Payé le',
                'paymentMethod' => 'Mode de paiement',
                'reference' => 'Référence',
                'generatedAt' => 'Généré le',
            ];
        $dots = '................................';
        $schoolName = $language === 'ar' ? $school->name_ar : $school->name_fr;
        $temporaryDirectory = storage_path('framework/cache/mpdf');
        File::ensureDirectoryExists($temporaryDirectory);
        $pdfOptions = [
            'mode' => 'utf-8',
            'format' => [160, 400],
            'default_font' => 'dejavusans',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'margin_left' => 0,
            'margin_right' => 0,
            'margin_top' => 0,
            'margin_bottom' => 0,
            'tempDir' => $temporaryDirectory,
        ];
        $receiptHtml = view('exports.payment-receipt', [
            'language' => $language,
            'labels' => $labels,
            'isTemplate' => true,
            'schoolName' => $schoolName,
            'yearLabel' => $dots,
            'studentName' => $dots,
            'periodStart' => $dots,
            'periodEnd' => $dots,
            'amount' => $dots,
            'currency' => '',
            'paidAt' => $dots,
            'paymentMethod' => $dots,
            'reference' => $dots,
            'generatedAt' => $dots,
        ])->render();

        $pdf = new Mpdf($pdfOptions);
        $pdf->WriteHTML($receiptHtml);
        $pageSide = max(160, (int) ceil($pdf->y + 4));
        $pdfOptions['format'] = [$pageSide, $pageSide];
        $pdf = new Mpdf($pdfOptions);
        $pdf->SetTitle($labels['title']);
        $pdf->WriteHTML($receiptHtml);

        return response($pdf->Output('', Destination::STRING_RETURN), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="payment-receipt-template.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function show(): JsonResponse
    {
        $school = DB::table('schools')
            ->where('id', $this->schoolId())
            ->first(['name_fr', 'name_ar', 'address', 'phone', 'email', 'currency', 'monthly_fee', 'payment_methods', 'student_number_prefix', 'student_number_use_dash']);

        abort_unless($school, 404);

        $school->payment_methods = PaymentMethods::fromJson($school->payment_methods);
        $school->student_number_use_dash = (bool) $school->student_number_use_dash;
        if ($school->monthly_fee !== null) {
            $school->monthly_fee = number_format((float) $school->monthly_fee, 2, '.', '');
        }

        return response()->json(['school' => $school]);
    }

    public function update(Request $request): JsonResponse
    {
        $request->merge([
            'currency' => strtoupper((string) $request->input('currency')),
        ]);
        if (is_array($request->input('payment_methods'))) {
            $request->merge([
                'payment_methods' => array_map('trim', $request->input('payment_methods')),
            ]);
        }
        if ($request->has('student_number_prefix')) {
            $request->merge([
                'student_number_prefix' => trim((string) $request->input('student_number_prefix')),
            ]);
        }

        $validated = $request->validate([
            'name_fr' => ['required', 'string', 'max:150'],
            'name_ar' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:190'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'monthly_fee' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'payment_methods' => ['sometimes', 'required', 'array', 'min:1', 'max:20'],
            'payment_methods.*' => ['required', 'string', 'max:40', 'distinct'],
            'student_number_prefix' => ['nullable', 'string', 'max:20'],
            'student_number_use_dash' => ['sometimes', 'boolean'],
        ]);
        if (isset($validated['payment_methods'])) {
            $validated['payment_methods'] = json_encode(
                array_values(array_map('trim', $validated['payment_methods'])),
                JSON_THROW_ON_ERROR,
            );
        }
        if (array_key_exists('student_number_prefix', $validated) && $validated['student_number_prefix'] === '') {
            $validated['student_number_prefix'] = null;
        }

        $schoolId = $this->schoolId();
        DB::transaction(function () use ($validated, $schoolId) {
            $before = DB::table('schools')
                ->where('id', $schoolId)
                ->lockForUpdate()
                ->first(['name_fr', 'name_ar', 'address', 'phone', 'email', 'currency', 'monthly_fee', 'payment_methods', 'student_number_prefix', 'student_number_use_dash']);
            abort_unless($before, 404);

            $beforeValues = (array) $before;
            $beforeValues['payment_methods'] = PaymentMethods::fromJson($before->payment_methods);
            $beforeValues['student_number_use_dash'] = (bool) $before->student_number_use_dash;
            $beforeValues['monthly_fee'] = $before->monthly_fee === null
                ? null
                : number_format((float) $before->monthly_fee, 2, '.', '');

            DB::table('schools')
                ->where('id', $schoolId)
                ->update([...$validated, 'updated_at' => now()]);
            $after = DB::table('schools')
                ->where('id', $schoolId)
                ->first(['name_fr', 'name_ar', 'address', 'phone', 'email', 'currency', 'monthly_fee', 'payment_methods', 'student_number_prefix', 'student_number_use_dash']);
            abort_unless($after, 404);
            $afterValues = (array) $after;
            $afterValues['payment_methods'] = PaymentMethods::fromJson($after->payment_methods);
            $afterValues['student_number_use_dash'] = (bool) $after->student_number_use_dash;
            $afterValues['monthly_fee'] = $after->monthly_fee === null
                ? null
                : number_format((float) $after->monthly_fee, 2, '.', '');

            AuditEventLogger::record(
                $schoolId,
                null,
                'school',
                $schoolId,
                'updated',
                'school.settings_updated',
                $beforeValues,
                $afterValues,
            );
        });

        return $this->show();
    }

    public function exportData(): Response
    {
        $schoolId = $this->schoolId();

        $tables = [
            'schools' => fn () => DB::table('schools')->where('id', $schoolId)->get(),
            'roles' => fn () => DB::table('roles')->get(),
            'academic_years' => fn () => DB::table('academic_years')->where('school_id', $schoolId)->get(),
            'users' => fn () => DB::table('users')->where('school_id', $schoolId)->get(),
            'password_recovery_keys' => fn () => DB::table('password_recovery_keys')
                ->whereIn('user_id', DB::table('users')->where('school_id', $schoolId)->pluck('id'))
                ->get(),
            'rooms' => fn () => DB::table('rooms')->where('school_id', $schoolId)->get(),
            'students' => fn () => DB::table('students')->where('school_id', $schoolId)->get(),
            'room_teachers' => fn () => DB::table('room_teachers')->where('school_id', $schoolId)->get(),
            'schedules' => fn () => DB::table('schedules')->where('school_id', $schoolId)->get(),
            'attendance' => fn () => DB::table('attendance')->where('school_id', $schoolId)->get(),
            'memorization_sessions' => fn () => DB::table('memorization_sessions')->where('school_id', $schoolId)->get(),
            'payments' => fn () => DB::table('payments')->where('school_id', $schoolId)->get(),
            'role_change_history' => fn () => DB::table('role_change_history')->where('school_id', $schoolId)->get(),
            'audit_events' => fn () => DB::table('audit_events')->where('school_id', $schoolId)->get(),
        ];

        $data = [];
        foreach ($tables as $name => $loader) {
            if (! DB::getSchemaBuilder()->hasTable($name)) {
                continue;
            }
            $rows = $loader();
            $data[$name] = $rows->map(fn ($row) => (array) $row)->all();
        }

        $payload = json_encode([
            'version' => 1,
            'exported_at' => now()->toIso8601String(),
            'school_id' => $schoolId,
            'data' => $data,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        return response($payload, 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => sprintf('attachment; filename="quranschool-backup-%s.json"', now()->format('Y-m-d-His')),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function importData(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();

        $request->validate([
            'backup_file' => ['required', 'file', 'mimes:json', 'max:51200'],
        ]);

        $file = $request->file('backup_file');
        $contents = $file->get();
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded) || ! isset($decoded['data']) || ! is_array($decoded['data'])) {
            return response()->json([
                'message' => 'Invalid backup file format.',
            ], 422);
        }

        $importedSchoolId = (int) ($decoded['school_id'] ?? 0);
        if ($importedSchoolId !== $schoolId) {
            return response()->json([
                'message' => 'This backup belongs to a different school.',
            ], 422);
        }

        $tables = [
            'schools', 'roles', 'academic_years', 'users', 'password_recovery_keys',
            'rooms', 'students', 'room_teachers', 'schedules', 'attendance',
            'memorization_sessions', 'payments', 'role_change_history', 'audit_events',
        ];

        $imported = [];
        $isSqlite = DB::getDriverName() === 'sqlite';
        if ($isSqlite) {
            DB::statement('PRAGMA foreign_keys = OFF');
        } else {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
        }
        DB::transaction(function () use ($decoded, $tables, $schoolId, &$imported) {
            foreach ($tables as $table) {
                if (! isset($decoded['data'][$table]) || ! is_array($decoded['data'][$table])) {
                    continue;
                }
                if (! DB::getSchemaBuilder()->hasTable($table)) {
                    continue;
                }

                $rows = $decoded['data'][$table];
                if ($table === 'schools') {
                    $rows = array_filter($rows, fn ($row) => (int) ($row['id'] ?? 0) === $schoolId);
                    DB::table($table)->where('id', $schoolId)->delete();
                } elseif ($table === 'roles') {
                    // Use delete() rather than truncate(): truncate is DDL on MySQL
                    // and would implicitly commit the surrounding transaction.
                    DB::table($table)->delete();
                } elseif ($table === 'password_recovery_keys') {
                    DB::table($table)
                        ->whereIn('user_id', DB::table('users')->where('school_id', $schoolId)->pluck('id'))
                        ->delete();
                } else {
                    DB::table($table)->where('school_id', $schoolId)->delete();
                }

                if (empty($rows)) {
                    continue;
                }

                $columns = array_values(array_diff(
                    DB::getSchemaBuilder()->getColumnListing($table),
                    $this->generatedColumns($table),
                ));
                $filteredRows = array_map(function ($row) use ($columns) {
                    return array_intersect_key($row, array_flip($columns));
                }, $rows);

                foreach (array_chunk($filteredRows, 200) as $chunk) {
                    DB::table($table)->insertOrIgnore($chunk);
                }

                $imported[$table] = count($filteredRows);
            }
        });
        if ($isSqlite) {
            DB::statement('PRAGMA foreign_keys = ON');
        } else {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        AuditEventLogger::record(
            $schoolId,
            null,
            'school',
            $schoolId,
            'restored',
            'school.data_imported',
            [],
            ['tables' => $imported],
        );

        return response()->json([
            'imported' => $imported,
        ]);
    }

    /**
     * Columns the database computes itself (e.g. STORED GENERATED), which must
     * never be part of an insert statement.
     *
     * @return array<int, string>
     */
    private function generatedColumns(string $table): array
    {
        if (DB::getDriverName() === 'sqlite') {
            $columns = DB::select("PRAGMA table_xinfo('{$table}')");

            return array_values(array_map(
                fn (object $column) => (string) $column->name,
                array_values(array_filter($columns, fn (object $column) => (int) $column->hidden > 1)),
            ));
        }

        $columns = DB::select("SHOW COLUMNS FROM `{$table}`");

        return array_values(array_map(
            fn (object $column) => (string) $column->Field,
            array_values(array_filter($columns, fn (object $column) => str_contains(strtoupper((string) $column->Extra), 'GENERATED'))),
        ));
    }

    private function schoolId(): int
    {
        $user = Auth::user();
        $schoolId = data_get($user, 'school_id');

        abort_unless(data_get($user, 'role') === 'admin' && $schoolId, 403);

        return (int) $schoolId;
    }
}
