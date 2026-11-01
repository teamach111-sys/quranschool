<?php

namespace App\Http\Controllers;

use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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

        $payments = $paymentsQuery
            ->orderByDesc('payments.period_start')
            ->orderBy('students.full_name')
            ->orderByDesc('payments.id')
            ->get([
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
            ])
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
            'can_manage' => $user->role === 'admin' && (bool) $year->is_active && (bool) $year->is_current,
            'can_record' => in_array($user->role, ['admin', 'secretary'], true)
                && (bool) $year->is_active
                && (bool) $year->is_current,
            'payments' => $payments,
            'students' => $students,
        ]);
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
            $query->where('is_current', true)->where('is_active', true);
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

    private function decimalAmount(string $amount): string
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return $whole.'.'.str_pad(substr($fraction, 0, 2), 2, '0');
    }
}
