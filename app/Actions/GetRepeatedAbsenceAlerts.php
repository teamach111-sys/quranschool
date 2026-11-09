<?php

namespace App\Actions;

use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GetRepeatedAbsenceAlerts
{
    public const ABSENCE_ALERT_THRESHOLD = 3;

    /**
     * @return Collection<int, array{student_id: int, student_name: string, student_number: ?string, room_name: ?string, absence_count: int, latest_attendance_date: string}>
     */
    public function __invoke(int $schoolId, int $yearId): Collection
    {
        $today = now();
        $startDate = $today->copy()->startOfMonth()->toDateString();
        $endDate = $today->toDateString();

        return DB::table('attendance')
            ->join('students', function (JoinClause $join) {
                $join->on('students.id', '=', 'attendance.student_id')
                    ->on('students.school_id', '=', 'attendance.school_id')
                    ->on('students.year_id', '=', 'attendance.year_id');
            })
            ->leftJoin('rooms', function (JoinClause $join) {
                $join->on('rooms.id', '=', 'students.room_id')
                    ->on('rooms.school_id', '=', 'students.school_id')
                    ->on('rooms.year_id', '=', 'students.year_id');
            })
            ->where('attendance.school_id', $schoolId)
            ->where('attendance.year_id', $yearId)
            ->whereBetween('attendance.attendance_date', [$startDate, $endDate])
            ->where('attendance.status', 'absent')
            ->where('students.status', 'active')
            ->groupBy('students.id', 'students.full_name', 'students.student_number', 'rooms.name')
            ->havingRaw('COUNT(*) > ?', [self::ABSENCE_ALERT_THRESHOLD])
            ->orderByDesc('absence_count')
            ->orderBy('students.full_name')
            ->get([
                'students.id as student_id',
                'students.full_name as student_name',
                'students.student_number',
                'rooms.name as room_name',
                DB::raw('COUNT(*) AS absence_count'),
                DB::raw('MAX(attendance.attendance_date) AS latest_attendance_date'),
            ])
            ->map(fn ($student) => [
                'student_id' => (int) $student->student_id,
                'student_name' => $student->student_name,
                'student_number' => $student->student_number,
                'room_name' => $student->room_name,
                'absence_count' => (int) $student->absence_count,
                'latest_attendance_date' => $student->latest_attendance_date,
            ])
            ->values();
    }
}
