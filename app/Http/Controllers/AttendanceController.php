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
use Illuminate\Validation\ValidationException;
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
        $students = DB::table('students')
            ->where('school_id', $schoolId)
            ->where('year_id', $yearId)
            ->when($user->role === 'teacher', function (Builder $query) use ($schoolId, $yearId, $user) {
                $today = now()->toDateString();
                $query
                    ->whereIn('status', ['active', 'paused'])
                    ->whereExists(function (Builder $assignments) use ($schoolId, $yearId, $user, $today) {
                        $assignments->selectRaw('1')
                            ->from('room_teachers')
                            ->join('rooms', function (JoinClause $join) {
                                $join->on('rooms.id', '=', 'room_teachers.room_id')
                                    ->on('rooms.school_id', '=', 'room_teachers.school_id')
                                    ->on('rooms.year_id', '=', 'room_teachers.year_id');
                            })
                            ->whereColumn('room_teachers.room_id', 'students.room_id')
                            ->where('room_teachers.school_id', $schoolId)
                            ->where('room_teachers.year_id', $yearId)
                            ->where('room_teachers.teacher_id', $user->id)
                            ->where('room_teachers.assigned_from', '<=', $today)
                            ->where(function (Builder $dates) use ($today) {
                                $dates->whereNull('room_teachers.assigned_until')
                                    ->orWhere('room_teachers.assigned_until', '>=', $today);
                            })
                            ->where('rooms.is_active', true);
                    });
            })
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
                'attendance.recorded_by',
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
                'can_edit' => $user->role === 'admin'
                    || $user->role === 'secretary'
                    || (
                        $user->role === 'teacher'
                        && (int) $record->recorded_by === (int) $user->id
                        && $record->attendance_date === now()->toDateString()
                    ),
            ])
            ->values();

        return response()->json([
            'year_id' => $yearId,
            'today' => now()->toDateString(),
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

    public function store(Request $request): JsonResponse
    {
        [$user, $schoolId] = $this->staffContext();
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
            ->first(['start_date', 'end_date']);
        abort_unless($year, 404);

        $validated = $request->validate([
            'year_id' => ['required', 'integer'],
            'student_id' => [
                'required',
                'integer',
                Rule::exists('students', 'id')
                    ->where('school_id', $schoolId)
                    ->where('year_id', $yearId),
            ],
            'attendance_date' => ['required', 'date_format:Y-m-d'],
            'status' => $user->role === 'secretary'
                ? ['prohibited']
                : ['required', Rule::in(self::STATUSES)],
            'note' => $user->role === 'secretary'
                ? ['required', 'string', 'max:500']
                : ['nullable', 'string', 'max:500'],
        ]);

        if (
            $validated['attendance_date'] < $year->start_date
            || $validated['attendance_date'] > $year->end_date
        ) {
            throw ValidationException::withMessages([
                'attendance_date' => 'The attendance date must belong to the selected academic year.',
            ]);
        }

        if ($user->role === 'teacher' && $validated['attendance_date'] !== now()->toDateString()) {
            throw ValidationException::withMessages([
                'attendance_date' => 'Teachers may correct attendance only on the attendance date.',
            ]);
        }

        $result = DB::transaction(function () use ($validated, $user, $schoolId, $yearId) {
            $student = DB::table('students')
                ->where('id', $validated['student_id'])
                ->where('school_id', $schoolId)
                ->where('year_id', $yearId)
                ->lockForUpdate()
                ->first(['id', 'room_id', 'status']);
            abort_unless($student, 404);

            $existing = DB::table('attendance')
                ->where('student_id', $student->id)
                ->where('school_id', $schoolId)
                ->where('year_id', $yearId)
                ->where('attendance_date', $validated['attendance_date'])
                ->lockForUpdate()
                ->first(['id', 'room_id', 'recorded_by']);

            if ($user->role === 'secretary') {
                abort_unless($existing, 404);

                DB::table('attendance')
                    ->where('id', $existing->id)
                    ->where('school_id', $schoolId)
                    ->update(['note' => $validated['note']]);

                return ['attendance_id' => (int) $existing->id, 'created' => false];
            }

            if ($user->role === 'admin') {
                abort_unless($existing, 404);
            }

            $roomId = $existing?->room_id ?? $student->room_id;

            if ($user->role === 'teacher') {
                abort_unless(in_array($student->status, ['active', 'paused'], true), 403);
                abort_unless(
                    ! $existing || (int) $existing->recorded_by === (int) $user->id,
                    403,
                );
                abort_unless(
                    $roomId !== null
                        && $this->teacherOwnsActiveRoom(
                            (int) $user->id,
                            (int) $roomId,
                            $schoolId,
                            $yearId,
                            $validated['attendance_date'],
                        ),
                    403,
                );
            }

            $now = now();
            DB::table('attendance')->upsert(
                [[
                    'student_id' => $student->id,
                    'school_id' => $schoolId,
                    'room_id' => $roomId,
                    'year_id' => $yearId,
                    'recorded_by' => $existing?->recorded_by ?? $user->id,
                    'attendance_date' => $validated['attendance_date'],
                    'status' => $validated['status'],
                    'note' => $validated['note'] ?? null,
                    'created_at' => $existing ? null : $now,
                ]],
                ['student_id', 'year_id', 'attendance_date'],
                ['room_id', 'status', 'note'],
            );

            $attendanceId = DB::table('attendance')
                ->where('student_id', $student->id)
                ->where('year_id', $yearId)
                ->where('attendance_date', $validated['attendance_date'])
                ->value('id');

            return ['attendance_id' => (int) $attendanceId, 'created' => ! $existing];
        });

        return response()->json($result, $result['created'] ? 201 : 200);
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
                ->join('rooms', function (JoinClause $join) {
                    $join->on('rooms.id', '=', 'room_teachers.room_id')
                        ->on('rooms.school_id', '=', 'room_teachers.school_id')
                        ->on('rooms.year_id', '=', 'room_teachers.year_id');
                })
                ->whereColumn('room_teachers.room_id', 'attendance.room_id')
                ->whereColumn('room_teachers.school_id', 'attendance.school_id')
                ->whereColumn('room_teachers.year_id', 'attendance.year_id')
                ->where('room_teachers.teacher_id', $teacherId)
                ->whereColumn('room_teachers.assigned_from', '<=', 'attendance.attendance_date')
                ->where('rooms.is_active', true)
                ->where(function (Builder $dates) {
                    $dates->whereNull('room_teachers.assigned_until')
                        ->orWhereColumn('room_teachers.assigned_until', '>=', 'attendance.attendance_date');
                });
        });
    }

    private function teacherOwnsActiveRoom(
        int $teacherId,
        int $roomId,
        int $schoolId,
        int $yearId,
        string $attendanceDate,
    ): bool {
        return DB::table('room_teachers')
            ->join('rooms', function (JoinClause $join) {
                $join->on('rooms.id', '=', 'room_teachers.room_id')
                    ->on('rooms.school_id', '=', 'room_teachers.school_id')
                    ->on('rooms.year_id', '=', 'room_teachers.year_id');
            })
            ->where('room_teachers.teacher_id', $teacherId)
            ->where('room_teachers.room_id', $roomId)
            ->where('room_teachers.school_id', $schoolId)
            ->where('room_teachers.year_id', $yearId)
            ->where('room_teachers.assigned_from', '<=', $attendanceDate)
            ->where(function (Builder $dates) use ($attendanceDate) {
                $dates->whereNull('room_teachers.assigned_until')
                    ->orWhere('room_teachers.assigned_until', '>=', $attendanceDate);
            })
            ->where('rooms.is_active', true)
            ->exists();
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
