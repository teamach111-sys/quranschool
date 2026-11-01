<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $validated = $request->validate([
            'year_id' => ['required', 'integer', 'min:1'],
        ]);

        $user = Auth::user();
        $schoolId = data_get($user, 'school_id');

        abort_unless(data_get($user, 'role') === 'admin' && $schoolId, 403);

        $yearId = (int) $validated['year_id'];
        $year = DB::table('academic_years')
            ->where('school_id', $schoolId)
            ->where('id', $yearId)
            ->first(['year_label']);

        abort_unless($year, 404);

        $attendanceStatuses = ['present', 'absent', 'late', 'excused'];
        $attendanceTotals = array_fill_keys($attendanceStatuses, 0);
        $attendanceRows = DB::table('attendance')
            ->where('school_id', $schoolId)
            ->where('year_id', $yearId)
            ->select('status', DB::raw('COUNT(*) AS total'))
            ->groupBy('status')
            ->get();

        foreach ($attendanceRows as $row) {
            $attendanceTotals[$row->status] = (int) $row->total;
        }

        $attendanceTotal = array_sum($attendanceTotals);
        $attendance = [];

        foreach ($attendanceTotals as $status => $total) {
            $attendance[$status] = [
                'count' => $total,
                'share' => $attendanceTotal > 0 ? $total / $attendanceTotal : 0,
            ];
        }

        $paymentStatuses = ['paid', 'due', 'late', 'waived'];
        $payments = array_fill_keys($paymentStatuses, ['amount' => 0.0]);
        $paymentRows = DB::table('payments')
            ->where('school_id', $schoolId)
            ->where('year_id', $yearId)
            ->select('status', DB::raw('SUM(amount) AS amount'))
            ->groupBy('status')
            ->get();

        foreach ($paymentRows as $row) {
            $payments[$row->status] = [
                'amount' => (float) $row->amount,
            ];
        }

        return response()->json([
            'year_label' => $year->year_label,
            'currency' => DB::table('schools')->where('id', $schoolId)->value('currency') ?? 'MAD',
            'stats' => [
                'active_students' => DB::table('students')
                    ->where('school_id', $schoolId)
                    ->where('year_id', $yearId)
                    ->where('status', 'active')
                    ->count(),
                'active_staff' => DB::table('users')
                    ->where('school_id', $schoolId)
                    ->where('is_active', true)
                    ->count(),
                'active_rooms' => DB::table('rooms')
                    ->where('school_id', $schoolId)
                    ->where('year_id', $yearId)
                    ->where('is_active', true)
                    ->count(),
                'attendance' => $attendance,
                'payments' => $payments,
            ],
        ]);
    }
}
