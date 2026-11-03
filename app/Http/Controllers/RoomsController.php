<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RoomsController extends Controller
{
    public function table(Request $request): JsonResponse
    {
        [$user, $schoolId] = $this->staffContext();
        $role = $user->role;
        $yearId = $this->resolveYearId($request, $schoolId);
        $academicYear = DB::table('academic_years')
            ->where('school_id', $schoolId)
            ->where('id', $yearId)
            ->first(['start_date', 'end_date', 'is_active']);
        abort_unless($academicYear, 404);
        $today = now()->toDateString();
        $studentCounts = DB::table('students')
            ->select('room_id')
            ->selectRaw('COUNT(*) AS student_count')
            ->where('school_id', $schoolId)
            ->where('year_id', $yearId)
            ->whereIn('status', ['active', 'paused'])
            ->groupBy('room_id');

        $roomQuery = DB::table('rooms')
            ->leftJoinSub($studentCounts, 'student_counts', function ($join) {
                $join->on('student_counts.room_id', '=', 'rooms.id');
            })
            ->where('rooms.school_id', $schoolId)
            ->where('rooms.year_id', $yearId);

        if ($role === 'teacher') {
            $roomQuery
                ->where('rooms.is_active', true)
                ->whereExists(function ($assignments) use ($schoolId, $yearId, $user, $today) {
                    $assignments->selectRaw('1')
                        ->from('room_teachers')
                        ->whereColumn('room_teachers.room_id', 'rooms.id')
                        ->where('room_teachers.school_id', $schoolId)
                        ->where('room_teachers.year_id', $yearId)
                        ->where('room_teachers.teacher_id', $user->id)
                        ->where('room_teachers.assigned_from', '<=', $today)
                        ->where(function ($dates) use ($today) {
                            $dates->whereNull('room_teachers.assigned_until')
                                ->orWhere('room_teachers.assigned_until', '>=', $today);
                        });
                });
        }

        $roomRows = $roomQuery
            ->select([
                'rooms.id',
                'rooms.name',
                'rooms.level',
                'rooms.age_min',
                'rooms.age_max',
                'rooms.capacity',
                'rooms.is_active',
                'student_counts.student_count',
            ])
            ->orderBy('rooms.name')
            ->get();
        $roomIds = $roomRows->pluck('id')->all();
        $assignmentQuery = DB::table('room_teachers')
            ->join('users', function ($join) {
                $join->on('users.id', '=', 'room_teachers.teacher_id')
                    ->on('users.school_id', '=', 'room_teachers.school_id');
            })
            ->where('room_teachers.school_id', $schoolId)
            ->where('room_teachers.year_id', $yearId)
            ->whereIn('room_teachers.room_id', $roomIds ?: [0])
            ->orderBy('users.full_name');

        if ($role === 'teacher') {
            $assignmentQuery
                ->where('room_teachers.assigned_from', '<=', $today)
                ->where(function ($dates) use ($today) {
                    $dates->whereNull('room_teachers.assigned_until')
                        ->orWhere('room_teachers.assigned_until', '>=', $today);
                });
        } else {
            $assignmentQuery
                ->where('room_teachers.assigned_from', '<=', $academicYear->end_date)
                ->where(function ($dates) use ($academicYear) {
                    $dates->whereNull('room_teachers.assigned_until')
                        ->orWhere('room_teachers.assigned_until', '>=', $academicYear->start_date);
                });
        }

        $assignments = $assignmentQuery
            ->get([
                'room_teachers.room_id',
                'room_teachers.teacher_id',
                'room_teachers.assigned_from',
                'room_teachers.assigned_until',
                'users.full_name',
            ])
            ->groupBy('room_id');

        $rooms = $roomRows->map(function ($room) use ($assignments) {
            return [
                'id' => (int) $room->id,
                'name' => $room->name,
                'level' => $room->level,
                'age_min' => $room->age_min === null ? null : (int) $room->age_min,
                'age_max' => $room->age_max === null ? null : (int) $room->age_max,
                'capacity' => (int) $room->capacity,
                'is_active' => (bool) $room->is_active,
                'student_count' => (int) ($room->student_count ?? 0),
                'assigned_teachers' => ($assignments->get($room->id) ?? collect())
                    ->map(fn ($assignment) => [
                        'id' => (int) $assignment->teacher_id,
                        'name' => $assignment->full_name,
                        'assigned_from' => $assignment->assigned_from,
                        'assigned_until' => $assignment->assigned_until,
                    ])
                    ->values(),
            ];
        })->values();

        $response = [
            'year_id' => $yearId,
            'year_is_active' => (bool) $academicYear->is_active,
            'year_is_writable' => (bool) $academicYear->is_active,
            'rooms' => $rooms,
            'can_manage' => $role === 'admin',
            'can_place' => in_array($role, ['admin', 'secretary'], true),
            'is_teacher_view' => $role === 'teacher',
        ];

        if ($role === 'teacher') {
            $response['students'] = DB::table('students')
                ->where('school_id', $schoolId)
                ->where('year_id', $yearId)
                ->whereIn('room_id', $roomIds ?: [0])
                ->whereIn('status', ['active', 'paused'])
                ->orderBy('full_name')
                ->get(['id', 'full_name', 'student_number', 'room_id', 'status'])
                ->map(fn ($student) => [
                    'id' => (int) $student->id,
                    'full_name' => $student->full_name,
                    'student_number' => $student->student_number,
                    'room_id' => (int) $student->room_id,
                    'status' => $student->status,
                ])
                ->values();
        }

        if ($role === 'admin') {
            $response['assignments'] = DB::table('room_teachers')
                ->join('rooms', function ($join) {
                    $join->on('rooms.id', '=', 'room_teachers.room_id')
                        ->on('rooms.school_id', '=', 'room_teachers.school_id')
                        ->on('rooms.year_id', '=', 'room_teachers.year_id');
                })
                ->join('users', function ($join) {
                    $join->on('users.id', '=', 'room_teachers.teacher_id')
                        ->on('users.school_id', '=', 'room_teachers.school_id');
                })
                ->where('room_teachers.school_id', $schoolId)
                ->where('room_teachers.year_id', $yearId)
                ->orderBy('rooms.name')
                ->orderBy('users.full_name')
                ->orderBy('room_teachers.assigned_from')
                ->get([
                    'room_teachers.room_id',
                    'room_teachers.teacher_id',
                    'room_teachers.assigned_from',
                    'room_teachers.assigned_until',
                    'rooms.name as room_name',
                    'users.full_name as teacher_name',
                ])
                ->map(fn ($assignment) => [
                    'key' => "{$assignment->room_id}:{$assignment->teacher_id}:{$assignment->assigned_from}",
                    'room_id' => (int) $assignment->room_id,
                    'room_name' => $assignment->room_name,
                    'teacher_id' => (int) $assignment->teacher_id,
                    'teacher_name' => $assignment->teacher_name,
                    'assigned_from' => $assignment->assigned_from,
                    'assigned_until' => $assignment->assigned_until,
                ])
                ->values();
        }

        if (in_array($role, ['admin', 'secretary'], true)) {
            $response['students'] = DB::table('students')
                ->where('school_id', $schoolId)
                ->where('year_id', $yearId)
                ->whereIn('status', ['active', 'paused'])
                ->orderBy('full_name')
                ->get(['id', 'full_name', 'student_number', 'room_id', 'status', 'birth_date'])
                ->map(function ($student) {
                    $birthDate = $student->birth_date === null ? null : Carbon::parse($student->birth_date);

                    return [
                        'id' => (int) $student->id,
                        'full_name' => $student->full_name,
                        'student_number' => $student->student_number,
                        'room_id' => $student->room_id === null ? null : (int) $student->room_id,
                        'status' => $student->status,
                        'age' => $birthDate === null || $birthDate->isFuture() ? null : $birthDate->age,
                    ];
                })
                ->values();
        }

        if ($role === 'admin') {
            $response['teachers'] = DB::table('users')
                ->join('roles', 'roles.id', '=', 'users.role_id')
                ->where('users.school_id', $schoolId)
                ->where('users.is_active', true)
                ->where('roles.role_key', 'teacher')
                ->orderBy('users.full_name')
                ->get(['users.id', 'users.full_name'])
                ->map(fn ($teacher) => [
                    'id' => (int) $teacher->id,
                    'name' => $teacher->full_name,
                ])
                ->values();
        }

        return response()->json($response);
    }

    public function store(Request $request): JsonResponse
    {
        [, $schoolId] = $this->adminContext();
        $yearId = $this->validateYearId($request, $schoolId);
        $this->ensureYearIsEditable($schoolId, $yearId);
        $room = $this->validatedRoom($request, true);
        $now = now();

        $roomId = DB::table('rooms')->insertGetId([
            ...$room,
            'school_id' => $schoolId,
            'year_id' => $yearId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return response()->json(['room_id' => (int) $roomId], 201);
    }

    public function update(Request $request, int $roomId): JsonResponse
    {
        [, $schoolId] = $this->adminContext();
        $existing = DB::table('rooms')
            ->where('id', $roomId)
            ->where('school_id', $schoolId)
            ->first(['id', 'year_id']);
        abort_unless($existing, 404);

        $yearId = $this->validateYearId($request, $schoolId);
        abort_unless((int) $existing->year_id === $yearId, 404);
        $this->ensureYearIsEditable($schoolId, $yearId);
        $room = $this->validatedRoom($request);

        DB::table('rooms')
            ->where('id', $roomId)
            ->where('school_id', $schoolId)
            ->where('year_id', $yearId)
            ->update([...$room, 'updated_at' => now()]);

        return response()->json(['room_id' => $roomId]);
    }

    public function archive(Request $request, int $roomId): JsonResponse
    {
        [, $schoolId] = $this->adminContext();
        $existing = DB::table('rooms')
            ->where('id', $roomId)
            ->where('school_id', $schoolId)
            ->first(['id', 'year_id']);
        abort_unless($existing, 404);

        $yearId = $this->validateYearId($request, $schoolId);
        abort_unless((int) $existing->year_id === $yearId, 404);
        $this->ensureYearIsEditable($schoolId, $yearId);

        DB::table('rooms')
            ->where('id', $roomId)
            ->where('school_id', $schoolId)
            ->where('year_id', $yearId)
            ->update(['is_active' => false, 'updated_at' => now()]);

        return response()->json(['room_id' => $roomId, 'is_active' => false]);
    }

    public function assignTeacher(Request $request, int $roomId): JsonResponse
    {
        [, $schoolId] = $this->adminContext();
        $yearId = $this->validateYearId($request, $schoolId);
        $this->ensureYearIsEditable($schoolId, $yearId);

        $room = DB::table('rooms')
            ->where('id', $roomId)
            ->where('school_id', $schoolId)
            ->where('year_id', $yearId)
            ->where('is_active', true)
            ->first(['id']);
        abort_unless($room, 404);

        $validated = $request->validate([
            'teacher_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')
                    ->where('school_id', $schoolId)
                    ->where('is_active', true),
            ],
            'assigned_from' => ['required', 'date'],
            'assigned_until' => ['nullable', 'date', 'after_or_equal:assigned_from'],
        ]);
        $isTeacher = DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('users.id', $validated['teacher_id'])
            ->where('users.school_id', $schoolId)
            ->where('users.is_active', true)
            ->where('roles.role_key', 'teacher')
            ->exists();
        if (! $isTeacher) {
            throw ValidationException::withMessages([
                'teacher_id' => 'The selected user is not an active teacher in this school.',
            ]);
        }

        DB::transaction(function () use ($validated, $roomId, $schoolId, $yearId) {
            $room = DB::table('rooms')
                ->where('id', $roomId)
                ->where('school_id', $schoolId)
                ->where('year_id', $yearId)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first(['id']);
            abort_unless($room, 404);

            $overlapEnd = $validated['assigned_until'] ?? '9999-12-31';
            $hasOverlap = DB::table('room_teachers')
                ->where('room_id', $roomId)
                ->where('teacher_id', $validated['teacher_id'])
                ->where('school_id', $schoolId)
                ->where('year_id', $yearId)
                ->where('assigned_from', '<=', $overlapEnd)
                ->where(function ($query) use ($validated) {
                    $query->whereNull('assigned_until')
                        ->orWhere('assigned_until', '>=', $validated['assigned_from']);
                })
                ->exists();

            if ($hasOverlap) {
                throw ValidationException::withMessages([
                    'teacher_id' => 'The teacher already has an overlapping assignment to this room.',
                ]);
            }

            DB::table('room_teachers')->insert([
                'room_id' => $roomId,
                'teacher_id' => $validated['teacher_id'],
                'school_id' => $schoolId,
                'year_id' => $yearId,
                'assigned_from' => $validated['assigned_from'],
                'assigned_until' => $validated['assigned_until'] ?? null,
            ]);
        });

        return response()->json(['room_id' => $roomId], 201);
    }

    public function endTeacherAssignment(Request $request, int $roomId, int $teacherId): JsonResponse
    {
        [, $schoolId] = $this->adminContext();
        $yearId = $this->validateYearId($request, $schoolId);
        $validated = $request->validate([
            'assigned_from' => ['required', 'date'],
        ]);
        $today = now()->toDateString();

        DB::transaction(function () use ($validated, $roomId, $teacherId, $schoolId, $yearId, $today) {
            $assignment = DB::table('room_teachers')
                ->where('room_id', $roomId)
                ->where('teacher_id', $teacherId)
                ->where('school_id', $schoolId)
                ->where('year_id', $yearId)
                ->where('assigned_from', $validated['assigned_from'])
                ->whereNull('assigned_until')
                ->lockForUpdate()
                ->first(['assigned_from']);
            abort_unless($assignment, 404);
            $this->ensureYearIsEditable($schoolId, $yearId);

            if ($assignment->assigned_from > $today) {
                throw ValidationException::withMessages([
                    'assigned_from' => 'A future assignment cannot be ended before it starts.',
                ]);
            }

            DB::table('room_teachers')
                ->where('room_id', $roomId)
                ->where('teacher_id', $teacherId)
                ->where('school_id', $schoolId)
                ->where('year_id', $yearId)
                ->where('assigned_from', $validated['assigned_from'])
                ->whereNull('assigned_until')
                ->update(['assigned_until' => $today]);
        });

        return response()->json([
            'room_id' => $roomId,
            'teacher_id' => $teacherId,
            'assigned_from' => $validated['assigned_from'],
            'assigned_until' => $today,
        ]);
    }

    public function placeStudent(Request $request, int $studentId): JsonResponse
    {
        [, $schoolId] = $this->placementContext();
        $student = DB::table('students')
            ->where('id', $studentId)
            ->where('school_id', $schoolId)
            ->whereIn('status', ['active', 'paused'])
            ->first(['id', 'year_id']);
        abort_unless($student, 404);

        $yearId = $this->validateYearId($request, $schoolId);
        abort_unless((int) $student->year_id === $yearId, 404);
        $this->ensureYearIsEditable($schoolId, $yearId);
        $validated = $request->validate([
            'room_id' => [
                'present',
                'nullable',
                'integer',
                Rule::exists('rooms', 'id')
                    ->where('school_id', $schoolId)
                    ->where('year_id', $yearId)
                    ->where('is_active', true),
            ],
        ]);

        DB::transaction(function () use ($validated, $studentId, $schoolId, $yearId) {
            $student = DB::table('students')
                ->where('id', $studentId)
                ->where('school_id', $schoolId)
                ->where('year_id', $yearId)
                ->whereIn('status', ['active', 'paused'])
                ->lockForUpdate()
                ->first(['id']);
            abort_unless($student, 404);

            if ($validated['room_id'] !== null) {
                $room = DB::table('rooms')
                    ->where('id', $validated['room_id'])
                    ->where('school_id', $schoolId)
                    ->where('year_id', $yearId)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->first(['id', 'capacity']);
                abort_unless($room, 404);

                $studentsInRoom = DB::table('students')
                    ->where('school_id', $schoolId)
                    ->where('year_id', $yearId)
                    ->where('room_id', $room->id)
                    ->where('id', '!=', $studentId)
                    ->whereIn('status', ['active', 'paused'])
                    ->count();

                if ($studentsInRoom >= (int) $room->capacity) {
                    throw ValidationException::withMessages([
                        'room_capacity' => 'The selected room has reached its capacity.',
                    ]);
                }
            }

            DB::table('students')
                ->where('id', $studentId)
                ->where('school_id', $schoolId)
                ->where('year_id', $yearId)
                ->update([
                    'room_id' => $validated['room_id'],
                    'updated_at' => now(),
                ]);
        });

        return response()->json([
            'student_id' => $studentId,
            'room_id' => $validated['room_id'] === null ? null : (int) $validated['room_id'],
        ]);
    }

    private function validatedRoom(Request $request, bool $isCreating = false): array
    {
        $request->merge([
            'name' => trim((string) $request->input('name')),
        ]);
        if ($isCreating && ! $request->has('is_active')) {
            $request->merge(['is_active' => true]);
        }
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:100'],
            'level' => ['required', Rule::in(['beginner', 'intermediate', 'advanced'])],
            'age_min' => ['nullable', 'integer', 'min:0', 'max:255'],
            'age_max' => ['nullable', 'integer', 'min:0', 'max:255'],
            'capacity' => ['required', 'integer', 'min:1', 'max:65535'],
            'is_active' => ['required', 'boolean'],
        ]);
        $validator->after(function ($validator) use ($request) {
            $ageMin = $request->input('age_min');
            $ageMax = $request->input('age_max');

            if ($ageMin !== null && $ageMax !== null && (int) $ageMin > (int) $ageMax) {
                $validator->errors()->add('age_min', 'The minimum age must not exceed the maximum age.');
            }
        });

        return $validator->validate();
    }

    private function staffContext(): array
    {
        $user = Auth::user();
        $schoolId = data_get($user, 'school_id');
        abort_unless(
            $user && in_array(data_get($user, 'role'), ['admin', 'secretary', 'teacher'], true) && $schoolId,
            403,
        );

        return [$user, (int) $schoolId];
    }

    private function adminContext(): array
    {
        [$user, $schoolId] = $this->staffContext();
        abort_unless($user->role === 'admin', 403);

        return [$user, $schoolId];
    }

    private function placementContext(): array
    {
        [$user, $schoolId] = $this->staffContext();
        abort_unless(in_array($user->role, ['admin', 'secretary'], true), 403);

        return [$user, $schoolId];
    }

    private function resolveYearId(Request $request, int $schoolId): int
    {
        $yearId = $request->input('year_id');

        if ($yearId !== null) {
            $year = DB::table('academic_years')
                ->where('id', $yearId)
                ->where('school_id', $schoolId)
                ->first(['id']);
            abort_unless($year, 404);

            return (int) $year->id;
        }

        $year = DB::table('academic_years')
            ->where('school_id', $schoolId)
            ->where('is_current', true)
            ->where('is_active', true)
            ->orderByDesc('start_date')
            ->first(['id']);
        abort_unless($year, 404);

        return (int) $year->id;
    }

    private function validateYearId(Request $request, int $schoolId): int
    {
        $validated = $request->validate([
            'year_id' => [
                'required',
                'integer',
                Rule::exists('academic_years', 'id')->where('school_id', $schoolId),
            ],
        ]);

        return (int) $validated['year_id'];
    }

    private function ensureYearIsEditable(int $schoolId, int $yearId): void
    {
        $year = DB::table('academic_years')
            ->where('school_id', $schoolId)
            ->where('id', $yearId)
            ->first(['is_active']);

        if (! $year || ! $year->is_active) {
            throw ValidationException::withMessages([
                'year_id' => 'Rooms and student placements in a read-only academic year cannot be changed.',
            ]);
        }
    }
}
