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

class MemorizationSessionsController extends Controller
{
    public function table(Request $request): JsonResponse
    {
        [$user, $schoolId] = $this->staffContext();
        $year = $this->resolveYear($request, $schoolId, $user->role);
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
            'session_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $sessionsQuery = DB::table('memorization_sessions')
            ->join('students', function (JoinClause $join) {
                $join->on('students.id', '=', 'memorization_sessions.student_id')
                    ->on('students.school_id', '=', 'memorization_sessions.school_id')
                    ->on('students.year_id', '=', 'memorization_sessions.year_id');
            })
            ->leftJoin('rooms', function (JoinClause $join) {
                $join->on('rooms.id', '=', 'memorization_sessions.room_id')
                    ->on('rooms.school_id', '=', 'memorization_sessions.school_id')
                    ->on('rooms.year_id', '=', 'memorization_sessions.year_id');
            })
            ->leftJoin('users as teachers', function (JoinClause $join) {
                $join->on('teachers.id', '=', 'memorization_sessions.teacher_id')
                    ->on('teachers.school_id', '=', 'memorization_sessions.school_id');
            })
            ->where('memorization_sessions.school_id', $schoolId)
            ->where('memorization_sessions.year_id', $yearId);

        if ($user->role === 'teacher') {
            $this->applyTeacherRoomScope($sessionsQuery, (int) $user->id);
        }

        if (! empty($filters['room_id'])) {
            $sessionsQuery->where('memorization_sessions.room_id', (int) $filters['room_id']);
        }
        if (! empty($filters['teacher_id'])) {
            $sessionsQuery->where('memorization_sessions.teacher_id', (int) $filters['teacher_id']);
        }
        if (! empty($filters['student_id'])) {
            $sessionsQuery->where('memorization_sessions.student_id', (int) $filters['student_id']);
        }
        if (! empty($filters['session_date'])) {
            $sessionsQuery->where('memorization_sessions.session_date', $filters['session_date']);
        }

        $sessions = $sessionsQuery
            ->orderByDesc('memorization_sessions.session_date')
            ->orderBy('students.full_name')
            ->orderBy('memorization_sessions.id')
            ->get([
                'memorization_sessions.id',
                'memorization_sessions.student_id',
                'memorization_sessions.room_id',
                'memorization_sessions.teacher_id',
                'memorization_sessions.session_date',
                'memorization_sessions.surah_start',
                'memorization_sessions.ayah_start',
                'memorization_sessions.surah_end',
                'memorization_sessions.ayah_end',
                'memorization_sessions.portion_label',
                'memorization_sessions.evaluation',
                'memorization_sessions.notes',
                'students.full_name as student_name',
                'students.student_number',
                'rooms.name as room_name',
                'teachers.full_name as teacher_name',
            ])
            ->map(fn ($session) => [
                'id' => (int) $session->id,
                'student_id' => (int) $session->student_id,
                'student_name' => $session->student_name,
                'student_number' => $session->student_number,
                'room_id' => $session->room_id === null ? null : (int) $session->room_id,
                'room_name' => $session->room_name,
                'teacher_id' => $session->teacher_id === null ? null : (int) $session->teacher_id,
                'teacher_name' => $session->teacher_name,
                'session_date' => $session->session_date,
                'surah_start' => $session->surah_start === null ? null : (int) $session->surah_start,
                'ayah_start' => $session->ayah_start === null ? null : (int) $session->ayah_start,
                'surah_end' => $session->surah_end === null ? null : (int) $session->surah_end,
                'ayah_end' => $session->ayah_end === null ? null : (int) $session->ayah_end,
                'portion_label' => $session->portion_label,
                'evaluation' => $session->evaluation,
                'notes' => $session->notes,
                'can_edit' => (bool) $year->is_active
                    && (bool) $year->is_current
                    && ($user->role === 'admin' || (int) $session->teacher_id === (int) $user->id),
            ])
            ->values();

        return response()->json([
            'year_id' => $yearId,
            'year_start' => $year->start_date,
            'year_end' => $year->end_date,
            'today' => now()->toDateString(),
            'year_is_active' => (bool) $year->is_active,
            'year_is_current' => (bool) $year->is_current,
            'sessions' => $sessions,
            'rooms' => $this->roomsForYear($schoolId, $yearId, $user->role, (int) $user->id, $year),
            'teachers' => $user->role === 'teacher'
                ? collect()
                : $this->teachersForSchool($schoolId),
            'students' => $this->studentsForYear($schoolId, $yearId, $user->role, (int) $user->id),
            'can_filter_teacher' => $user->role !== 'teacher',
            'can_manage' => $user->role === 'admin',
            'can_create' => (bool) $year->is_active
                && (bool) $year->is_current
                && in_array($user->role, ['admin', 'teacher'], true),
            'form_options' => $this->formOptionsForYear($schoolId, $yearId, $user->role, (int) $user->id, $year),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        [$user, $schoolId] = $this->writerContext();
        $year = $this->resolveWritableYear($request, $schoolId);
        $validated = $this->validatedSession($request, $user->role, $schoolId, (int) $year->id, $year);
        $teacherId = $user->role === 'teacher' ? (int) $user->id : $validated['teacher_id'];

        $sessionId = DB::transaction(function () use ($validated, $user, $schoolId, $year, $teacherId) {
            $this->ensureSessionRoomAndStudent($validated, $user->role, $schoolId, (int) $year->id);
            $this->ensureTeacherAssignment(
                $user->role,
                (int) $user->id,
                (int) $validated['room_id'],
                $schoolId,
                (int) $year->id,
                $validated['session_date'],
            );

            return DB::table('memorization_sessions')->insertGetId([
                ...$validated,
                'school_id' => $schoolId,
                'year_id' => (int) $year->id,
                'teacher_id' => $teacherId,
                'created_at' => now(),
            ]);
        });

        return response()->json(['session_id' => $sessionId], 201);
    }

    public function update(Request $request, int $sessionId): JsonResponse
    {
        [$user, $schoolId] = $this->writerContext();
        $year = $this->resolveWritableYear($request, $schoolId);
        $validated = $this->validatedSession($request, $user->role, $schoolId, (int) $year->id, $year);
        $teacherId = $user->role === 'teacher' ? (int) $user->id : $validated['teacher_id'];

        DB::transaction(function () use ($validated, $user, $schoolId, $year, $sessionId, $teacherId) {
            $session = DB::table('memorization_sessions')
                ->where('id', $sessionId)
                ->where('school_id', $schoolId)
                ->where('year_id', $year->id)
                ->lockForUpdate()
                ->first(['id', 'teacher_id']);
            abort_unless($session, 404);
            abort_unless(
                $user->role === 'admin' || (int) $session->teacher_id === (int) $user->id,
                403,
            );

            $this->ensureSessionRoomAndStudent($validated, $user->role, $schoolId, (int) $year->id);
            $this->ensureTeacherAssignment(
                $user->role,
                (int) $user->id,
                (int) $validated['room_id'],
                $schoolId,
                (int) $year->id,
                $validated['session_date'],
            );

            DB::table('memorization_sessions')
                ->where('id', $sessionId)
                ->where('school_id', $schoolId)
                ->where('year_id', $year->id)
                ->update([
                    ...$validated,
                    'teacher_id' => $teacherId,
                ]);
        });

        return response()->json(['session_id' => $sessionId]);
    }

    /**
     * @return array{student_id: int, room_id: int, session_date: string, surah_start: ?int, ayah_start: ?int, surah_end: ?int, ayah_end: ?int, portion_label: string, evaluation: string, notes: ?string, teacher_id?: int}
     */
    private function validatedSession(
        Request $request,
        string $role,
        int $schoolId,
        int $yearId,
        stdClass $year,
    ): array {
        $validated = $request->validate([
            'student_id' => [
                'required',
                'integer',
                Rule::exists('students', 'id')
                    ->where('school_id', $schoolId)
                    ->where('year_id', $yearId),
            ],
            'room_id' => [
                'required',
                'integer',
                Rule::exists('rooms', 'id')
                    ->where('school_id', $schoolId)
                    ->where('year_id', $yearId),
            ],
            'teacher_id' => $role === 'admin'
                ? [
                    'required',
                    'integer',
                    Rule::exists('users', 'id')
                        ->where('school_id', $schoolId)
                        ->where('role_id', DB::table('roles')->where('role_key', 'teacher')->value('id')),
                ]
                : ['prohibited'],
            'session_date' => ['required', 'date_format:Y-m-d'],
            'surah_start' => ['nullable', 'integer', 'between:1,114'],
            'ayah_start' => ['nullable', 'integer', 'between:1,286'],
            'surah_end' => ['nullable', 'integer', 'between:1,114'],
            'ayah_end' => ['nullable', 'integer', 'between:1,286'],
            'portion_label' => ['required', 'string', 'max:160'],
            'evaluation' => ['required', Rule::in(['excellent', 'good', 'needs_review'])],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        if ($validated['session_date'] < $year->start_date || $validated['session_date'] > $year->end_date) {
            throw ValidationException::withMessages([
                'session_date' => 'The session date must belong to the selected academic year.',
            ]);
        }

        $surahStart = isset($validated['surah_start']) ? (int) $validated['surah_start'] : null;
        $ayahStart = isset($validated['ayah_start']) ? (int) $validated['ayah_start'] : null;
        $surahEnd = isset($validated['surah_end']) ? (int) $validated['surah_end'] : null;
        $ayahEnd = isset($validated['ayah_end']) ? (int) $validated['ayah_end'] : null;
        $rangeHasStart = $surahStart !== null || $ayahStart !== null;
        $rangeHasEnd = $surahEnd !== null || $ayahEnd !== null;

        if (
            ($rangeHasStart && ($surahStart === null || $ayahStart === null))
            || ($rangeHasEnd && ($surahEnd === null || $ayahEnd === null))
            || ($rangeHasStart !== $rangeHasEnd)
        ) {
            throw ValidationException::withMessages([
                'surah_start' => 'Provide both complete surah and ayah endpoints, or leave the range empty.',
            ]);
        }

        if (
            $rangeHasStart
            && (
                $surahStart > $surahEnd
                || (
                    $surahStart === $surahEnd
                    && $ayahStart > $ayahEnd
                )
            )
        ) {
            throw ValidationException::withMessages([
                'surah_end' => 'The memorization range must end at or after its starting point.',
            ]);
        }

        return [
            'student_id' => (int) $validated['student_id'],
            'room_id' => (int) $validated['room_id'],
            'session_date' => $validated['session_date'],
            'surah_start' => $surahStart === null ? null : (int) $surahStart,
            'ayah_start' => $ayahStart === null ? null : (int) $ayahStart,
            'surah_end' => $surahEnd === null ? null : (int) $surahEnd,
            'ayah_end' => $ayahEnd === null ? null : (int) $ayahEnd,
            'portion_label' => $validated['portion_label'],
            'evaluation' => $validated['evaluation'],
            'notes' => $validated['notes'] ?? null,
            ...($role === 'admin' ? ['teacher_id' => (int) $validated['teacher_id']] : []),
        ];
    }

    /**
     * @param  array{student_id: int, room_id: int}  $validated
     */
    private function ensureSessionRoomAndStudent(
        array $validated,
        string $role,
        int $schoolId,
        int $yearId,
    ): void {
        $roomQuery = DB::table('rooms')
            ->where('id', $validated['room_id'])
            ->where('school_id', $schoolId)
            ->where('year_id', $yearId);

        if ($role === 'teacher') {
            $roomQuery->where('is_active', true);
        }

        abort_unless($roomQuery->exists(), 404);

        $studentQuery = DB::table('students')
            ->where('id', $validated['student_id'])
            ->where('school_id', $schoolId)
            ->where('year_id', $yearId)
            ->where('room_id', $validated['room_id']);

        if ($role === 'teacher') {
            $studentQuery->whereIn('status', ['active', 'paused']);
        }

        abort_unless($studentQuery->exists(), 404);
    }

    private function ensureTeacherAssignment(
        string $role,
        int $userId,
        int $roomId,
        int $schoolId,
        int $yearId,
        string $sessionDate,
    ): void {
        if ($role !== 'teacher') {
            return;
        }

        $assigned = DB::table('room_teachers')
            ->join('rooms', function (JoinClause $join) {
                $join->on('rooms.id', '=', 'room_teachers.room_id')
                    ->on('rooms.school_id', '=', 'room_teachers.school_id')
                    ->on('rooms.year_id', '=', 'room_teachers.year_id');
            })
            ->where('room_teachers.teacher_id', $userId)
            ->where('room_teachers.room_id', $roomId)
            ->where('room_teachers.school_id', $schoolId)
            ->where('room_teachers.year_id', $yearId)
            ->where('room_teachers.assigned_from', '<=', $sessionDate)
            ->where(function (Builder $dates) use ($sessionDate) {
                $dates->whereNull('room_teachers.assigned_until')
                    ->orWhere('room_teachers.assigned_until', '>=', $sessionDate);
            })
            ->where('rooms.is_active', true)
            ->exists();

        abort_unless($assigned, 403);
    }

    private function formOptionsForYear(
        int $schoolId,
        int $yearId,
        string $role,
        int $userId,
        stdClass $year,
    ): array {
        $rooms = DB::table('rooms')
            ->where('school_id', $schoolId)
            ->where('year_id', $yearId)
            ->when($role === 'teacher', function (Builder $query) use ($schoolId, $yearId, $userId, $year) {
                $query->where('is_active', true)
                    ->whereExists(function (Builder $assignments) use ($schoolId, $yearId, $userId, $year) {
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

        $students = DB::table('students')
            ->where('school_id', $schoolId)
            ->where('year_id', $yearId)
            ->when($role === 'teacher', function (Builder $query) use ($schoolId, $yearId, $userId, $year) {
                $query->whereIn('status', ['active', 'paused'])
                    ->whereExists(function (Builder $assignments) use ($schoolId, $yearId, $userId, $year) {
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
                            ->where('room_teachers.teacher_id', $userId)
                            ->where('room_teachers.assigned_from', '<=', $year->end_date)
                            ->where(function (Builder $dates) use ($year) {
                                $dates->whereNull('room_teachers.assigned_until')
                                    ->orWhere('room_teachers.assigned_until', '>=', $year->start_date);
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

        return [
            'rooms' => $rooms,
            'students' => $students,
            'teachers' => $role === 'admin' ? $this->teachersForSchool($schoolId) : collect(),
        ];
    }

    private function applyTeacherRoomScope(Builder $query, int $teacherId): void
    {
        $query->whereExists(function (Builder $assignments) use ($teacherId) {
            $assignments->selectRaw('1')
                ->from('room_teachers')
                ->whereColumn('room_teachers.room_id', 'memorization_sessions.room_id')
                ->whereColumn('room_teachers.school_id', 'memorization_sessions.school_id')
                ->whereColumn('room_teachers.year_id', 'memorization_sessions.year_id')
                ->where('room_teachers.teacher_id', $teacherId)
                ->whereColumn('room_teachers.assigned_from', '<=', 'memorization_sessions.session_date')
                ->where(function (Builder $dates) {
                    $dates->whereNull('room_teachers.assigned_until')
                        ->orWhereColumn('room_teachers.assigned_until', '>=', 'memorization_sessions.session_date');
                });
        });
    }

    private function roomsForYear(int $schoolId, int $yearId, string $role, int $userId, stdClass $year): Collection
    {
        return DB::table('rooms')
            ->where('rooms.school_id', $schoolId)
            ->where('rooms.year_id', $yearId)
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
            ->orderBy('rooms.name')
            ->get(['rooms.id', 'rooms.name'])
            ->map(fn ($room) => ['id' => (int) $room->id, 'name' => $room->name])
            ->values();
    }

    private function teachersForSchool(int $schoolId): Collection
    {
        return DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('users.school_id', $schoolId)
            ->where('roles.role_key', 'teacher')
            ->orderBy('users.full_name')
            ->get(['users.id', 'users.full_name'])
            ->map(fn ($teacher) => ['id' => (int) $teacher->id, 'name' => $teacher->full_name])
            ->values();
    }

    private function studentsForYear(int $schoolId, int $yearId, string $role, int $userId): Collection
    {
        return DB::table('students')
            ->where('students.school_id', $schoolId)
            ->where('students.year_id', $yearId)
            ->when($role === 'teacher', function (Builder $query) use ($schoolId, $yearId, $userId) {
                $query->whereExists(function (Builder $studentSessions) use ($schoolId, $yearId, $userId) {
                    $studentSessions->selectRaw('1')
                        ->from('memorization_sessions')
                        ->whereColumn('memorization_sessions.student_id', 'students.id')
                        ->where('memorization_sessions.school_id', $schoolId)
                        ->where('memorization_sessions.year_id', $yearId)
                        ->whereExists(function (Builder $assignments) use ($userId) {
                            $assignments->selectRaw('1')
                                ->from('room_teachers')
                                ->whereColumn('room_teachers.room_id', 'memorization_sessions.room_id')
                                ->whereColumn('room_teachers.school_id', 'memorization_sessions.school_id')
                                ->whereColumn('room_teachers.year_id', 'memorization_sessions.year_id')
                                ->where('room_teachers.teacher_id', $userId)
                                ->whereColumn('room_teachers.assigned_from', '<=', 'memorization_sessions.session_date')
                                ->where(function (Builder $dates) {
                                    $dates->whereNull('room_teachers.assigned_until')
                                        ->orWhereColumn('room_teachers.assigned_until', '>=', 'memorization_sessions.session_date');
                                });
                        });
                });
            })
            ->orderBy('students.full_name')
            ->get(['students.id', 'students.full_name', 'students.student_number'])
            ->map(fn ($student) => [
                'id' => (int) $student->id,
                'name' => $student->full_name,
                'student_number' => $student->student_number,
            ])
            ->values();
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

    private function writerContext(): array
    {
        [$user, $schoolId] = $this->staffContext();
        abort_unless(in_array($user->role, ['admin', 'teacher'], true), 403);

        return [$user, $schoolId];
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
            ->first(['id', 'start_date', 'end_date', 'is_active', 'is_current']);
        abort_unless($year, 404);

        if (! $year->is_active || ! $year->is_current) {
            throw ValidationException::withMessages([
                'year_id' => 'Memorization sessions in a closed academic year cannot be changed.',
            ]);
        }

        return $year;
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
            'start_date',
            'end_date',
            'is_active',
            'is_current',
        ]);
        abort_unless($year, 404);

        return $year;
    }
}
