<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StudentsController extends Controller
{
    public function index(Request $request)
    {
        return $this->table($request);
    }

    public function table(Request $request)
    {
        $user = Auth::user();
        abort_unless($user, 403);

        $schoolId = data_get($user, 'school_id');
        abort_unless($schoolId, 403);

        $role = $user->role ?? null;
        abort_unless(in_array($role, ['admin', 'teacher', 'secretary'], true), 403);

        $yearId = $this->resolveYearId($request, (int) $schoolId);
        $query = $this->baseStudentQuery($request, $user, (int) $schoolId, $yearId);

        $rows = $query
            ->select([
                'students.id',
                'students.student_number',
                'students.full_name',
                'students.status',
                'students.birth_date',
                'students.guardian_name',
                'students.guardian_phone',
                'students.guardian_email',
                'students.enrollment_date',
                'students.notes',
                'students.room_id',
                'rooms.name as room_name',
            ])
            ->orderBy('students.full_name')
            ->get();

        $students = $rows->map(function ($row) use ($role) {
            $canManage = in_array($role, ['admin', 'secretary'], true);

            return [
                'id' => (int) $row->id,
                'student_number' => $row->student_number,
                'full_name' => $row->full_name,
                'status' => $row->status,
                'birth_date' => $row->birth_date,
                'guardian_name' => $canManage ? $row->guardian_name : null,
                'guardian_phone' => $canManage ? $row->guardian_phone : null,
                'guardian_email' => $canManage ? $row->guardian_email : null,
                'enrollment_date' => $row->enrollment_date,
                'notes' => $row->notes,
                'room_id' => $row->room_id ? (int) $row->room_id : null,
                'room_name' => $row->room_name,
                'is_read_only' => ! $canManage,
            ];
        });

        return response()->json([
            'year_id' => $yearId,
            'year_is_active' => (bool) DB::table('academic_years')
                ->where('school_id', $schoolId)
                ->where('id', $yearId)
                ->value('is_active'),
            'students' => $students,
            'can_manage' => in_array($role, ['admin', 'secretary'], true),
            'is_teacher_view' => $role === 'teacher',
        ]);
    }

    public function options(Request $request)
    {
        [$user, $schoolId] = $this->managerContext();
        $yearId = $this->resolveYearId($request, $schoolId);
        $yearIsActive = (bool) DB::table('academic_years')
            ->where('school_id', $schoolId)
            ->where('id', $yearId)
            ->value('is_active');

        return response()->json([
            'year_id' => $yearId,
            'is_editable' => $yearIsActive,
            'rooms' => DB::table('rooms')
                ->where('school_id', $schoolId)
                ->where('year_id', $yearId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function show(Request $request, int $studentId)
    {
        $user = Auth::user();
        abort_unless($user, 403);

        $schoolId = data_get($user, 'school_id');
        abort_unless($schoolId, 403);

        $role = $user->role ?? null;
        abort_unless(in_array($role, ['admin', 'teacher', 'secretary'], true), 403);

        $schoolId = (int) $schoolId;
        $yearId = $this->resolveYearId($request, $schoolId);
        $query = DB::table('students')
            ->leftJoin('rooms', function ($join) {
                $join->on('rooms.id', '=', 'students.room_id')
                    ->on('rooms.school_id', '=', 'students.school_id')
                    ->on('rooms.year_id', '=', 'students.year_id');
            })
            ->where('students.id', $studentId)
            ->where('students.school_id', $schoolId)
            ->where('students.year_id', $yearId);

        if ($role === 'teacher') {
            $today = now()->toDateString();
            $query->whereIn('students.status', ['active', 'paused'])
                ->whereExists(function ($assignment) use ($schoolId, $yearId, $user, $today) {
                    $assignment->selectRaw('1')
                        ->from('room_teachers')
                        ->whereColumn('room_teachers.room_id', 'students.room_id')
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

        $student = $query->first([
            'students.id',
            'students.student_number',
            'students.full_name',
            'students.status',
            'students.birth_date',
            'students.guardian_name',
            'students.guardian_phone',
            'students.guardian_email',
            'students.enrollment_date',
            'students.notes',
            'students.room_id',
            'rooms.name as room_name',
        ]);
        abort_unless($student, 404);

        if ($role === 'teacher') {
            return response()->json([
                'student' => [
                    'id' => (int) $student->id,
                    'full_name' => $student->full_name,
                    'birth_date' => $student->birth_date,
                    'notes' => $student->notes,
                    'room_name' => $student->room_name,
                    'is_read_only' => true,
                ],
            ]);
        }

        return response()->json(['student' => $this->studentPayload($student)]);
    }

    public function store(Request $request)
    {
        [, $schoolId] = $this->managerContext();
        $yearId = $this->validateYearId($request, $schoolId);
        $this->ensureYearIsEditable($schoolId, $yearId);
        $student = $this->validatedStudent($request, $schoolId, $yearId);
        $now = now();

        $studentId = DB::transaction(function () use ($student, $schoolId, $yearId, $now) {
            if ($student['room_id'] !== null && $student['status'] !== 'withdrawn') {
                $this->ensureRoomHasCapacity($schoolId, $yearId, $student['room_id']);
            }

            return DB::table('students')->insertGetId([
                ...$student,
                'school_id' => $schoolId,
                'year_id' => $yearId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        return response()->json([
            'student' => $this->findStudentPayload($studentId, $schoolId, $yearId),
        ], 201);
    }

    public function update(Request $request, int $studentId)
    {
        [, $schoolId] = $this->managerContext();
        $existing = DB::table('students')
            ->where('id', $studentId)
            ->where('school_id', $schoolId)
            ->first(['id', 'year_id']);
        abort_unless($existing, 404);

        $yearId = $this->validateYearId($request, $schoolId);
        abort_unless((int) $existing->year_id === $yearId, 404);
        $this->ensureYearIsEditable($schoolId, $yearId);
        $student = $this->validatedStudent($request, $schoolId, $yearId, $studentId);

        DB::transaction(function () use ($student, $studentId, $schoolId, $yearId) {
            DB::table('students')
                ->where('id', $studentId)
                ->where('school_id', $schoolId)
                ->where('year_id', $yearId)
                ->lockForUpdate()
                ->first(['id']);

            if ($student['room_id'] !== null && $student['status'] !== 'withdrawn') {
                $this->ensureRoomHasCapacity($schoolId, $yearId, $student['room_id'], $studentId);
            }

            DB::table('students')
                ->where('id', $studentId)
                ->where('school_id', $schoolId)
                ->where('year_id', $yearId)
                ->update([...$student, 'updated_at' => now()]);
        });

        return response()->json([
            'student' => $this->findStudentPayload($studentId, $schoolId, $yearId),
        ]);
    }

    public function updateStatus(Request $request, int $studentId)
    {
        [, $schoolId] = $this->managerContext();
        $existing = DB::table('students')
            ->where('id', $studentId)
            ->where('school_id', $schoolId)
            ->first(['id', 'year_id']);
        abort_unless($existing, 404);

        $yearId = $this->validateYearId($request, $schoolId);
        abort_unless((int) $existing->year_id === $yearId, 404);
        $this->ensureYearIsEditable($schoolId, $yearId);
        $validated = $request->validate([
            'status' => ['required', Rule::in(['paused', 'withdrawn'])],
        ]);

        DB::table('students')
            ->where('id', $studentId)
            ->where('school_id', $schoolId)
            ->where('year_id', $yearId)
            ->update([
                'status' => $validated['status'],
                'updated_at' => now(),
            ]);

        return response()->json([
            'student' => $this->findStudentPayload($studentId, $schoolId, $yearId),
        ]);
    }

    private function managerContext(): array
    {
        $user = Auth::user();
        $schoolId = data_get($user, 'school_id');

        abort_unless(
            $user && in_array(data_get($user, 'role'), ['admin', 'secretary'], true) && $schoolId,
            403,
        );

        return [$user, (int) $schoolId];
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
        $isActive = DB::table('academic_years')
            ->where('school_id', $schoolId)
            ->where('id', $yearId)
            ->value('is_active');

        if (! $isActive) {
            throw ValidationException::withMessages([
                'year_id' => 'Students in a closed academic year cannot be changed.',
            ]);
        }
    }

    private function validatedStudent(
        Request $request,
        int $schoolId,
        int $yearId,
        ?int $studentId = null,
    ): array {
        $request->merge([
            'student_number' => trim((string) $request->input('student_number')) ?: null,
            'full_name' => trim((string) $request->input('full_name')),
        ]);

        $studentNumberRule = Rule::unique('students', 'student_number')
            ->where('year_id', $yearId);

        if ($studentId !== null) {
            $studentNumberRule->ignore($studentId);
        }

        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:120'],
            'student_number' => ['nullable', 'string', 'max:30', $studentNumberRule],
            'birth_date' => ['nullable', 'date'],
            'guardian_name' => ['nullable', 'string', 'max:120'],
            'guardian_phone' => ['nullable', 'string', 'max:30'],
            'guardian_email' => ['nullable', 'email', 'max:190'],
            'room_id' => [
                'nullable',
                'integer',
                Rule::exists('rooms', 'id')
                    ->where('school_id', $schoolId)
                    ->where('year_id', $yearId)
                    ->where('is_active', true),
            ],
            'enrollment_date' => ['required', 'date'],
            'status' => ['required', Rule::in(['active', 'paused', 'withdrawn'])],
            'notes' => ['nullable', 'string'],
        ]);

        return [
            'full_name' => $validated['full_name'],
            'student_number' => $validated['student_number'] ?? null,
            'birth_date' => $validated['birth_date'] ?? null,
            'guardian_name' => $validated['guardian_name'] ?? null,
            'guardian_phone' => $validated['guardian_phone'] ?? null,
            'guardian_email' => $validated['guardian_email'] ?? null,
            'room_id' => $validated['room_id'] ?? null,
            'enrollment_date' => $validated['enrollment_date'],
            'status' => $validated['status'],
            'notes' => $validated['notes'] ?? null,
        ];
    }

    private function ensureRoomHasCapacity(
        int $schoolId,
        int $yearId,
        int $roomId,
        ?int $studentId = null,
    ): void {
        $room = DB::table('rooms')
            ->where('id', $roomId)
            ->where('school_id', $schoolId)
            ->where('year_id', $yearId)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first(['id', 'capacity']);
        abort_unless($room, 404);

        $studentsInRoom = DB::table('students')
            ->where('school_id', $schoolId)
            ->where('year_id', $yearId)
            ->where('room_id', $roomId)
            ->whereIn('status', ['active', 'paused'])
            ->when($studentId !== null, fn ($query) => $query->where('id', '!=', $studentId))
            ->count();

        if ($studentsInRoom >= (int) $room->capacity) {
            throw ValidationException::withMessages([
                'room_capacity' => 'The selected room has reached its capacity.',
            ]);
        }
    }

    private function findStudentPayload(int $studentId, int $schoolId, int $yearId): array
    {
        $student = DB::table('students')
            ->leftJoin('rooms', function ($join) {
                $join->on('rooms.id', '=', 'students.room_id')
                    ->on('rooms.school_id', '=', 'students.school_id')
                    ->on('rooms.year_id', '=', 'students.year_id');
            })
            ->where('students.id', $studentId)
            ->where('students.school_id', $schoolId)
            ->where('students.year_id', $yearId)
            ->first([
                'students.id',
                'students.student_number',
                'students.full_name',
                'students.status',
                'students.birth_date',
                'students.guardian_name',
                'students.guardian_phone',
                'students.guardian_email',
                'students.enrollment_date',
                'students.notes',
                'students.room_id',
                'rooms.name as room_name',
            ]);
        abort_unless($student, 404);

        return $this->studentPayload($student);
    }

    private function studentPayload(object $student): array
    {
        return [
            'id' => (int) $student->id,
            'student_number' => $student->student_number,
            'full_name' => $student->full_name,
            'status' => $student->status,
            'birth_date' => $student->birth_date,
            'guardian_name' => $student->guardian_name,
            'guardian_phone' => $student->guardian_phone,
            'guardian_email' => $student->guardian_email,
            'enrollment_date' => $student->enrollment_date,
            'notes' => $student->notes,
            'room_id' => $student->room_id ? (int) $student->room_id : null,
            'room_name' => $student->room_name,
            'is_read_only' => false,
        ];
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

    private function baseStudentQuery(Request $request, object $user, int $schoolId, int $yearId)
    {
        $role = $user->role ?? null;
        $query = DB::table('students')
            ->leftJoin('rooms', 'rooms.id', '=', 'students.room_id')
            ->where('students.school_id', $schoolId)
            ->where('students.year_id', $yearId);

        if ($role === 'teacher') {
            $roomIds = DB::table('room_teachers')
                ->where('school_id', $schoolId)
                ->where('year_id', $yearId)
                ->where('teacher_id', $user->id)
                ->where('assigned_from', '<=', now()->toDateString())
                ->where(function ($query) {
                    $query->whereNull('assigned_until')
                        ->orWhere('assigned_until', '>=', now()->toDateString());
                })
                ->pluck('room_id')
                ->all();

            $query->whereIn('students.room_id', $roomIds);

            if (! $request->has('status')) {
                $query->whereIn('students.status', ['active', 'paused']);
            } elseif ($request->input('status') !== 'all') {
                $query->where('students.status', $request->input('status'));
            }
        } else {
            if ($request->filled('status') && $request->input('status') !== 'all') {
                $query->where('students.status', $request->input('status'));
            }
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            if ($search !== '') {
                $searchTerm = '%'.$search.'%';
                $query->where(function ($query) use ($searchTerm) {
                    $query->where('students.full_name', 'like', $searchTerm)
                        ->orWhere('students.student_number', 'like', $searchTerm)
                        ->orWhere('rooms.name', 'like', $searchTerm);
                });
            }
        }

        return $query;
    }
}
