<?php

namespace App\Http\Controllers;

use App\Actions\GetRepeatedAbsenceAlerts;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SecretaryDashboardController extends Controller
{
    public function __invoke(
        Request $request,
        GetRepeatedAbsenceAlerts $getRepeatedAbsenceAlerts,
    ): JsonResponse {
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
        $recentStudentCutoff = now()->subDays(29)->startOfDay()->toDateTimeString();

        $incompleteStudentCount = $this->incompleteStudentsQuery($schoolId, $yearId)->count();
        $studentRecordGaps = $this->studentRecordsQuery($schoolId, $yearId)
            ->selectRaw("
                SUM(CASE WHEN student_number IS NULL OR TRIM(student_number) = '' THEN 1 ELSE 0 END) AS student_number,
                SUM(CASE WHEN guardian_phone IS NULL OR TRIM(guardian_phone) = '' THEN 1 ELSE 0 END) AS guardian_phone,
                SUM(CASE WHEN guardian_email IS NULL OR TRIM(guardian_email) = '' THEN 1 ELSE 0 END) AS guardian_email
            ")
            ->first();
        $newStudentCount = $this->studentRecordsQuery($schoolId, $yearId)
            ->where('created_at', '>=', $recentStudentCutoff)
            ->count();

        $studentsToComplete = $this->incompleteStudentsQuery($schoolId, $yearId)
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

        $latePaymentStudents = DB::table('payments')
            ->join('students', function (JoinClause $join) {
                $join->on('students.id', '=', 'payments.student_id')
                    ->on('students.school_id', '=', 'payments.school_id')
                    ->on('students.year_id', '=', 'payments.year_id');
            })
            ->where('payments.school_id', $schoolId)
            ->where('payments.year_id', $yearId)
            ->where('payments.status', 'late')
            ->groupBy('students.id', 'students.full_name', 'students.student_number')
            ->selectRaw('students.id AS student_id, students.full_name AS student_name, students.student_number AS student_number, COUNT(*) AS late_count, SUM(payments.amount) AS total_amount')
            ->orderByDesc('total_amount')
            ->limit(6)
            ->get()
            ->map(fn ($row) => [
                'student_id' => (int) $row->student_id,
                'student_name' => $row->student_name,
                'student_number' => $row->student_number,
                'late_count' => (int) $row->late_count,
                'total_amount' => (float) $row->total_amount,
            ])
            ->values();

        $paymentBalances = [
            'due' => ['count' => 0, 'amount' => 0.0],
            'late' => ['count' => 0, 'amount' => 0.0],
            'waived' => ['count' => 0, 'amount' => 0.0],
        ];
        $balanceRows = DB::table('payments')
            ->where('school_id', $schoolId)
            ->where('year_id', $yearId)
            ->whereIn('status', ['due', 'late', 'waived'])
            ->select('status', DB::raw('COUNT(*) AS payment_count'), DB::raw('SUM(amount) AS total_amount'))
            ->groupBy('status')
            ->get();

        foreach ($balanceRows as $row) {
            $paymentBalances[$row->status] = [
                'count' => (int) $row->payment_count,
                'amount' => (float) $row->total_amount,
            ];
        }

        $repeatedAbsenceItems = $getRepeatedAbsenceAlerts($schoolId, $yearId);

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
                'attendance_follow_up' => $repeatedAbsenceItems->count(),
                'payment_balances' => $paymentBalances,
            ],
            'student_record_gaps' => [
                'student_number' => (int) ($studentRecordGaps->student_number ?? 0),
                'guardian_phone' => (int) ($studentRecordGaps->guardian_phone ?? 0),
                'guardian_email' => (int) ($studentRecordGaps->guardian_email ?? 0),
            ],
            'students_to_complete' => $studentsToComplete,
            'late_payment_students' => $latePaymentStudents,
            'repeated_absence_items' => $repeatedAbsenceItems,
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
                $missingField->whereNull($field)
                    ->orWhereRaw("TRIM({$field}) = ''");
            });
        }
    }
}
