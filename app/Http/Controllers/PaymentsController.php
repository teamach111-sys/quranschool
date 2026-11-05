<?php

namespace App\Http\Controllers;

use DateTimeImmutable;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use stdClass;

class PaymentsController extends Controller
{
    public function table(Request $request): JsonResponse
    {
        [$user, $schoolId] = $this->staffContext();
        $year = $this->resolveYear($request, $schoolId, $user->role);
        $yearId = (int) $year->id;
        $filters = $request->validate([
            'student_id' => [
                'nullable',
                'integer',
                Rule::exists('students', 'id')
                    ->where('school_id', $schoolId)
                    ->where('year_id', $yearId),
            ],
            'status' => ['nullable', Rule::in(['paid', 'due', 'late', 'waived'])],
            'period_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $paymentsQuery = DB::table('payments')
            ->join('students', function (JoinClause $join) {
                $join->on('students.id', '=', 'payments.student_id')
                    ->on('students.school_id', '=', 'payments.school_id')
                    ->on('students.year_id', '=', 'payments.year_id');
            })
            ->leftJoin('users as recorders', function (JoinClause $join) {
                $join->on('recorders.id', '=', 'payments.recorded_by')
                    ->on('recorders.school_id', '=', 'payments.school_id');
            })
            ->where('payments.school_id', $schoolId)
            ->where('payments.year_id', $yearId);

        if (! empty($filters['student_id'])) {
            $paymentsQuery->where('payments.student_id', (int) $filters['student_id']);
        }
        if (! empty($filters['status'])) {
            $paymentsQuery->where('payments.status', $filters['status']);
        }
        if (! empty($filters['period_date'])) {
            $paymentsQuery
                ->where('payments.period_start', '<=', $filters['period_date'])
                ->where('payments.period_end', '>=', $filters['period_date']);
        }

        $paymentColumns = [
            'payments.id',
            'payments.student_id',
            'students.full_name as student_name',
            'students.student_number',
            'payments.period_start',
            'payments.period_end',
            'payments.amount',
            'payments.currency',
            'payments.status',
            'payments.paid_at',
            'payments.payment_method',
            'payments.reference',
            'payments.notes',
            'payments.recorded_by',
            'recorders.full_name as recorded_by_name',
        ];
        if ($user->role === 'secretary') {
            $paymentColumns[] = 'students.guardian_phone';
        }

        $payments = $paymentsQuery
            ->orderByDesc('payments.period_start')
            ->orderBy('students.full_name')
            ->orderByDesc('payments.id')
            ->get($paymentColumns)
            ->map(fn ($payment) => [
                'id' => (int) $payment->id,
                'student_id' => (int) $payment->student_id,
                'student_name' => $payment->student_name,
                'student_number' => $payment->student_number,
                'period_start' => $payment->period_start,
                'period_end' => $payment->period_end,
                'amount' => $this->decimalAmount((string) $payment->amount),
                'currency' => $payment->currency,
                'status' => $payment->status,
                'paid_at' => $payment->paid_at,
                'payment_method' => $payment->payment_method,
                'reference' => $payment->reference,
                'notes' => $payment->notes,
                'recorded_by' => $payment->recorded_by === null ? null : (int) $payment->recorded_by,
                'recorded_by_name' => $payment->recorded_by_name,
                ...($user->role === 'secretary' ? ['guardian_phone' => $payment->guardian_phone] : []),
            ])
            ->values();

        $schoolCurrency = DB::table('schools')->where('id', $schoolId)->value('currency') ?? 'MAD';

        $unpaidBalances = DB::table('payments')
            ->join('students', function (JoinClause $join) {
                $join->on('students.id', '=', 'payments.student_id')
                    ->on('students.school_id', '=', 'payments.school_id')
                    ->on('students.year_id', '=', 'payments.year_id');
            })
            ->where('payments.school_id', $schoolId)
            ->where('payments.year_id', $yearId)
            ->whereIn('payments.status', ['due', 'late'])
            ->select([
                'students.id as student_id',
                'students.full_name as student_name',
                'students.student_number',
                'students.guardian_phone',
                DB::raw('SUM(payments.amount) as total_amount'),
                DB::raw('COUNT(*) as period_count'),
                DB::raw('MIN(payments.period_start) as oldest_period_start'),
                DB::raw('MAX(payments.period_end) as latest_period_end'),
                DB::raw('MIN(payments.id) as first_payment_id'),
            ])
            ->groupBy('students.id', 'students.full_name', 'students.student_number', 'students.guardian_phone')
            ->orderBy('oldest_period_start')
            ->orderBy('students.full_name')
            ->orderBy('students.id')
            ->get()
            ->map(fn ($balance) => [
                'student_id' => (int) $balance->student_id,
                'student_name' => $balance->student_name,
                'student_number' => $balance->student_number,
                'guardian_phone' => $balance->guardian_phone,
                'period_count' => (int) $balance->period_count,
                'total_amount' => $this->decimalAmount((string) $balance->total_amount),
                'currency' => $schoolCurrency,
                'oldest_period_start' => $balance->oldest_period_start,
                'latest_period_end' => $balance->latest_period_end,
                'first_payment_id' => (int) $balance->first_payment_id,
            ])
            ->values();

        $students = DB::table('students')
            ->where('school_id', $schoolId)
            ->where('year_id', $yearId)
            ->orderBy('full_name')
            ->orderBy('id')
            ->select(['id', 'full_name', 'student_number'])
            ->selectSub(
                DB::table('payments as previous_payments')
                    ->select('payment_method')
                    ->whereColumn('previous_payments.student_id', 'students.id')
                    ->whereColumn('previous_payments.school_id', 'students.school_id')
                    ->where('previous_payments.status', 'paid')
                    ->whereNotNull('previous_payments.payment_method')
                    ->orderByDesc('previous_payments.paid_at')
                    ->orderByDesc('previous_payments.id')
                    ->limit(1),
                'last_payment_method',
            )
            ->get()
            ->map(fn ($student) => [
                'id' => (int) $student->id,
                'name' => $student->full_name,
                'student_number' => $student->student_number,
                'last_payment_method' => $student->last_payment_method,
            ])
            ->values();

        return response()->json([
            'year_id' => $yearId,
            'year_label' => $year->year_label,
            'year_is_active' => (bool) $year->is_active,
            'year_is_current' => (bool) $year->is_current,
            'currency' => $schoolCurrency,
            'can_manage' => $user->role === 'admin' && (bool) $year->is_active,
            'can_record' => in_array($user->role, ['admin', 'secretary'], true)
                && (bool) $year->is_active,
            'can_export_receipts' => in_array($user->role, ['admin', 'secretary'], true),
            'is_secretary' => $user->role === 'secretary',
            'payments' => $payments,
            'students' => $students,
            'unpaid_balances' => $user->role === 'secretary' ? $unpaidBalances : [],
        ]);
    }

    public function receipt(Request $request, int $paymentId)
    {
        [$user, $schoolId] = $this->staffContext();
        $year = $this->resolveYear($request, $schoolId, $user->role);
        $language = $request->validate([
            'lang' => ['nullable', Rule::in(['fr', 'ar'])],
        ])['lang'] ?? data_get($user, 'language', 'fr');
        $language = in_array($language, ['fr', 'ar'], true) ? $language : 'fr';

        $payment = DB::table('payments')
            ->join('students', function (JoinClause $join) {
                $join->on('students.id', '=', 'payments.student_id')
                    ->on('students.school_id', '=', 'payments.school_id')
                    ->on('students.year_id', '=', 'payments.year_id');
            })
            ->join('schools', 'schools.id', '=', 'payments.school_id')
            ->join('academic_years', function (JoinClause $join) {
                $join->on('academic_years.id', '=', 'payments.year_id')
                    ->on('academic_years.school_id', '=', 'payments.school_id');
            })
            ->where('payments.id', $paymentId)
            ->where('payments.school_id', $schoolId)
            ->where('payments.year_id', $year->id)
            ->where('payments.status', 'paid')
            ->whereNotNull('payments.paid_at')
            ->first([
                'payments.id',
                'payments.year_id',
                'payments.period_start',
                'payments.period_end',
                'payments.amount',
                'payments.currency',
                'payments.paid_at',
                'payments.payment_method',
                'payments.reference',
                'students.full_name as student_name',
                'schools.name_fr as school_name_fr',
                'schools.name_ar as school_name_ar',
                'academic_years.year_label',
            ]);
        abort_unless($payment, 404);

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
        $schoolName = $language === 'ar' ? $payment->school_name_ar : $payment->school_name_fr;
        $generatedAt = now()->format('Y-m-d H:i:s');
        $temporaryDirectory = storage_path('framework/cache/mpdf');
        File::ensureDirectoryExists($temporaryDirectory);
        $pdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'default_font' => 'dejavusans',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'tempDir' => $temporaryDirectory,
        ]);
        $pdf->SetTitle($labels['title']);
        $pdf->WriteHTML(view('exports.payment-receipt', [
            'language' => $language,
            'labels' => $labels,
            'schoolName' => $schoolName,
            'yearLabel' => $payment->year_label,
            'studentName' => $payment->student_name,
            'periodStart' => $payment->period_start,
            'periodEnd' => $payment->period_end,
            'amount' => number_format((float) $payment->amount, 2, '.', ','),
            'currency' => $payment->currency,
            'paidAt' => $payment->paid_at,
            'paymentMethod' => $payment->payment_method,
            'reference' => $payment->reference,
            'generatedAt' => $generatedAt,
        ])->render());
        $contents = $pdf->Output('', Destination::STRING_RETURN);

        $actorName = DB::table('users')
            ->where('school_id', $schoolId)
            ->where('id', $user->id)
            ->value('full_name');
        abort_unless(is_string($actorName) && $actorName !== '', 403);

        DB::table('audit_events')->insert([
            'school_id' => $schoolId,
            'year_id' => (int) $payment->year_id,
            'actor_user_id' => (int) $user->id,
            'actor_name' => $actorName,
            'action_type' => 'exported',
            'entity_type' => 'payment',
            'entity_id' => (int) $payment->id,
            'summary_key' => 'payment.receipt_exported',
            'changes' => json_encode([
                'format' => 'pdf',
                'page' => 'payments',
                'record_count' => 1,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="payment-receipt-'.$paymentId.'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function generationOptions(Request $request): JsonResponse
    {
        [, $schoolId] = $this->staffContext();
        $year = $this->resolveGenerationYear($request, $schoolId);
        $school = DB::table('schools')
            ->where('id', $schoolId)
            ->first(['monthly_fee', 'currency']);
        abort_unless($school, 404);

        $yearId = (int) $year->id;
        $students = DB::table('students')
            ->leftJoin('rooms', function (JoinClause $join) {
                $join->on('rooms.id', '=', 'students.room_id')
                    ->on('rooms.school_id', '=', 'students.school_id')
                    ->on('rooms.year_id', '=', 'students.year_id')
                    ->where('rooms.is_active', true);
            })
            ->where('students.school_id', $schoolId)
            ->where('students.year_id', $yearId)
            ->where('status', 'active')
            ->orderBy('students.full_name')
            ->orderBy('students.id')
            ->get([
                'students.id',
                'students.full_name',
                'students.student_number',
                'students.room_id',
                'rooms.name as room_name',
                'rooms.level',
            ])
            ->map(fn ($student) => [
                'id' => (int) $student->id,
                'name' => $student->full_name,
                'student_number' => $student->student_number,
                'room_id' => $student->room_id === null ? null : (int) $student->room_id,
                'room_name' => $student->room_name,
                'level' => $student->level,
            ])
            ->values();
        $rooms = DB::table('rooms')
            ->where('school_id', $schoolId)
            ->where('year_id', $yearId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'level'])
            ->map(fn ($room) => [
                'id' => (int) $room->id,
                'name' => $room->name,
                'level' => $room->level,
            ])
            ->values();
        $levels = $rooms->pluck('level')->unique()->values();

        return response()->json([
            'year_id' => (int) $year->id,
            'year_label' => $year->year_label,
            'start_date' => $year->start_date,
            'end_date' => $year->end_date,
            'monthly_fee' => $school->monthly_fee === null
                ? null
                : $this->decimalAmount((string) $school->monthly_fee),
            'currency' => $school->currency,
            'students' => $students,
            'rooms' => $rooms,
            'levels' => $levels,
        ]);
    }

    public function previewGeneration(Request $request): JsonResponse
    {
        [, $schoolId] = $this->staffContext();
        $year = $this->resolveGenerationYear($request, $schoolId);
        $validated = $this->validatedGenerationInput($request, $schoolId, $year);
        $preview = $this->buildGenerationPreview($schoolId, $year, $validated);
        $publicPreview = $this->publicGenerationPreview($preview);

        return response()->json([
            ...$publicPreview,
            'preview_token' => $this->generationPreviewToken($publicPreview),
        ]);
    }

    public function generatePeriods(Request $request): JsonResponse
    {
        [$user, $schoolId] = $this->staffContext();
        $year = $this->resolveGenerationYear($request, $schoolId);
        $validated = $this->validatedGenerationInput($request, $schoolId, $year);
        $previewToken = $request->validate([
            'preview_token' => ['required', 'string', 'size:64'],
        ])['preview_token'];

        $result = DB::transaction(function () use ($validated, $previewToken, $user, $schoolId, $year) {
            $this->lockGenerationYear($schoolId, (int) $year->id);
            $studentIds = array_map('intval', $validated['student_ids']);
            $this->lockStudentsForPayment($studentIds, $schoolId, (int) $year->id);
            $preview = $this->buildGenerationPreview($schoolId, $year, $validated, true);
            $publicPreview = $this->publicGenerationPreview($preview);

            if (! hash_equals($this->generationPreviewToken($publicPreview), $previewToken)) {
                throw ValidationException::withMessages([
                    'preview_token' => 'The billing preview changed. Review it again before generating payments.',
                ]);
            }

            $rowsToInsert = array_values(array_filter(
                $preview['rows'],
                fn (array $row) => $row['status'] === 'create',
            ));

            if ($rowsToInsert !== []) {
                $timestamp = now();
                DB::table('payments')->insert(array_map(
                    fn (array $row) => [
                        'student_id' => $row['student_id'],
                        'school_id' => $schoolId,
                        'year_id' => (int) $year->id,
                        'recorded_by' => (int) $user->id,
                        'period_start' => $row['period_start'],
                        'period_end' => $row['period_end'],
                        'amount' => $preview['monthly_fee'],
                        'currency' => $preview['currency'],
                        'status' => 'due',
                        'paid_at' => null,
                        'payment_method' => null,
                        'reference' => null,
                        'notes' => null,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ],
                    $rowsToInsert,
                ));
            }

            return [
                ...$publicPreview,
                'created_count' => count($rowsToInsert),
            ];
        });

        return response()->json($result);
    }

    public function store(Request $request): JsonResponse
    {
        [$user, $schoolId] = $this->staffContext();
        abort_unless($user->role === 'admin', 403);
        $year = $this->resolveWritableYear($request, $schoolId);
        $validated = $this->validatedPayment($request, $schoolId, (int) $year->id);

        $paymentId = DB::transaction(function () use ($validated, $user, $schoolId, $year) {
            $schoolCurrency = $this->lockSchoolForPayment($schoolId);
            $this->lockStudentsForPayment([(int) $validated['student_id']], $schoolId, (int) $year->id);
            $this->ensureNoOverlappingPeriod(
                (int) $validated['student_id'],
                $schoolId,
                (int) $year->id,
                $validated['period_start'],
                $validated['period_end'],
            );
            $receipt = $validated['status'] === 'paid'
                ? $this->generatePaymentReceipt($schoolId)
                : null;

            return DB::table('payments')->insertGetId([
                ...$this->paymentAttributes($validated, $schoolCurrency, $receipt),
                'school_id' => $schoolId,
                'year_id' => (int) $year->id,
                'recorded_by' => (int) $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return response()->json(['payment_id' => $paymentId], 201);
    }

    public function update(Request $request, int $paymentId): JsonResponse
    {
        [$user, $schoolId] = $this->staffContext();
        $year = $this->resolveWritableYear($request, $schoolId);
        $yearId = (int) $year->id;
        $existingPayment = DB::table('payments')
            ->where('id', $paymentId)
            ->where('school_id', $schoolId)
            ->where('year_id', $yearId)
            ->first();
        abort_unless($existingPayment, 404);

        if ($user->role === 'secretary') {
            $validated = $this->validatedSecretaryPaymentUpdate($request, $existingPayment->status);

            DB::transaction(function () use ($paymentId, $schoolId, $yearId, $validated, $user) {
                $schoolCurrency = $this->lockSchoolForPayment($schoolId);
                $payment = DB::table('payments')
                    ->where('id', $paymentId)
                    ->where('school_id', $schoolId)
                    ->where('year_id', $yearId)
                    ->lockForUpdate()
                    ->first(['status', 'payment_method', 'reference', 'notes']);
                abort_unless($payment && in_array($payment->status, ['due', 'late', 'waived'], true), 404);
                $receipt = $validated['status'] === 'paid'
                    ? $this->generatePaymentReceipt($schoolId)
                    : null;

                DB::table('payments')
                    ->where('id', $paymentId)
                    ->update([
                        'status' => $validated['status'],
                        'paid_at' => $receipt['paid_at'] ?? null,
                        'currency' => $schoolCurrency,
                        'payment_method' => $validated['status'] === 'paid'
                            ? $validated['payment_method']
                            : null,
                        'reference' => $validated['status'] === 'paid'
                            ? $receipt['reference']
                            : null,
                        'notes' => $validated['notes'] ?? $payment->notes,
                        'recorded_by' => (int) $user->id,
                        'updated_at' => now(),
                    ]);
            });

            return response()->json(['updated' => true]);
        }

        abort_unless($user->role === 'admin', 403);
        $validated = $this->validatedPayment($request, $schoolId, $yearId);

        DB::transaction(function () use ($validated, $user, $schoolId, $yearId, $paymentId) {
            $schoolCurrency = $this->lockSchoolForPayment($schoolId);
            $payment = DB::table('payments')
                ->where('id', $paymentId)
                ->where('school_id', $schoolId)
                ->where('year_id', $yearId)
                ->lockForUpdate()
                ->first(['student_id', 'status', 'paid_at', 'reference']);
            abort_unless($payment, 404);

            $this->lockStudentsForPayment(
                [(int) $payment->student_id, (int) $validated['student_id']],
                $schoolId,
                $yearId,
            );
            $this->ensureNoOverlappingPeriod(
                (int) $validated['student_id'],
                $schoolId,
                $yearId,
                $validated['period_start'],
                $validated['period_end'],
                $paymentId,
            );
            $receipt = $validated['status'] === 'paid' && $payment->status !== 'paid'
                ? $this->generatePaymentReceipt($schoolId)
                : null;

            DB::table('payments')
                ->where('id', $paymentId)
                ->where('school_id', $schoolId)
                ->where('year_id', $yearId)
                ->update([
                    ...$this->paymentAttributes($validated, $schoolCurrency, $receipt, $payment),
                    'recorded_by' => (int) $user->id,
                    'updated_at' => now(),
                ]);
        });

        return response()->json(['updated' => true]);
    }

    /**
     * @return array{0: object, 1: int}
     */
    private function staffContext(): array
    {
        $user = Auth::user();
        $schoolId = data_get($user, 'school_id');
        abort_unless(
            $user && in_array(data_get($user, 'role'), ['admin', 'secretary'], true) && $schoolId,
            403,
        );

        return [$user, (int) $schoolId];
    }

    private function resolveYear(Request $request, int $schoolId, string $role): stdClass
    {
        $query = DB::table('academic_years')->where('school_id', $schoolId);
        $yearId = $request->input('year_id');

        if ($yearId !== null) {
            $request->validate([
                'year_id' => [
                    'integer',
                    Rule::exists('academic_years', 'id')->where('school_id', $schoolId),
                ],
            ]);
            $query->where('id', (int) $yearId);
        }

        if ($role !== 'admin') {
            $query->where('is_active', true);
            if ($yearId === null) {
                $query->where('is_current', true);
            }
        }

        $year = $query->orderByDesc('start_date')->first([
            'id',
            'year_label',
            'is_active',
            'is_current',
        ]);
        abort_unless($year, 404);

        return $year;
    }

    private function resolveWritableYear(Request $request, int $schoolId): stdClass
    {
        $yearId = $request->validate([
            'year_id' => [
                'required',
                'integer',
                Rule::exists('academic_years', 'id')->where('school_id', $schoolId),
            ],
        ])['year_id'];
        $year = DB::table('academic_years')
            ->where('id', $yearId)
            ->where('school_id', $schoolId)
            ->first(['id', 'is_active']);
        abort_unless($year, 404);

        if (! $year->is_active) {
            throw ValidationException::withMessages([
                'year_id' => 'Payments in a closed academic year cannot be changed.',
            ]);
        }

        return $year;
    }

    private function resolveGenerationYear(Request $request, int $schoolId): stdClass
    {
        $yearId = $request->validate([
            'year_id' => [
                'required',
                'integer',
                Rule::exists('academic_years', 'id')
                    ->where('school_id', $schoolId)
                    ->where('is_current', true)
                    ->where('is_active', true),
            ],
        ])['year_id'];

        $year = DB::table('academic_years')
            ->where('id', $yearId)
            ->where('school_id', $schoolId)
            ->where('is_current', true)
            ->where('is_active', true)
            ->first(['id', 'year_label', 'start_date', 'end_date']);
        abort_unless($year, 404);

        return $year;
    }

    private function lockGenerationYear(int $schoolId, int $yearId): void
    {
        $year = DB::table('academic_years')
            ->where('id', $yearId)
            ->where('school_id', $schoolId)
            ->lockForUpdate()
            ->first(['is_current', 'is_active']);

        if (! $year || ! $year->is_current || ! $year->is_active) {
            throw ValidationException::withMessages([
                'year_id' => 'Payment periods can only be generated for the current active academic year.',
            ]);
        }
    }

    /**
     * @return array{from_month: string, to_month: string, student_ids: array<int, int|string>}
     */
    private function validatedGenerationInput(Request $request, int $schoolId, stdClass $year): array
    {
        $validated = $request->validate([
            'from_month' => ['required', 'date_format:Y-m'],
            'to_month' => ['required', 'date_format:Y-m'],
            'student_ids' => ['required', 'array', 'min:1'],
            'student_ids.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('students', 'id')
                    ->where('school_id', $schoolId)
                    ->where('year_id', (int) $year->id)
                    ->where('status', 'active'),
            ],
        ]);

        $startMonth = $validated['from_month'];
        $endMonth = $validated['to_month'];
        $firstYearMonth = substr((string) $year->start_date, 0, 7);
        $lastYearMonth = substr((string) $year->end_date, 0, 7);
        $errors = [];

        if ($startMonth > $endMonth) {
            $errors['from_month'] = 'The first month must not be after the last month.';
        }
        if ($startMonth < $firstYearMonth) {
            $errors['from_month'] = 'The billing range must be within the current academic year.';
        }
        if ($endMonth > $lastYearMonth) {
            $errors['to_month'] = 'The billing range must be within the current academic year.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $validated;
    }

    /**
     * @param  array{from_month: string, to_month: string, student_ids: array<int, int|string>}  $validated
     * @return array<string, mixed>
     */
    private function buildGenerationPreview(
        int $schoolId,
        stdClass $year,
        array $validated,
        bool $lockPayments = false,
    ): array {
        $schoolQuery = DB::table('schools')->where('id', $schoolId);
        if ($lockPayments) {
            $schoolQuery->lockForUpdate();
        }
        $school = $schoolQuery->first(['monthly_fee', 'currency']);
        abort_unless($school, 404);

        if ($school->monthly_fee === null) {
            throw ValidationException::withMessages([
                'monthly_fee' => 'Configure a monthly fee before generating payment periods.',
            ]);
        }

        $monthlyFee = $this->decimalAmount((string) $school->monthly_fee);
        $feeCents = $this->decimalToCents($monthlyFee);
        $periods = $this->generationPeriods($year, $validated['from_month'], $validated['to_month']);
        $studentIds = array_map('intval', $validated['student_ids']);
        $students = DB::table('students')
            ->whereIn('id', $studentIds)
            ->where('school_id', $schoolId)
            ->where('year_id', (int) $year->id)
            ->where('status', 'active')
            ->orderBy('full_name')
            ->orderBy('id')
            ->get(['id', 'full_name', 'student_number', 'enrollment_date']);

        if ($students->count() !== count(array_unique($studentIds))) {
            abort(404);
        }

        $existingPaymentsQuery = DB::table('payments')
            ->whereIn('student_id', $studentIds)
            ->where('school_id', $schoolId)
            ->where('year_id', (int) $year->id)
            ->where('period_start', '<=', $periods[array_key_last($periods)]['period_end'])
            ->where('period_end', '>=', $periods[0]['period_start']);
        if ($lockPayments) {
            $existingPaymentsQuery->lockForUpdate();
        }
        $existingPayments = $existingPaymentsQuery
            ->get(['student_id', 'period_start', 'period_end'])
            ->groupBy('student_id');

        $studentSummaries = [];
        $rows = [];
        $counts = [
            'create' => 0,
            'exists' => 0,
            'overlap' => 0,
            'not_enrolled' => 0,
        ];

        foreach ($students as $student) {
            $studentId = (int) $student->id;
            $studentPayments = $existingPayments->get($studentId, collect());
            $studentSummary = [
                'id' => $studentId,
                'name' => $student->full_name,
                'student_number' => $student->student_number,
                'create_count' => 0,
                'existing_count' => 0,
                'overlap_count' => 0,
                'not_enrolled_count' => 0,
            ];

            foreach ($periods as $period) {
                $status = 'create';
                if ($period['period_end'] < $student->enrollment_date) {
                    $status = 'not_enrolled';
                } else {
                    foreach ($studentPayments as $payment) {
                        if (
                            $payment->period_start === $period['period_start']
                            && $payment->period_end === $period['period_end']
                        ) {
                            $status = 'exists';
                            break;
                        }
                        if (
                            $payment->period_start <= $period['period_end']
                            && $payment->period_end >= $period['period_start']
                        ) {
                            $status = 'overlap';
                        }
                    }
                }

                $counts[$status]++;
                $summaryKey = [
                    'create' => 'create_count',
                    'exists' => 'existing_count',
                    'overlap' => 'overlap_count',
                    'not_enrolled' => 'not_enrolled_count',
                ][$status];
                $studentSummary[$summaryKey]++;
                $rows[] = [
                    'student_id' => $studentId,
                    'period_start' => $period['period_start'],
                    'period_end' => $period['period_end'],
                    'status' => $status,
                ];
            }

            $studentSummaries[] = $studentSummary;
        }

        return [
            'year_id' => (int) $year->id,
            'year_label' => $year->year_label,
            'from_month' => $validated['from_month'],
            'to_month' => $validated['to_month'],
            'monthly_fee' => $monthlyFee,
            'currency' => $school->currency,
            'period_count' => count($periods),
            'students' => $studentSummaries,
            'rows' => $rows,
            'create_count' => $counts['create'],
            'existing_count' => $counts['exists'],
            'overlap_count' => $counts['overlap'],
            'not_enrolled_count' => $counts['not_enrolled'],
            'total_amount' => $this->centsToDecimal($counts['create'] * $feeCents),
        ];
    }

    /**
     * @return array<int, array{period_start: string, period_end: string}>
     */
    private function generationPeriods(stdClass $year, string $fromMonth, string $toMonth): array
    {
        $yearStart = new DateTimeImmutable((string) $year->start_date);
        $yearEnd = new DateTimeImmutable((string) $year->end_date);
        $lastMonth = new DateTimeImmutable($toMonth.'-01');
        $periods = [];

        for ($month = new DateTimeImmutable($fromMonth.'-01'); $month <= $lastMonth; $month = $month->modify('+1 month')) {
            $monthStart = $month->modify('first day of this month');
            $monthEnd = $month->modify('last day of this month');
            $periodStart = $monthStart < $yearStart ? $yearStart : $monthStart;
            $periodEnd = $monthEnd > $yearEnd ? $yearEnd : $monthEnd;
            $periods[] = [
                'period_start' => $periodStart->format('Y-m-d'),
                'period_end' => $periodEnd->format('Y-m-d'),
            ];
        }

        return $periods;
    }

    /**
     * @param  array<string, mixed>  $preview
     * @return array<string, mixed>
     */
    private function publicGenerationPreview(array $preview): array
    {
        unset($preview['rows']);

        return $preview;
    }

    /**
     * @param  array<string, mixed>  $preview
     */
    private function generationPreviewToken(array $preview): string
    {
        return hash_hmac(
            'sha256',
            json_encode($preview, JSON_THROW_ON_ERROR),
            (string) config('app.key'),
        );
    }

    private function decimalToCents(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    private function centsToDecimal(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPayment(Request $request, int $schoolId, int $yearId): array
    {
        return $request->validate([
            'student_id' => [
                'required',
                'integer',
                Rule::exists('students', 'id')
                    ->where('school_id', $schoolId)
                    ->where('year_id', $yearId),
            ],
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
            'amount' => ['required', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'currency' => ['prohibited'],
            'status' => ['required', Rule::in(['paid', 'due', 'late', 'waived'])],
            'paid_at' => ['prohibited'],
            'payment_method' => [
                Rule::requiredIf($request->input('status') === 'paid'),
                Rule::prohibitedIf($request->input('status') !== 'paid'),
                'nullable',
                'string',
                'max:40',
            ],
            'reference' => ['prohibited'],
            'notes' => [
                Rule::requiredIf($request->input('status') === 'waived'),
                'nullable',
                'string',
                'max:500',
            ],
        ]);
    }

    /**
     * @return array{status: string, payment_method: ?string, notes: ?string}
     */
    private function validatedSecretaryPaymentUpdate(Request $request, string $currentStatus): array
    {
        abort_unless(in_array($currentStatus, ['due', 'late', 'waived'], true), 404);

        return $request->validate([
            'status' => ['required', Rule::in(['paid', 'late', 'waived'])],
            'paid_at' => ['prohibited'],
            'payment_method' => [
                Rule::requiredIf($request->input('status') === 'paid'),
                Rule::prohibitedIf($request->input('status') !== 'paid'),
                'nullable',
                'string',
                'max:40',
            ],
            'reference' => ['prohibited'],
            'notes' => [
                Rule::requiredIf($request->input('status') === 'waived'),
                'nullable',
                'string',
                'max:500',
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array{paid_at: string, reference: string}|null  $receipt
     * @return array<string, mixed>
     */
    private function paymentAttributes(
        array $validated,
        string $currency,
        ?array $receipt,
        ?object $existingPayment = null,
    ): array {
        $paidAt = $receipt['paid_at'] ?? ($existingPayment->paid_at ?? null);
        $reference = $receipt['reference'] ?? ($existingPayment->reference ?? null);

        if ($validated['status'] === 'paid' && (! $paidAt || ! $reference)) {
            throw new \LogicException('Paid payments require a generated receipt.');
        }

        return [
            'student_id' => (int) $validated['student_id'],
            'period_start' => $validated['period_start'],
            'period_end' => $validated['period_end'],
            'amount' => $validated['amount'],
            'currency' => $currency,
            'status' => $validated['status'],
            'paid_at' => $validated['status'] === 'paid' ? $paidAt : null,
            'payment_method' => $validated['status'] === 'paid'
                ? $validated['payment_method']
                : null,
            'reference' => $validated['status'] === 'paid' ? $reference : null,
            'notes' => $validated['notes'] ?? null,
        ];
    }

    private function lockSchoolForPayment(int $schoolId): string
    {
        $school = DB::table('schools')
            ->where('id', $schoolId)
            ->lockForUpdate()
            ->first(['currency']);
        abort_unless($school, 404);

        return (string) $school->currency;
    }

    /**
     * @return array{paid_at: string, reference: string}
     */
    private function generatePaymentReceipt(int $schoolId): array
    {
        $paidAt = now();
        $year = $paidAt->format('Y');
        $referencePrefix = "REC-{$year}-";
        $references = DB::table('payments')
            ->where('school_id', $schoolId)
            ->where('reference', 'like', $referencePrefix.'%')
            ->pluck('reference');
        $highestSequence = 0;
        $referencePattern = '/^'.preg_quote($referencePrefix, '/').'([0-9]+)$/D';

        foreach ($references as $reference) {
            if (preg_match($referencePattern, (string) $reference, $matches) === 1) {
                $highestSequence = max($highestSequence, (int) $matches[1]);
            }
        }

        return [
            'paid_at' => $paidAt->toDateTimeString(),
            'reference' => $referencePrefix.str_pad((string) ($highestSequence + 1), 4, '0', STR_PAD_LEFT),
        ];
    }

    /**
     * @param  array<int>  $studentIds
     */
    private function lockStudentsForPayment(array $studentIds, int $schoolId, int $yearId): void
    {
        sort($studentIds);
        $lockedStudentIds = DB::table('students')
            ->whereIn('id', array_unique($studentIds))
            ->where('school_id', $schoolId)
            ->where('year_id', $yearId)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (count($lockedStudentIds) !== count(array_unique($studentIds))) {
            abort(404);
        }
    }

    private function ensureNoOverlappingPeriod(
        int $studentId,
        int $schoolId,
        int $yearId,
        string $periodStart,
        string $periodEnd,
        ?int $exceptPaymentId = null,
    ): void {
        $overlapQuery = DB::table('payments')
            ->where('student_id', $studentId)
            ->where('school_id', $schoolId)
            ->where('year_id', $yearId)
            ->where('period_start', '<=', $periodEnd)
            ->where('period_end', '>=', $periodStart);

        if ($exceptPaymentId !== null) {
            $overlapQuery->where('id', '!=', $exceptPaymentId);
        }

        if ($overlapQuery->exists()) {
            throw ValidationException::withMessages([
                'period_start' => 'The billing period overlaps another payment for this student.',
            ]);
        }
    }

    private function decimalAmount(string $amount): string
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return $whole.'.'.str_pad(substr($fraction, 0, 2), 2, '0');
    }
}
