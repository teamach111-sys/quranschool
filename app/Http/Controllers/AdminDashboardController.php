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

        $today = now()->toDateString();
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

        $latePayments = DB::table('payments')
            ->join('students', function ($join) {
                $join->on('students.id', '=', 'payments.student_id')
                    ->on('students.school_id', '=', 'payments.school_id')
                    ->on('students.year_id', '=', 'payments.year_id');
            })
            ->where('payments.school_id', $schoolId)
            ->where('payments.year_id', $yearId)
            ->where('payments.status', 'late')
            ->orderBy('payments.period_end')
            ->orderBy('students.full_name')
            ->get([
                'payments.id',
                'students.full_name as student_name',
                'students.student_number',
                'payments.period_start',
                'payments.period_end',
                'payments.amount',
                'payments.currency',
            ]);

        $repeatedAbsences = DB::table('attendance')
            ->join('students', function ($join) {
                $join->on('students.id', '=', 'attendance.student_id')
                    ->on('students.school_id', '=', 'attendance.school_id')
                    ->on('students.year_id', '=', 'attendance.year_id');
            })
            ->leftJoin('rooms', function ($join) {
                $join->on('rooms.id', '=', 'students.room_id')
                    ->on('rooms.school_id', '=', 'students.school_id')
                    ->on('rooms.year_id', '=', 'students.year_id');
            })
            ->where('attendance.school_id', $schoolId)
            ->where('attendance.year_id', $yearId)
            ->where('attendance.status', 'absent')
            ->where('students.status', 'active')
            ->groupBy('students.id', 'students.full_name', 'students.student_number', 'rooms.name')
            ->havingRaw('COUNT(*) >= ?', [3])
            ->orderByDesc('absence_count')
            ->orderBy('students.full_name')
            ->get([
                'students.id as student_id',
                'students.full_name as student_name',
                'students.student_number',
                'rooms.name as room_name',
                DB::raw('COUNT(*) AS absence_count'),
            ]);

        $roomsWithoutActiveTeacher = DB::table('rooms')
            ->where('rooms.school_id', $schoolId)
            ->where('rooms.year_id', $yearId)
            ->where('rooms.is_active', true)
            ->whereNotExists(function ($query) use ($schoolId, $yearId, $today) {
                $query->select(DB::raw(1))
                    ->from('room_teachers as assignments')
                    ->join('users as teachers', 'teachers.id', '=', 'assignments.teacher_id')
                    ->join('roles as teacher_roles', 'teacher_roles.id', '=', 'teachers.role_id')
                    ->whereColumn('assignments.room_id', 'rooms.id')
                    ->where('assignments.school_id', $schoolId)
                    ->where('assignments.year_id', $yearId)
                    ->where('teachers.school_id', $schoolId)
                    ->where('teachers.is_active', true)
                    ->where('teacher_roles.role_key', 'teacher')
                    ->where('assignments.assigned_from', '<=', $today)
                    ->where(function ($dates) use ($today) {
                        $dates->whereNull('assignments.assigned_until')
                            ->orWhere('assignments.assigned_until', '>=', $today);
                    });
            })
            ->orderBy('rooms.name')
            ->get(['rooms.id as room_id', 'rooms.name as room_name']);

        $inactiveUsersWithAssignments = DB::table('room_teachers as assignments')
            ->join('users as teachers', 'teachers.id', '=', 'assignments.teacher_id')
            ->join('roles as teacher_roles', 'teacher_roles.id', '=', 'teachers.role_id')
            ->join('rooms', function ($join) {
                $join->on('rooms.id', '=', 'assignments.room_id')
                    ->on('rooms.school_id', '=', 'assignments.school_id')
                    ->on('rooms.year_id', '=', 'assignments.year_id');
            })
            ->where('assignments.school_id', $schoolId)
            ->where('assignments.year_id', $yearId)
            ->where('teachers.school_id', $schoolId)
            ->where('teachers.is_active', false)
            ->where('teacher_roles.role_key', 'teacher')
            ->where('rooms.is_active', true)
            ->where('assignments.assigned_from', '<=', $today)
            ->where(function ($dates) use ($today) {
                $dates->whereNull('assignments.assigned_until')
                    ->orWhere('assignments.assigned_until', '>=', $today);
            })
            ->groupBy('teachers.id', 'teachers.full_name')
            ->get([
                'teachers.id as user_id',
                'teachers.full_name as user_name',
                DB::raw('COUNT(DISTINCT rooms.id) AS active_assignments'),
            ]);

        $inactiveUsersWithSchedules = DB::table('schedules')
            ->join('users as teachers', 'teachers.id', '=', 'schedules.teacher_id')
            ->join('roles as teacher_roles', 'teacher_roles.id', '=', 'teachers.role_id')
            ->join('rooms', function ($join) {
                $join->on('rooms.id', '=', 'schedules.room_id')
                    ->on('rooms.school_id', '=', 'schedules.school_id')
                    ->on('rooms.year_id', '=', 'schedules.year_id');
            })
            ->where('schedules.school_id', $schoolId)
            ->where('schedules.year_id', $yearId)
            ->where('teachers.school_id', $schoolId)
            ->where('teachers.is_active', false)
            ->where('teacher_roles.role_key', 'teacher')
            ->where('rooms.is_active', true)
            ->where(function ($dates) use ($today) {
                $dates->whereNull('schedules.valid_until')
                    ->orWhere('schedules.valid_until', '>=', $today);
            })
            ->groupBy('teachers.id', 'teachers.full_name')
            ->get([
                'teachers.id as user_id',
                'teachers.full_name as user_name',
                DB::raw('COUNT(*) AS future_schedules'),
            ]);

        $inactiveUsersWithPendingItems = [];

        foreach ($inactiveUsersWithAssignments as $inactiveUser) {
            $inactiveUsersWithPendingItems[$inactiveUser->user_id] = [
                'user_id' => (int) $inactiveUser->user_id,
                'user_name' => $inactiveUser->user_name,
                'active_assignments' => (int) $inactiveUser->active_assignments,
                'future_schedules' => 0,
            ];
        }

        foreach ($inactiveUsersWithSchedules as $inactiveUser) {
            $inactiveUsersWithPendingItems[$inactiveUser->user_id] ??= [
                'user_id' => (int) $inactiveUser->user_id,
                'user_name' => $inactiveUser->user_name,
                'active_assignments' => 0,
                'future_schedules' => 0,
            ];
            $inactiveUsersWithPendingItems[$inactiveUser->user_id]['future_schedules'] = (int) $inactiveUser->future_schedules;
        }

        $inactiveUsersWithPendingItems = array_values($inactiveUsersWithPendingItems);
        usort($inactiveUsersWithPendingItems, fn ($first, $second) => strcmp($first['user_name'], $second['user_name']));

        return response()->json([
            'year_label' => $year->year_label,
            'currency' => DB::table('schools')->where('id', $schoolId)->value('currency') ?? 'MAD',
            'alerts' => [
                'late_payments' => $latePayments,
                'repeated_absences' => $repeatedAbsences,
                'rooms_without_active_teacher' => $roomsWithoutActiveTeacher,
                'inactive_users_with_pending_items' => $inactiveUsersWithPendingItems,
            ],
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
