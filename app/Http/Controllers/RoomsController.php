<?php

namespace App\Http\Controllers;

use App\Support\AuditEventLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class RoomsController extends Controller
{
    public function export(Request $request): Response|JsonResponse
    {
        [$user, $schoolId] = $this->staffContext();
        $role = $user->role;
        $yearId = $this->resolveYearId($request, $schoolId);
        $filters = $request->validate([
            'format' => ['required', Rule::in(['csv', 'xlsx', 'pdf'])],
            'lang' => ['required', Rule::in(['fr', 'ar'])],
            'room_id' => [
                'nullable',
                'integer',
                Rule::exists('rooms', 'id')
                    ->where('school_id', $schoolId)
                    ->where('year_id', $yearId),
            ],
        ]);
        $academicYear = DB::table('academic_years')
            ->where('school_id', $schoolId)
            ->where('id', $yearId)
            ->first(['year_label', 'start_date', 'end_date']);
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

        if (isset($filters['room_id'])) {
            $roomQuery->where('rooms.id', $filters['room_id']);
        }

        $rooms = $roomQuery
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

        if ($rooms->isEmpty()) {
            return response()->json(['message' => 'There are no groups to export.'], 422);
        }

        $labels = $this->exportLabels($filters['lang']);
        $roomIds = $rooms->pluck('id')->all();
        $teachersByRoom = collect();
        if ($role === 'admin') {
            $teachersByRoom = DB::table('room_teachers')
                ->join('users', function ($join) {
                    $join->on('users.id', '=', 'room_teachers.teacher_id')
                        ->on('users.school_id', '=', 'room_teachers.school_id');
                })
                ->where('room_teachers.school_id', $schoolId)
                ->where('room_teachers.year_id', $yearId)
                ->whereIn('room_teachers.room_id', $roomIds)
                ->where('room_teachers.assigned_from', '<=', $academicYear->end_date)
                ->where(function ($dates) use ($academicYear) {
                    $dates->whereNull('room_teachers.assigned_until')
                        ->orWhere('room_teachers.assigned_until', '>=', $academicYear->start_date);
                })
                ->orderBy('users.full_name')
                ->get(['room_teachers.room_id', 'users.full_name'])
                ->groupBy('room_id');
        }

        $studentsByRoom = collect();
        if ($role === 'teacher') {
            $studentsByRoom = DB::table('students')
                ->where('school_id', $schoolId)
                ->where('year_id', $yearId)
                ->whereIn('room_id', $roomIds)
                ->whereIn('status', ['active', 'paused'])
                ->orderBy('full_name')
                ->get(['room_id', 'student_number', 'full_name', 'status'])
                ->groupBy('room_id');
        }

        $headers = [
            $labels['name'],
            $labels['level'],
            $labels['age_range'],
            $labels['capacity'],
            $labels['status'],
        ];
        if ($role === 'admin') {
            $headers[] = $labels['teachers'];
        }
        if ($role === 'teacher') {
            array_push($headers, $labels['student'], $labels['student_number'], $labels['student_status']);
        }

        $rows = $rooms->flatMap(function ($room) use ($labels, $role, $teachersByRoom, $studentsByRoom) {
            $baseRow = [
                $room->name,
                $labels['levels'][$room->level] ?? $room->level,
                $room->age_min === null && $room->age_max === null
                    ? $labels['all_ages']
                    : ($room->age_min ?? '—').' – '.($room->age_max ?? '—').$labels['years_suffix'],
                (int) ($room->student_count ?? 0).' / '.(int) $room->capacity,
                $room->is_active ? $labels['active'] : $labels['archived'],
            ];

            if ($role === 'admin') {
                $teacherNames = ($teachersByRoom->get($room->id) ?? collect())
                    ->pluck('full_name')
                    ->implode(', ');

                return [array_merge($baseRow, [$teacherNames ?: $labels['no_teachers']])];
            }

            if ($role === 'teacher') {
                $students = $studentsByRoom->get($room->id) ?? collect();
                if ($students->isEmpty()) {
                    return [array_merge($baseRow, ['', '', ''])];
                }

                return $students->map(fn ($student) => array_merge($baseRow, [
                    $student->full_name,
                    $student->student_number ?? '',
                    $labels[$student->status] ?? $student->status,
                ]))->all();
            }

            return [$baseRow];
        })->values()->all();

        $school = DB::table('schools')
            ->where('id', $schoolId)
            ->first(['name_fr', 'name_ar']);
        abort_unless($school, 404);
        $schoolName = $filters['lang'] === 'ar' ? $school->name_ar : $school->name_fr;
        $generatedAt = now()->format('Y-m-d H:i:s');
        $contents = match ($filters['format']) {
            'csv' => $this->roomsCsv(
                $labels,
                $schoolName,
                $academicYear->year_label,
                $generatedAt,
                $rooms->count(),
                $headers,
                $rows,
            ),
            'xlsx' => $this->roomsXlsx(
                $filters['lang'],
                $labels,
                $schoolName,
                $academicYear->year_label,
                $generatedAt,
                $rooms->count(),
                $headers,
                $rows,
            ),
            default => $this->roomsPdf(
                $filters['lang'],
                $labels,
                $schoolName,
                $academicYear->year_label,
                $generatedAt,
                $rooms->count(),
                $headers,
                $rows,
            ),
        };

        $actor = DB::table('users')
            ->where('school_id', $schoolId)
            ->where('id', Auth::id())
            ->first(['id', 'full_name']);
        abort_unless($actor && is_string($actor->full_name) && $actor->full_name !== '', 403);

        DB::table('audit_events')->insert([
            'school_id' => $schoolId,
            'year_id' => $yearId,
            'actor_user_id' => (int) $actor->id,
            'actor_name' => $actor->full_name,
            'action_type' => 'exported',
            'entity_type' => 'room',
            'entity_id' => null,
            'summary_key' => 'rooms.exported',
            'changes' => json_encode([
                'format' => $filters['format'],
                'page' => 'rooms',
                'record_count' => $rooms->count(),
                'scope' => $role === 'teacher' ? 'assigned_rooms' : 'school_rooms',
                'room_id' => $filters['room_id'] ?? null,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        $filename = $filters['lang'] === 'ar' ? 'المجموعات.' : 'groupes.';

        return response($contents, 200, [
            'Content-Type' => match ($filters['format']) {
                'pdf' => 'application/pdf',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                default => 'text/csv; charset=UTF-8',
            },
            'Content-Disposition' => "attachment; filename*=UTF-8''".rawurlencode($filename.$filters['format']),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Export-Record-Count' => (string) $rooms->count(),
        ]);
    }

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

        $roomId = DB::transaction(function () use ($room, $schoolId, $yearId, $now) {
            $roomId = DB::table('rooms')->insertGetId([
                ...$room,
                'school_id' => $schoolId,
                'year_id' => $yearId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            AuditEventLogger::record(
                $schoolId,
                $yearId,
                'room',
                (int) $roomId,
                'created',
                'room.created',
                [],
                $room,
            );

            return (int) $roomId;
        });

        return response()->json(['room_id' => (int) $roomId], 201);
    }

    public function update(Request $request, int $roomId): JsonResponse
    {
        [, $schoolId] = $this->adminContext();
        $existing = DB::table('rooms')
            ->where('id', $roomId)
            ->where('school_id', $schoolId)
            ->first(['id', 'year_id', 'name', 'level', 'age_min', 'age_max', 'capacity', 'is_active']);
        abort_unless($existing, 404);

        $yearId = $this->validateYearId($request, $schoolId);
        abort_unless((int) $existing->year_id === $yearId, 404);
        $this->ensureYearIsEditable($schoolId, $yearId);
        $room = $this->validatedRoom($request);

        DB::transaction(function () use ($room, $roomId, $schoolId, $yearId, $existing) {
            DB::table('rooms')
                ->where('id', $roomId)
                ->where('school_id', $schoolId)
                ->where('year_id', $yearId)
                ->update([...$room, 'updated_at' => now()]);
            AuditEventLogger::record(
                $schoolId,
                $yearId,
                'room',
                $roomId,
                'updated',
                'room.updated',
                array_intersect_key((array) $existing, $room),
                $room,
            );
        });

        return response()->json(['room_id' => $roomId]);
    }

    public function archive(Request $request, int $roomId): JsonResponse
    {
        [, $schoolId] = $this->adminContext();
        $existing = DB::table('rooms')
            ->where('id', $roomId)
            ->where('school_id', $schoolId)
            ->first(['id', 'year_id', 'name', 'is_active']);
        abort_unless($existing, 404);

        $yearId = $this->validateYearId($request, $schoolId);
        abort_unless((int) $existing->year_id === $yearId, 404);
        $this->ensureYearIsEditable($schoolId, $yearId);

        DB::transaction(function () use ($roomId, $schoolId, $yearId, $existing) {
            DB::table('rooms')
                ->where('id', $roomId)
                ->where('school_id', $schoolId)
                ->where('year_id', $yearId)
                ->update(['is_active' => false, 'updated_at' => now()]);
            AuditEventLogger::record(
                $schoolId,
                $yearId,
                'room',
                $roomId,
                'archived',
                'room.archived',
                ['name' => $existing->name, 'is_active' => (bool) $existing->is_active],
                ['name' => $existing->name, 'is_active' => false],
            );
        });

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
            AuditEventLogger::record(
                $schoolId,
                $yearId,
                'room',
                $roomId,
                'created',
                'room.teacher_assigned',
                [],
                [
                    'teacher_id' => (int) $validated['teacher_id'],
                    'assigned_from' => $validated['assigned_from'],
                    'assigned_until' => $validated['assigned_until'] ?? null,
                ],
            );
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
            AuditEventLogger::record(
                $schoolId,
                $yearId,
                'room',
                $roomId,
                'updated',
                'room.teacher_assignment_ended',
                ['teacher_id' => $teacherId, 'assigned_from' => $assignment->assigned_from, 'assigned_until' => null],
                ['teacher_id' => $teacherId, 'assigned_from' => $assignment->assigned_from, 'assigned_until' => $today],
            );
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
                ->first(['id', 'room_id']);
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
            AuditEventLogger::record(
                $schoolId,
                $yearId,
                'student',
                $studentId,
                'updated',
                'student.room_changed',
                ['room_id' => $student->room_id === null ? null : (int) $student->room_id],
                ['room_id' => $validated['room_id'] === null ? null : (int) $validated['room_id']],
            );
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

    /**
     * @return array<string, mixed>
     */
    private function exportLabels(string $language): array
    {
        return $language === 'ar'
            ? [
                'title' => 'قائمة المجموعات',
                'school' => 'المدرسة',
                'year' => 'السنة الدراسية',
                'count' => 'عدد المجموعات',
                'generated' => 'تاريخ التصدير',
                'name' => 'المجموعة',
                'level' => 'المستوى',
                'age_range' => 'الفئة العمرية',
                'capacity' => 'العدد / السعة',
                'status' => 'الحالة',
                'active' => 'نشطة',
                'archived' => 'مؤرشفة',
                'teachers' => 'المعلمون المعينون',
                'student' => 'الطالب',
                'student_number' => 'رقم الطالب',
                'student_status' => 'حالة الطالب',
                'all_ages' => 'جميع الأعمار',
                'years_suffix' => ' سنة',
                'no_teachers' => 'لا يوجد معلمون معينون',
                'paused' => 'متوقف مؤقتًا',
                'levels' => [
                    'beginner' => 'مبتدئ',
                    'intermediate' => 'متوسط',
                    'advanced' => 'متقدم',
                ],
            ]
            : [
                'title' => 'Liste des groupes',
                'school' => 'Établissement',
                'year' => 'Année scolaire',
                'count' => 'Nombre de groupes',
                'generated' => 'Date d’export',
                'name' => 'Groupe',
                'level' => 'Niveau',
                'age_range' => 'Tranche d’âge',
                'capacity' => 'Effectif / capacité',
                'status' => 'Statut',
                'active' => 'Actif',
                'archived' => 'Archivé',
                'teachers' => 'Enseignants assignés',
                'student' => 'Élève',
                'student_number' => 'Numéro élève',
                'student_status' => 'Statut élève',
                'all_ages' => 'Tous les âges',
                'years_suffix' => ' ans',
                'no_teachers' => 'Aucun enseignant assigné',
                'paused' => 'En pause',
                'levels' => [
                    'beginner' => 'Débutant',
                    'intermediate' => 'Intermédiaire',
                    'advanced' => 'Avancé',
                ],
            ];
    }

    /**
     * @param  array<string, mixed>  $labels
     * @param  list<string>  $headers
     * @param  list<list<string|int>>  $rows
     */
    private function roomsCsv(
        array $labels,
        string $schoolName,
        string $yearLabel,
        string $generatedAt,
        int $count,
        array $headers,
        array $rows,
    ): string {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Unable to create the groups CSV export.');
        }

        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, [$labels['title']]);
        fputcsv($stream, [$labels['school'], $this->spreadsheetSafe($schoolName)]);
        fputcsv($stream, [$labels['year'], $this->spreadsheetSafe($yearLabel)]);
        fputcsv($stream, [$labels['count'], $count]);
        fputcsv($stream, [$labels['generated'], $generatedAt]);
        fputcsv($stream, []);
        fputcsv($stream, $headers);
        foreach ($rows as $row) {
            fputcsv($stream, array_map(
                fn ($value) => $this->spreadsheetSafe((string) ($value ?? '')),
                $row,
            ));
        }

        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);
        if ($contents === false) {
            throw new \RuntimeException('Unable to read the groups CSV export.');
        }

        return $contents;
    }

    /**
     * @param  array<string, mixed>  $labels
     * @param  list<string>  $headers
     * @param  list<list<string|int>>  $rows
     */
    private function roomsPdf(
        string $language,
        array $labels,
        string $schoolName,
        string $yearLabel,
        string $generatedAt,
        int $count,
        array $headers,
        array $rows,
    ): string {
        $temporaryDirectory = storage_path('framework/cache/mpdf');
        File::ensureDirectoryExists($temporaryDirectory);
        $pdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4-L',
            'default_font' => 'dejavusans',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'tempDir' => $temporaryDirectory,
        ]);
        $pdf->SetTitle($labels['title']);
        $pdf->WriteHTML(view('exports.rooms', [
            'language' => $language,
            'labels' => $labels,
            'schoolName' => $schoolName,
            'yearLabel' => $yearLabel,
            'generatedAt' => $generatedAt,
            'count' => $count,
            'headers' => $headers,
            'rows' => $rows,
        ])->render());
        $contents = $pdf->Output('', Destination::STRING_RETURN);
        if (! str_starts_with($contents, '%PDF-')) {
            throw new \RuntimeException('The groups PDF export is invalid.');
        }

        return $contents;
    }

    /**
     * @param  array<string, mixed>  $labels
     * @param  list<string>  $headers
     * @param  list<list<string|int>>  $rows
     */
    private function roomsXlsx(
        string $language,
        array $labels,
        string $schoolName,
        string $yearLabel,
        string $generatedAt,
        int $count,
        array $headers,
        array $rows,
    ): string {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($language === 'ar' ? 'المجموعات' : 'Groupes');
        $sheet->setRightToLeft($language === 'ar');

        $metadata = [
            [$labels['title']],
            [$labels['school'], $schoolName],
            [$labels['year'], $yearLabel],
            [$labels['count'], (string) $count],
            [$labels['generated'], $generatedAt],
        ];
        foreach ($metadata as $rowIndex => $row) {
            foreach ($row as $columnIndex => $value) {
                $sheet->getCell([$columnIndex + 1, $rowIndex + 1])
                    ->setValueExplicit(
                        $this->spreadsheetSafe((string) $value),
                        DataType::TYPE_STRING,
                    );
            }
        }
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);

        $headerRow = count($metadata) + 2;
        foreach ($headers as $index => $heading) {
            $sheet->getCell([$index + 1, $headerRow])
                ->setValueExplicit($heading, DataType::TYPE_STRING);
            $sheet->getColumnDimensionByColumn($index + 1)->setAutoSize(true);
        }
        $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
        $lastRow = $headerRow + count($rows);
        $sheet->getStyle("A{$headerRow}:{$lastColumn}{$headerRow}")->getFont()->setBold(true);
        $sheet->freezePane('A'.($headerRow + 1));
        if ($rows !== []) {
            $sheet->setAutoFilter("A{$headerRow}:{$lastColumn}{$lastRow}");
        }

        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $columnIndex => $value) {
                $sheet->getCell([$columnIndex + 1, $headerRow + $rowIndex + 1])
                    ->setValueExplicit(
                        $this->spreadsheetSafe((string) ($value ?? '')),
                        DataType::TYPE_STRING,
                    );
            }
        }

        $temporaryFile = tempnam(sys_get_temp_dir(), 'quranschool-groups-');
        if ($temporaryFile === false) {
            throw new \RuntimeException('Unable to create the groups Excel export.');
        }

        try {
            (new Xlsx($spreadsheet))->save($temporaryFile);
            $contents = file_get_contents($temporaryFile);
            if ($contents === false || ! str_starts_with($contents, 'PK')) {
                throw new \RuntimeException('Unable to read the groups Excel export.');
            }
        } finally {
            if (is_file($temporaryFile) && ! unlink($temporaryFile)) {
                throw new \RuntimeException('Unable to clean up the temporary groups Excel export.');
            }
            $spreadsheet->disconnectWorksheets();
        }

        return $contents;
    }

    private function spreadsheetSafe(string $value): string
    {
        return preg_match('/^[\s]*[=+\-@\t\r]/u', $value) === 1 ? "'".$value : $value;
    }
}
