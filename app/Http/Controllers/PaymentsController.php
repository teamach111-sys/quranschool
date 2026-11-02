<?php

namespace App\Http\Controllers;

use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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

        $students = DB::table('students')
            ->where('school_id', $schoolId)
            ->where('year_id', $yearId)
            ->orderBy('full_name')
            ->orderBy('id')
            ->get(['id', 'full_name', 'student_number'])
            ->map(fn ($student) => [
                'id' => (int) $student->id,
                'name' => $student->full_name,
                'student_number' => $student->student_number,
            ])
            ->values();

        return response()->json([
            'year_id' => $yearId,
            'year_label' => $year->year_label,
            'year_is_active' => (bool) $year->is_active,
            'year_is_current' => (bool) $year->is_current,
            'currency' => DB::table('schools')->where('id', $schoolId)->value('currency') ?? 'MAD',
            'can_manage' => $user->role === 'admin' && (bool) $year->is_active,
            'can_record' => in_array($user->role, ['admin', 'secretary'], true)
                && (bool) $year->is_active,
            'is_secretary' => $user->role === 'secretary',
            'payments' => $payments,
            'students' => $students,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        [$user, $schoolId] = $this->staffContext();
        abort_unless($user->role === 'admin', 403);
        $year = $this->resolveWritableYear($request, $schoolId);
        $validated = $this->validatedPayment($request, $schoolId, (int) $year->id);

        $paymentId = DB::transaction(function () use ($validated, $user, $schoolId, $year) {
            $this->lockStudentsForPayment([(int) $validated['student_id']], $schoolId, (int) $year->id);
            $this->ensureNoOverlappingPeriod(
                (int) $validated['student_id'],
                $schoolId,
                (int) $year->id,
                $validated['period_start'],
                $validated['period_end'],
            );

            return DB::table('payments')->insertGetId([
                ...$this->paymentAttributes($validated),
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
                $payment = DB::table('payments')
                    ->where('id', $paymentId)
                    ->where('school_id', $schoolId)
                    ->where('year_id', $yearId)
                    ->lockForUpdate()
                    ->first(['status', 'payment_method', 'reference', 'notes']);
                abort_unless($payment && in_array($payment->status, ['due', 'late'], true), 404);

                DB::table('payments')
                    ->where('id', $paymentId)
                    ->update([
                        'status' => $validated['status'],
                        'paid_at' => $validated['status'] === 'paid' ? $validated['paid_at'] : null,
                        'payment_method' => $validated['status'] === 'paid'
                            ? $validated['payment_method']
                            : $payment->payment_method,
                        'reference' => $validated['status'] === 'paid'
                            ? $validated['reference']
                            : $payment->reference,
                        'notes' => $validated['notes'] ?? $payment->notes,
                        'recorded_by' => (int) $user->id,
                        'updated_at' => now(),
                    ]);
            });

            return response()->json(['updated' => true]);
        }

        abort_unless($user->role === 'admin', 403);
        $validated = $this->validatedPayment($request, $schoolId, $yearId);

        DB::transaction(function () use ($validated, $user, $schoolId, $yearId, $paymentId, $existingPayment) {
            $this->lockStudentsForPayment(
                [(int) $existingPayment->student_id, (int) $validated['student_id']],
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

            DB::table('payments')
                ->where('id', $paymentId)
                ->where('school_id', $schoolId)
                ->where('year_id', $yearId)
                ->update([
                    ...$this->paymentAttributes($validated),
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
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'status' => ['required', Rule::in(['paid', 'due', 'late', 'waived'])],
            'paid_at' => [
                Rule::requiredIf($request->input('status') === 'paid'),
                Rule::prohibitedIf($request->input('status') !== 'paid'),
                'nullable',
                'date',
            ],
            'payment_method' => ['nullable', 'string', 'max:40'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => [
                Rule::requiredIf($request->input('status') === 'waived'),
                'nullable',
                'string',
                'max:500',
            ],
        ]);
    }

    /**
     * @return array{status: string, paid_at: ?string, payment_method: ?string, reference: ?string, notes: ?string}
     */
    private function validatedSecretaryPaymentUpdate(Request $request, string $currentStatus): array
    {
        abort_unless(in_array($currentStatus, ['due', 'late'], true), 404);

        return $request->validate([
            'status' => ['required', Rule::in(['paid', 'late', 'waived'])],
            'paid_at' => [
                Rule::requiredIf($request->input('status') === 'paid'),
                Rule::prohibitedIf($request->input('status') !== 'paid'),
                'nullable',
                'date',
            ],
            'payment_method' => [
                Rule::requiredIf($request->input('status') === 'paid'),
                'nullable',
                'string',
                'max:40',
            ],
            'reference' => [
                Rule::requiredIf($request->input('status') === 'paid'),
                'nullable',
                'string',
                'max:100',
            ],
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
     * @return array<string, mixed>
     */
    private function paymentAttributes(array $validated): array
    {
        return [
            'student_id' => (int) $validated['student_id'],
            'period_start' => $validated['period_start'],
            'period_end' => $validated['period_end'],
            'amount' => $validated['amount'],
            'currency' => $validated['currency'],
            'status' => $validated['status'],
            'paid_at' => $validated['status'] === 'paid' ? $validated['paid_at'] : null,
            'payment_method' => $validated['payment_method'] ?? null,
            'reference' => $validated['reference'] ?? null,
            'notes' => $validated['notes'] ?? null,
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
