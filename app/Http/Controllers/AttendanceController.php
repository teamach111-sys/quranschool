<?php

namespace App\Http\Controllers;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use stdClass;

class AttendanceController extends Controller
{
    private const STATUSES = ['present', 'absent', 'late', 'excused'];

    public function table(Request $request): JsonResponse
    {
        [$user, $schoolId] = $this->staffContext();
        $year = $this->resolveYear($request, $schoolId);
        $yearId = (int) $year->id;
        $filters = $request->validate([
            'room_id' => [
                'nullable',
                'integer',
                Rule::exists('rooms', 'id')
                    ->where('school_id', $schoolId)
                    ->where('year_id', $yearId),
            ],
            'teacher_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')
                    ->where('school_id', $schoolId)
                    ->where('role_id', DB::table('roles')->where('role_key', 'teacher')->value('id')),
            ],
            'student_id' => [
                'nullable',
                'integer',
                Rule::exists('students', 'id')
                    ->where('school_id', $schoolId)
                    ->where('year_id', $yearId),
            ],
            'attendance_date' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
        ]);

        $rooms = $this->roomsForYear($schoolId, $yearId, $user->role, $user->id, $year);
        $roomIds = $rooms->pluck('id')->all();
        $students = DB::table('students')
            ->where('school_id', $schoolId)
            ->where('year_id', $yearId)
            ->when($user->role === 'teacher', fn (Builder $query) => $query->whereIn('room_id', $roomIds ?: [0]))
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'student_number', 'room_id'])
            ->map(fn ($student) => [
                'id' => (int) $student->id,
                'name' => $student->full_name,
                'student_number' => $student->student_number,
                'room_id' => $student->room_id === null ? null : (int) $student->room_id,
            ])
            ->values();
        $teachers = $this->teachersForYear($schoolId, $yearId, $user->role, $user->id, $year);

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
            ->leftJoin('room_teachers as assignments', function (JoinClause $join) {
                $join->on('assignments.room_id', '=', 'attendance.room_id')
                    ->on('assignments.school_id', '=', 'attendance.school_id')
                    ->on('assignments.year_id', '=', 'attendance.year_id')
                    ->whereColumn('assignments.assigned_from', '<=', 'attendance.attendance_date')
                    ->where(function (JoinClause $dates) {
                        $dates->whereNull('assignments.assigned_until')
                            ->orWhereColumn('assignments.assigned_until', '>=', 'attendance.attendance_date');
                    });
            })
            ->leftJoin('users as teachers', function (JoinClause $join) {
                $join->on('teachers.id', '=', 'assignments.teacher_id')
                    ->on('teachers.school_id', '=', 'assignments.school_id');
            })
            ->where('attendance.school_id', $schoolId)
            ->where('attendance.year_id', $yearId);

        if ($user->role === 'teacher') {
            $this->applyTeacherRoomScope($attendanceQuery, (int) $user->id);
        }

        if (! empty($filters['room_id'])) {
            $attendanceQuery->where('attendance.room_id', (int) $filters['room_id']);
        }
        if (! empty($filters['teacher_id'])) {
            $attendanceQuery->where('assignments.teacher_id', (int) $filters['teacher_id']);
        }
        if (! empty($filters['student_id'])) {
            $attendanceQuery->where('attendance.student_id', (int) $filters['student_id']);
        }
        if (! empty($filters['attendance_date'])) {
            $attendanceQuery->where('attendance.attendance_date', $filters['attendance_date']);
        }
        if (! empty($filters['status'])) {
            $attendanceQuery->where('attendance.status', $filters['status']);
        }

        $attendance = $attendanceQuery
            ->orderByDesc('attendance.attendance_date')
            ->orderBy('students.full_name')
            ->orderBy('attendance.id')
            ->get([
                'attendance.id',
                'attendance.student_id',
                'attendance.room_id',
                'attendance.attendance_date',
                'attendance.status',
                'attendance.note',
                'students.full_name as student_name',
                'students.student_number',
                'rooms.name as room_name',
                'assignments.teacher_id',
                'teachers.full_name as teacher_name',
            ])
            ->map(fn ($record) => [
                'id' => (int) $record->id,
                'student_id' => (int) $record->student_id,
                'student_name' => $record->student_name,
                'student_number' => $record->student_number,
                'room_id' => $record->room_id === null ? null : (int) $record->room_id,
                'room_name' => $record->room_name,
                'teacher_id' => $record->teacher_id === null ? null : (int) $record->teacher_id,
                'teacher_name' => $record->teacher_name,
                'attendance_date' => $record->attendance_date,
                'status' => $record->status,
                'note' => $record->note,
            ])
            ->values();

        return response()->json([
            'year_id' => $yearId,
            'attendance' => $attendance,
            'rooms' => $rooms,
            'teachers' => $teachers,
            'students' => $students,
            'statuses' => self::STATUSES,
            'can_correct' => $user->role === 'admin',
            'can_mark' => $user->role === 'teacher',
            'can_follow_up' => $user->role === 'secretary',
        ]);
    }

    private function roomsForYear(int $schoolId, int $yearId, string $role, int $userId, stdClass $year): Collection
    {
        return DB::table('rooms')
            ->where('school_id', $schoolId)
            ->where('year_id', $yearId)
            ->when($role === 'teacher', function (Builder $query) use ($schoolId, $yearId, $userId, $year) {
                $query->whereExists(function (Builder $assignments) use ($schoolId, $yearId, $userId, $year) {
                    $assignments->selectRaw('1')
                        ->from('room_teachers')
                        ->whereColumn('room_teachers.room_id', 'rooms.id')
                        ->where('room_teachers.school_id', $schoolId)
                        ->where('room_teachers.year_id', $yearId)
                        ->where('room_teachers.teacher_id', $userId)
                        ->where('room_teachers.assigned_from', '<=', $year->end_date)
                        ->where(function (Builder $dates) use ($year) {
                            $dates->whereNull('room_teachers.assigned_until')
                                ->orWhere('room_teachers.assigned_until', '>=', $year->start_date);
                        });
                });
            })
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($room) => ['id' => (int) $room->id, 'name' => $room->name])
            ->values();
    }

    private function teachersForYear(int $schoolId, int $yearId, string $role, int $userId, stdClass $year): Collection
    {
        return DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('users.school_id', $schoolId)
            ->where('roles.role_key', 'teacher')
            ->whereExists(function (Builder $assignments) use ($schoolId, $yearId, $role, $userId, $year) {
                $assignments->selectRaw('1')
                    ->from('room_teachers')
                    ->whereColumn('room_teachers.teacher_id', 'users.id')
                    ->where('room_teachers.school_id', $schoolId)
                    ->where('room_teachers.year_id', $yearId)
                    ->where('room_teachers.assigned_from', '<=', $year->end_date)
                    ->where(function (Builder $dates) use ($year) {
                        $dates->whereNull('room_teachers.assigned_until')
                            ->orWhere('room_teachers.assigned_until', '>=', $year->start_date);
                    })
                    ->when($role === 'teacher', function (Builder $query) use ($schoolId, $yearId, $userId, $year) {
                        $query->whereExists(function (Builder $ownAssignments) use ($schoolId, $yearId, $userId, $year) {
                            $ownAssignments->selectRaw('1')
                                ->from('room_teachers as own_room_teachers')
                                ->whereColumn('own_room_teachers.room_id', 'room_teachers.room_id')
                                ->where('own_room_teachers.school_id', $schoolId)
                                ->where('own_room_teachers.year_id', $yearId)
                                ->where('own_room_teachers.teacher_id', $userId)
                                ->where('own_room_teachers.assigned_from', '<=', $year->end_date)
                                ->where(function (Builder $ownDates) use ($year) {
                                    $ownDates->whereNull('own_room_teachers.assigned_until')
                                        ->orWhere('own_room_teachers.assigned_until', '>=', $year->start_date);
                                });
                        });
                    });
            })
            ->orderBy('users.full_name')
            ->get(['users.id', 'users.full_name'])
            ->map(fn ($teacher) => ['id' => (int) $teacher->id, 'name' => $teacher->full_name])
            ->values();
    }

    private function applyTeacherRoomScope(Builder $query, int $teacherId): void
    {
        $query->whereExists(function (Builder $assignments) use ($teacherId) {
            $assignments->selectRaw('1')
                ->from('room_teachers')
                ->whereColumn('room_teachers.room_id', 'attendance.room_id')
                ->whereColumn('room_teachers.school_id', 'attendance.school_id')
                ->whereColumn('room_teachers.year_id', 'attendance.year_id')
                ->where('room_teachers.teacher_id', $teacherId)
                ->whereColumn('room_teachers.assigned_from', '<=', 'attendance.attendance_date')
                ->where(function (Builder $dates) {
                    $dates->whereNull('room_teachers.assigned_until')
                        ->orWhereColumn('room_teachers.assigned_until', '>=', 'attendance.attendance_date');
                });
        });
    }

    /**
     * @return array{0: object, 1: int}
     */
    private function staffContext(): array
    {
        $user = Auth::user();
        $schoolId = data_get($user, 'school_id');
        abort_unless(
            $user && in_array(data_get($user, 'role'), ['admin', 'teacher', 'secretary'], true) && $schoolId,
            403,
        );

        return [$user, (int) $schoolId];
    }

    private function resolveYear(Request $request, int $schoolId): stdClass
    {
        $query = DB::table('academic_years')->where('school_id', $schoolId);
        $yearId = $request->input('year_id');

        if ($yearId !== null) {
            $query->where('id', $yearId);
        } else {
            $query->where('is_current', true)->where('is_active', true);
        }

        $year = $query->orderByDesc('start_date')->first(['id', 'start_date', 'end_date']);
        abort_unless($year, 404);

        return $year;
    }
}
