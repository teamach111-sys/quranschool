<?php

namespace App\Http\Controllers;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SecretaryDashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = Auth::user();
        $schoolId = data_get($user, 'school_id');
        abort_unless(data_get($user, 'role') === 'secretary' && $schoolId, 403);

        $validated = $request->validate([
            'year_id' => ['sometimes', 'required', 'integer', 'min:1'],
        ]);

        $schoolId = (int) $schoolId;
        $yearQuery = DB::table('academic_years')
            ->where('school_id', $schoolId)
            ->where('is_current', true)
            ->where('is_active', true);

        if (isset($validated['year_id'])) {
            $yearQuery->where('id', (int) $validated['year_id']);
        }

        $year = $yearQuery->orderByDesc('start_date')->first(['id', 'year_label']);
        abort_unless($year, 404);

        $yearId = (int) $year->id;
        $today = now()->toDateString();
        $recentStudentCutoff = now()->subDays(29)->startOfDay()->toDateTimeString();
        $attendanceCutoff = now()->subDays(6)->toDateString();

        $incompleteStudentCount = $this->incompleteStudentsQuery($schoolId, $yearId)->count();
        $newStudentCount = $this->studentRecordsQuery($schoolId, $yearId)
            ->where('created_at', '>=', $recentStudentCutoff)
            ->count();

        $studentsToReview = $this->studentRecordsQuery($schoolId, $yearId)
            ->where(function (Builder $query) use ($recentStudentCutoff) {
                $query->where('created_at', '>=', $recentStudentCutoff)
                    ->orWhere(function (Builder $incomplete) {
                        $this->applyIncompleteStudentFilter($incomplete);
                    });
            })
            ->orderByDesc('created_at')
            ->orderBy('full_name')
            ->limit(6)
            ->get([
                'id',
                'student_number',
                'full_name',
                'guardian_phone',
                'guardian_email',
                'status',
                'created_at',
            ])
            ->map(function ($student) use ($recentStudentCutoff) {
                $missingFields = [];

                foreach (['student_number', 'guardian_phone', 'guardian_email'] as $field) {
                    if ($student->{$field} === null || trim((string) $student->{$field}) === '') {
                        $missingFields[] = $field;
                    }
                }

                return [
                    'id' => (int) $student->id,
                    'student_name' => $student->full_name,
                    'student_number' => $student->student_number,
                    'status' => $student->status,
                    'created_at' => $student->created_at,
                    'is_new' => $student->created_at >= $recentStudentCutoff,
                    'missing_fields' => $missingFields,
                ];
            })
            ->values();

        $attendanceQuery = DB::table('attendance')
            ->join('students', function (JoinClause $join) {
                $join->on('students.id', '=', 'attendance.student_id')
                    ->on('students.school_id', '=', 'attendance.school_id')
                    ->on('students.year_id', '=', 'attendance.year_id');
            })
            ->leftJoin('rooms', function (JoinClause $join) {
                $join->on('rooms.id', '=', 'attendance.room_id')
                    ->on('rooms.school_id', '=', 'attendance.school_id')
                    ->on('rooms.year_id', '=', 'attendance.year_id');
            })
            ->where('attendance.school_id', $schoolId)
            ->where('attendance.year_id', $yearId)
            ->whereBetween('attendance.attendance_date', [$attendanceCutoff, $today])
            ->whereIn('attendance.status', ['absent', 'late'])
            ->whereIn('students.status', ['active', 'paused']);

        $attendanceFollowUpCount = (clone $attendanceQuery)->count('attendance.id');
        $attendanceFollowUpItems = $attendanceQuery
            ->orderByDesc('attendance.attendance_date')
            ->orderByDesc('attendance.id')
            ->limit(8)
            ->get([
                'attendance.id',
                'students.full_name as student_name',
                'students.student_number',
                'rooms.name as room_name',
                'attendance.attendance_date',
                'attendance.status',
            ])
            ->map(fn ($attendance) => [
                'id' => (int) $attendance->id,
                'student_name' => $attendance->student_name,
                'student_number' => $attendance->student_number,
                'room_name' => $attendance->room_name,
                'attendance_date' => $attendance->attendance_date,
                'status' => $attendance->status,
            ])
            ->values();

        $recentPayments = DB::table('payments')
            ->join('students', function (JoinClause $join) {
                $join->on('students.id', '=', 'payments.student_id')
                    ->on('students.school_id', '=', 'payments.school_id')
                    ->on('students.year_id', '=', 'payments.year_id');
            })
            ->where('payments.school_id', $schoolId)
            ->where('payments.year_id', $yearId)
            ->orderByDesc('payments.created_at')
            ->orderByDesc('payments.id')
            ->limit(5)
            ->get([
                'payments.id',
                'students.full_name as student_name',
                'payments.created_at',
                'payments.amount',
                'payments.currency',
                'payments.status',
            ])
            ->map(fn ($payment) => [
                'id' => (int) $payment->id,
                'student_name' => $payment->student_name,
                'created_at' => $payment->created_at,
                'amount' => (float) $payment->amount,
                'currency' => $payment->currency,
                'status' => $payment->status,
            ])
            ->values();

        $paymentBalances = [
            'due' => ['count' => 0, 'amount' => 0.0],
            'late' => ['count' => 0, 'amount' => 0.0],
        ];
        $balanceRows = DB::table('payments')
            ->where('school_id', $schoolId)
            ->where('year_id', $yearId)
            ->whereIn('status', ['due', 'late'])
            ->select('status', DB::raw('COUNT(*) AS payment_count'), DB::raw('SUM(amount) AS total_amount'))
            ->groupBy('status')
            ->get();

        foreach ($balanceRows as $row) {
            $paymentBalances[$row->status] = [
                'count' => (int) $row->payment_count,
                'amount' => (float) $row->total_amount,
            ];
        }

        $repeatedAbsenceCount = DB::table('attendance')
            ->join('students', function (JoinClause $join) {
                $join->on('students.id', '=', 'attendance.student_id')
                    ->on('students.school_id', '=', 'attendance.school_id')
                    ->on('students.year_id', '=', 'attendance.year_id');
            })
            ->where('attendance.school_id', $schoolId)
            ->where('attendance.year_id', $yearId)
            ->where('attendance.status', 'absent')
            ->where('students.status', 'active')
            ->groupBy('students.id')
            ->havingRaw('COUNT(*) >= ?', [3])
            ->get(['students.id'])
            ->count();

        return response()->json([
            'year_id' => $yearId,
            'year_label' => $year->year_label,
            'currency' => DB::table('schools')->where('id', $schoolId)->value('currency') ?? 'MAD',
            'stats' => [
                'active_students' => $this->studentRecordsQuery($schoolId, $yearId)
                    ->where('status', 'active')
                    ->count(),
                'new_students' => $newStudentCount,
                'incomplete_students' => $incompleteStudentCount,
                'attendance_follow_up' => $attendanceFollowUpCount,
                'payment_balances' => $paymentBalances,
            ],
            'new_or_incomplete_students' => $studentsToReview,
            'attendance_follow_up_items' => $attendanceFollowUpItems,
            'recent_payments' => $recentPayments,
            'alerts' => [
                'late_payments' => $paymentBalances['late']['count'],
                'repeated_absences' => $repeatedAbsenceCount,
                'incomplete_student_records' => $incompleteStudentCount,
            ],
        ]);
    }

    private function studentRecordsQuery(int $schoolId, int $yearId): Builder
    {
        return DB::table('students')
            ->where('school_id', $schoolId)
            ->where('year_id', $yearId)
            ->whereIn('status', ['active', 'paused']);
    }

    private function incompleteStudentsQuery(int $schoolId, int $yearId): Builder
    {
        return $this->studentRecordsQuery($schoolId, $yearId)
            ->where(function (Builder $query) {
                $this->applyIncompleteStudentFilter($query);
            });
    }

    private function applyIncompleteStudentFilter(Builder $query): void
    {
        foreach (['student_number', 'guardian_phone', 'guardian_email'] as $field) {
            $query->orWhere(function (Builder $missingField) use ($field) {
                $missingField->whereNull($field)->orWhere($field, '');
            });
        }
    }
}
