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

        $year = DB::table('academic_years')
            ->where('school_id', $schoolId)
            ->where('id', $yearId)
            ->first(['is_active']);
        $yearIsWritable = (bool) $year?->is_active;

        return response()->json([
            'year_id' => $yearId,
            'year_is_active' => (bool) $year?->is_active,
            'year_is_writable' => $yearIsWritable,
            'students' => $students,
            'can_manage' => in_array($role, ['admin', 'secretary'], true),
            'is_teacher_view' => $role === 'teacher',
        ]);
    }

    public function options(Request $request)
    {
        [$user, $schoolId] = $this->managerContext();
        $yearId = $this->resolveYearId($request, $schoolId);
        $year = DB::table('academic_years')
            ->where('school_id', $schoolId)
            ->where('id', $yearId)
            ->first(['id', 'start_date', 'is_active']);

        $nextStudentNumber = $this->nextStudentNumberForYear($schoolId, $yearId);
        $reEnrollmentCandidates = $this->previousYearStudentsForYear($schoolId, $yearId);
        $reEnrollmentStudent = $reEnrollmentCandidates[0] ?? null;

        return response()->json([
            'year_id' => $yearId,
            'is_editable' => (bool) $year?->is_active,
            'next_student_number' => $nextStudentNumber,
            'rooms' => DB::table('rooms')
                ->where('school_id', $schoolId)
                ->where('year_id', $yearId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
            'previous_year_student' => $reEnrollmentStudent,
            're_enrollment_student' => $reEnrollmentStudent,
            'previous_year_students' => $reEnrollmentCandidates,
            're_enrollment_candidates' => $reEnrollmentCandidates,
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

        $today = now()->startOfDay();
        $attendanceByDate = DB::table('attendance')
            ->where('student_id', $studentId)
            ->where('school_id', $schoolId)
            ->where('year_id', $yearId)
            ->whereBetween('attendance_date', [
                $today->copy()->subDays(29)->toDateString(),
                $today->toDateString(),
            ])
            ->get(['attendance_date', 'status'])
            ->keyBy('attendance_date');
        $attendanceDays = collect(range(29, 0))
            ->map(function (int $daysAgo) use ($today, $attendanceByDate): array {
                $date = $today->copy()->subDays($daysAgo)->toDateString();

                return [
                    'date' => $date,
                    'status' => $attendanceByDate->get($date)?->status,
                ];
            });
        $memorizationSessions = DB::table('memorization_sessions')
            ->where('student_id', $studentId)
            ->where('school_id', $schoolId)
            ->where('year_id', $yearId)
            ->orderByDesc('session_date')
            ->orderByDesc('id')
            ->get([
                'id',
                'session_date',
                'surah_start',
                'ayah_start',
                'surah_end',
                'ayah_end',
                'portion_label',
                'evaluation',
            ])
            ->map(fn ($session): array => [
                'id' => (int) $session->id,
                'session_date' => $session->session_date,
                'surah_start' => $session->surah_start === null ? null : (int) $session->surah_start,
                'ayah_start' => $session->ayah_start === null ? null : (int) $session->ayah_start,
                'surah_end' => $session->surah_end === null ? null : (int) $session->surah_end,
                'ayah_end' => $session->ayah_end === null ? null : (int) $session->ayah_end,
                'portion_label' => $session->portion_label,
                'evaluation' => $session->evaluation,
            ])
            ->values();
        $profileData = [
            'attendance_last_30_days' => $attendanceDays,
            'memorization_sessions' => $memorizationSessions,
        ];

        if ($role === 'teacher') {
            return response()->json([
                'student' => [
                    'id' => (int) $student->id,
                    'full_name' => $student->full_name,
                    'birth_date' => $student->birth_date,
                    'notes' => $student->notes,
                    'room_name' => $student->room_name,
                    'is_read_only' => true,
                    ...$profileData,
                ],
            ]);
        }

        $profileData['payment_summary'] = DB::table('payments')
            ->where('student_id', $studentId)
            ->where('school_id', $schoolId)
            ->where('year_id', $yearId)
            ->select('currency')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN status IN (?, ?) THEN amount ELSE 0 END), 0) as outstanding_amount',
                ['due', 'late'],
            )
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN amount ELSE 0 END), 0) as due_amount', ['due'])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN amount ELSE 0 END), 0) as late_amount', ['late'])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN amount ELSE 0 END), 0) as waived_amount', ['waived'])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as due_count', ['due'])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as late_count', ['late'])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as waived_count', ['waived'])
            ->groupBy('currency')
            ->orderBy('currency')
            ->get()
            ->map(fn ($payment): array => [
                'currency' => $payment->currency,
                'outstanding_amount' => (string) $payment->outstanding_amount,
                'due_amount' => (string) $payment->due_amount,
                'late_amount' => (string) $payment->late_amount,
                'waived_amount' => (string) $payment->waived_amount,
                'due_count' => (int) $payment->due_count,
                'late_count' => (int) $payment->late_count,
                'waived_count' => (int) $payment->waived_count,
            ])
            ->values();

        return response()->json([
            'student' => [
                ...$this->studentPayload($student),
                ...$profileData,
            ],
        ]);
    }

    public function store(Request $request)
    {
        [, $schoolId] = $this->managerContext();
        $yearId = $this->validateYearId($request, $schoolId);
        $this->ensureYearIsEditable($schoolId, $yearId);
        $this->validateReEnrollmentSelection($request, $schoolId, $yearId);
        $this->applyReEnrollmentDefaults($request, $schoolId, $yearId);
        $student = $this->validatedStudent($request, $schoolId, $yearId);
        if (empty($student['student_number'])) {
            $student['student_number'] = $this->nextStudentNumberForYear($schoolId, $yearId);
        }
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

    private function previousAcademicYearIdForSchoolYear(int $schoolId, int $yearId): ?int
    {
        $thisYear = DB::table('academic_years')
            ->where('school_id', $schoolId)
            ->where('id', $yearId)
            ->first(['id', 'start_date']);

        if (! $thisYear || ! $thisYear->start_date) {
            return null;
        }

        $previousYear = DB::table('academic_years')
            ->where('school_id', $schoolId)
            ->where('start_date', '<', $thisYear->start_date)
            ->orderByDesc('start_date')
            ->first(['id']);

        return $previousYear?->id ?? null;
    }

    private function previousYearStudentsForYear(int $schoolId, int $yearId): array
    {
        $previousYearId = $this->previousAcademicYearIdForSchoolYear($schoolId, $yearId);

        if ($previousYearId === null) {
            return [];
        }

        return DB::table('students')
            ->where('school_id', $schoolId)
            ->where('year_id', $previousYearId)
            ->whereIn('status', ['active', 'paused'])
            ->orderByDesc('enrollment_date')
            ->orderByDesc('id')
            ->limit(20)
            ->get([
                'id',
                'full_name',
                'student_number',
                'birth_date',
                'guardian_name',
                'guardian_phone',
                'guardian_email',
                'room_id',
                'status',
                'enrollment_date',
            ])
            ->map(fn ($student): array => [
                'id' => (int) $student->id,
                'full_name' => $student->full_name,
                'student_number' => $student->student_number,
                'birth_date' => $student->birth_date,
                'guardian_name' => $student->guardian_name,
                'guardian_phone' => $student->guardian_phone,
                'guardian_email' => $student->guardian_email,
                'room_id' => $student->room_id ? (int) $student->room_id : null,
                'status' => $student->status,
                'enrollment_date' => $student->enrollment_date,
            ])
            ->values()
            ->all();
    }

    private function validateReEnrollmentSelection(Request $request, int $schoolId, int $yearId): void
    {
        $selectedId = $request->input('re_enrollment_student_id', $request->input('previous_year_student_id'));

        if ($selectedId === null || $selectedId === '') {
            return;
        }

        $previousYearId = $this->previousAcademicYearIdForSchoolYear($schoolId, $yearId);

        $exists = DB::table('students')
            ->where('id', (int) $selectedId)
            ->where('school_id', $schoolId)
            ->where('year_id', $previousYearId)
            ->whereIn('status', ['active', 'paused'])
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                're_enrollment_student_id' => 'The selected re-enrollment record is not valid for this school and year.',
            ]);
        }
    }

    private function applyReEnrollmentDefaults(Request $request, int $schoolId, int $yearId): void
    {
        $selectedId = $request->input('re_enrollment_student_id', $request->input('previous_year_student_id'));

        if ($selectedId === null || $selectedId === '') {
            return;
        }

        $previousYearId = $this->previousAcademicYearIdForSchoolYear($schoolId, $yearId);

        $previousStudent = DB::table('students')
            ->where('id', $selectedId)
            ->where('school_id', $schoolId)
            ->when($previousYearId !== null, fn ($query) => $query->where('year_id', $previousYearId))
            ->first([
                'id',
                'full_name',
                'birth_date',
                'guardian_name',
                'guardian_phone',
                'guardian_email',
                'room_id',
                'status',
                'enrollment_date',
            ]);

        if (! $previousStudent) {
            return;
        }

        $roomId = $request->input('room_id');
        if ($roomId === null || $roomId === '') {
            $roomId = DB::table('rooms')
                ->where('id', $previousStudent->room_id)
                ->where('school_id', $schoolId)
                ->where('year_id', $yearId)
                ->where('is_active', true)
                ->value('id');
        }

        $request->merge([
            'full_name' => trim((string) $request->input('full_name', $previousStudent->full_name)) ?: $previousStudent->full_name,
            'birth_date' => $request->input('birth_date', $previousStudent->birth_date) ?: $previousStudent->birth_date,
            'guardian_name' => $request->input('guardian_name', $previousStudent->guardian_name) ?: $previousStudent->guardian_name,
            'guardian_phone' => $request->input('guardian_phone', $previousStudent->guardian_phone) ?: $previousStudent->guardian_phone,
            'guardian_email' => $request->input('guardian_email', $previousStudent->guardian_email) ?: $previousStudent->guardian_email,
            'room_id' => $roomId,
            'status' => $request->input('status', 'active') ?: 'active',
            'enrollment_date' => $request->input('enrollment_date', $previousStudent->enrollment_date) ?: $previousStudent->enrollment_date,
        ]);
    }

    private function nextStudentNumberForYear(int $schoolId, int $yearId): string
    {
        $year = DB::table('academic_years')
            ->where('school_id', $schoolId)
            ->where('id', $yearId)
            ->first(['year_label', 'start_date']);

        $yearPrefix = $year?->year_label
            ? (string) explode('-', (string) $year->year_label)[0]
            : ($year?->start_date ? (new \DateTimeImmutable($year->start_date))->format('Y') : now()->format('Y'));

        $highestSequence = DB::table('students')
            ->where('school_id', $schoolId)
            ->where('year_id', $yearId)
            ->whereNotNull('student_number')
            ->pluck('student_number')
            ->reduce(function (?int $carry, mixed $value): ?int {
                $number = trim((string) $value);

                if (preg_match('/^QS-(\d{4})-(\d+)$/', $number, $matches)) {
                    return max((int) ($carry ?? 0), (int) $matches[2]);
                }

                if (preg_match('/^S-(\d+)$/', $number, $matches)) {
                    return max((int) ($carry ?? 0), (int) $matches[1]);
                }

                return $carry;
            }, 0);

        return sprintf('QS-%s-%03d', $yearPrefix, ($highestSequence ?? 0) + 1);
    }

    private function ensureYearIsEditable(int $schoolId, int $yearId): void
    {
        $year = DB::table('academic_years')
            ->where('school_id', $schoolId)
            ->where('id', $yearId)
            ->first(['is_active']);

        if (! $year || ! $year->is_active) {
            throw ValidationException::withMessages([
                'year_id' => 'Students in a read-only academic year cannot be changed.',
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
