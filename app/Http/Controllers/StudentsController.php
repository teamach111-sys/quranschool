<?php

namespace App\Http\Controllers;

use App\Support\AuditEventLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

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

    public function export(Request $request): Response
    {
        $user = Auth::user();
        abort_unless($user, 403);

        $schoolId = (int) data_get($user, 'school_id');
        abort_unless($schoolId, 403);

        $role = $user->role ?? null;
        abort_unless(in_array($role, ['admin', 'teacher', 'secretary'], true), 403);

        $yearId = $this->resolveYearId($request, $schoolId);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in($role === 'teacher'
                ? ['all', 'active', 'paused']
                : ['all', 'active', 'paused', 'withdrawn'])],
            'room_id' => [
                'nullable',
                'integer',
                Rule::exists('rooms', 'id')
                    ->where('school_id', $schoolId)
                    ->where('year_id', $yearId),
            ],
            'lang' => ['nullable', Rule::in(['fr', 'ar'])],
            'sort_key' => ['nullable', Rule::in(['full_name', 'student_number', 'room_name', 'status'])],
            'sort_direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'format' => ['required', Rule::in(['csv', 'pdf', 'xlsx'])],
        ]);
        $language = $filters['lang'] ?? 'fr';
        $query = $this->baseStudentQuery($request, $user, $schoolId, $yearId);

        if (! empty($filters['room_id'])) {
            $query->where('students.room_id', (int) $filters['room_id']);
        }

        $canManage = in_array($role, ['admin', 'secretary'], true);
        $sortColumns = [
            'full_name' => 'students.full_name',
            'student_number' => 'students.student_number',
            'room_name' => 'rooms.name',
            'status' => 'students.status',
        ];
        $sortKey = $filters['sort_key'] ?? 'full_name';
        $sortDirection = $filters['sort_direction'] ?? 'asc';
        $students = $query
            ->select([
                'students.student_number',
                'students.full_name',
                'students.status',
                'students.guardian_name',
                'students.guardian_phone',
                'students.guardian_email',
                'rooms.name as room_name',
            ])
            ->orderBy($sortColumns[$sortKey], $sortDirection)
            ->get();

        $school = DB::table('schools')
            ->where('id', $schoolId)
            ->first(['name_fr', 'name_ar']);
        $year = DB::table('academic_years')
            ->where('school_id', $schoolId)
            ->where('id', $yearId)
            ->value('year_label');
        $roomName = isset($filters['room_id'])
            ? DB::table('rooms')
                ->where('id', $filters['room_id'])
                ->where('school_id', $schoolId)
                ->where('year_id', $yearId)
                ->value('name')
            : null;
        $labels = $language === 'ar'
            ? [
                'title' => 'قائمة الطلاب',
                'school' => 'المدرسة',
                'year' => 'السنة الدراسية',
                'count' => 'عدد الطلاب',
                'generated' => 'تاريخ التصدير',
                'filters' => 'عوامل التصفية',
                'number' => 'الرقم',
                'name' => 'الاسم الكامل',
                'room' => 'المجموعة',
                'guardian' => 'ولي الأمر',
                'status' => 'الحالة',
                'active' => 'نشط',
                'paused' => 'متوقف مؤقتًا',
                'withdrawn' => 'منسحب',
            ]
            : [
                'title' => 'Liste des élèves',
                'school' => 'Établissement',
                'year' => 'Année scolaire',
                'count' => 'Nombre d’élèves',
                'generated' => 'Date d’export',
                'filters' => 'Filtres',
                'number' => 'Numéro',
                'name' => 'Nom complet',
                'room' => 'Groupe',
                'guardian' => 'Responsable',
                'status' => 'Statut',
                'active' => 'Actif',
                'paused' => 'En pause',
                'withdrawn' => 'Retiré',
            ];
        $schoolName = $language === 'ar' ? $school->name_ar : $school->name_fr;
        $generatedAt = now()->format('Y-m-d H:i:s');
        $filterSummary = array_filter([
            trim((string) ($filters['search'] ?? '')) !== '' ? trim((string) $filters['search']) : null,
            isset($filters['status']) && $filters['status'] !== 'all' ? $labels[$filters['status']] : null,
            $roomName,
        ]);
        $headers = [$labels['number'], $labels['name'], $labels['room']];
        if ($canManage) {
            $headers[] = $labels['guardian'];
        }
        $headers[] = $labels['status'];
        $rows = $students->map(function ($student) use ($canManage, $labels) {
            $row = [$student->student_number, $student->full_name, $student->room_name];
            if ($canManage) {
                $row[] = $student->guardian_name ?: ($student->guardian_phone ?: $student->guardian_email);
            }
            $row[] = $labels[$student->status] ?? $student->status;

            return $row;
        });
        $format = $filters['format'];
        $contents = match ($format) {
            'csv' => $this->studentsCsv(
                $labels,
                $schoolName,
                (string) $year,
                $students->count(),
                $generatedAt,
                $filterSummary,
                $headers,
                $rows->all(),
            ),
            'xlsx' => $this->studentsXlsx(
                $labels,
                $language,
                $schoolName,
                (string) $year,
                $students->count(),
                $generatedAt,
                $filterSummary,
                $headers,
                $rows->all(),
            ),
            default => $this->studentsPdf(
                $labels,
                $language,
                $schoolName,
                (string) $year,
                $students->count(),
                $generatedAt,
                $filterSummary,
                $headers,
                $rows->all(),
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
            'entity_type' => 'student',
            'entity_id' => null,
            'summary_key' => 'students.exported',
            'changes' => json_encode([
                'format' => $format,
                'page' => 'students',
                'record_count' => $students->count(),
                'filters' => [
                    'search' => trim((string) ($filters['search'] ?? '')) !== '',
                    'status' => $filters['status'] ?? 'all',
                    'room' => $roomName,
                ],
                'sort_key' => $sortKey,
                'sort_direction' => $sortDirection,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        $mimeType = match ($format) {
            'csv' => 'text/csv; charset=UTF-8',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            default => 'application/pdf',
        };

        return response($contents, 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'attachment; filename="students.'.$format.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Export-Record-Count' => (string) $students->count(),
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

            $studentId = DB::table('students')->insertGetId([
                ...$student,
                'school_id' => $schoolId,
                'year_id' => $yearId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            AuditEventLogger::record(
                $schoolId,
                $yearId,
                'student',
                (int) $studentId,
                'created',
                'student.created',
                [],
                array_intersect_key($student, array_flip([
                    'student_number', 'full_name', 'status', 'enrollment_date', 'room_id',
                ])),
            );

            return $studentId;
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
            $before = DB::table('students')
                ->where('id', $studentId)
                ->where('school_id', $schoolId)
                ->where('year_id', $yearId)
                ->lockForUpdate()
                ->first(['student_number', 'full_name', 'status', 'enrollment_date', 'room_id']);
            abort_unless($before, 404);

            if ($student['room_id'] !== null && $student['status'] !== 'withdrawn') {
                $this->ensureRoomHasCapacity($schoolId, $yearId, $student['room_id'], $studentId);
            }

            DB::table('students')
                ->where('id', $studentId)
                ->where('school_id', $schoolId)
                ->where('year_id', $yearId)
                ->update([...$student, 'updated_at' => now()]);
            AuditEventLogger::record(
                $schoolId,
                $yearId,
                'student',
                $studentId,
                'updated',
                'student.updated',
                (array) $before,
                array_intersect_key($student, array_flip([
                    'student_number', 'full_name', 'status', 'enrollment_date', 'room_id',
                ])),
            );
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
        DB::transaction(function () use ($studentId, $schoolId, $yearId, $validated) {
            $before = DB::table('students')
                ->where('id', $studentId)
                ->where('school_id', $schoolId)
                ->where('year_id', $yearId)
                ->lockForUpdate()
                ->value('status');
            abort_unless($before !== null, 404);

            DB::table('students')
                ->where('id', $studentId)
                ->where('school_id', $schoolId)
                ->where('year_id', $yearId)
                ->update([
                    'status' => $validated['status'],
                    'updated_at' => now(),
                ]);
            AuditEventLogger::record(
                $schoolId,
                $yearId,
                'student',
                $studentId,
                'updated',
                'student.status_changed',
                ['status' => $before],
                ['status' => $validated['status']],
            );
        });

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

    /**
     * @param  array<string, string>  $labels
     * @param  array<int, string|null>  $filters
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string|null>>  $rows
     */
    private function studentsCsv(
        array $labels,
        string $schoolName,
        string $year,
        int $count,
        string $generatedAt,
        array $filters,
        array $headers,
        array $rows,
    ): string {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Unable to create the student CSV export.');
        }

        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, [$labels['title']]);
        fputcsv($stream, [$labels['school'], $this->spreadsheetSafe($schoolName)]);
        fputcsv($stream, [$labels['year'], $this->spreadsheetSafe($year)]);
        fputcsv($stream, [$labels['count'], $count]);
        fputcsv($stream, [$labels['generated'], $generatedAt]);
        fputcsv($stream, [$labels['filters'], $this->spreadsheetSafe(implode(' | ', $filters))]);
        fputcsv($stream, []);
        fputcsv($stream, $headers);

        foreach ($rows as $row) {
            fputcsv($stream, array_map(fn ($value) => $this->spreadsheetSafe($value), $row));
        }

        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);
        if ($contents === false) {
            throw new \RuntimeException('Unable to read the student CSV export.');
        }

        return $contents;
    }

    /**
     * @param  array<string, string>  $labels
     * @param  array<int, string>  $filters
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string|null>>  $rows
     */
    private function studentsXlsx(
        array $labels,
        string $language,
        string $schoolName,
        string $year,
        int $count,
        string $generatedAt,
        array $filters,
        array $headers,
        array $rows,
    ): string {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($language === 'ar' ? 'قائمة الطلاب' : 'Élèves');
        $sheet->setRightToLeft($language === 'ar');

        $metadata = [
            [$labels['title']],
            [$labels['school'], $schoolName],
            [$labels['year'], $year],
            [$labels['count'], (string) $count],
            [$labels['generated'], $generatedAt],
            [$labels['filters'], implode(' | ', $filters)],
        ];
        foreach ($metadata as $rowIndex => $row) {
            foreach ($row as $columnIndex => $value) {
                $sheet->getCell([$columnIndex + 1, $rowIndex + 1])
                    ->setValueExplicit(
                        $this->spreadsheetSafe($value),
                        DataType::TYPE_STRING,
                    );
            }
        }

        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
        $headerRow = count($metadata) + 2;
        foreach ($headers as $columnIndex => $heading) {
            $sheet->getCell([$columnIndex + 1, $headerRow])
                ->setValueExplicit($heading, DataType::TYPE_STRING);
            $sheet->getColumnDimensionByColumn($columnIndex + 1)->setAutoSize(true);
        }
        $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
        $lastRow = $headerRow + count($rows);
        $sheet->getStyle("A{$headerRow}:{$lastColumn}{$headerRow}")->getFont()->setBold(true);
        $sheet->freezePane('A'.($headerRow + 1));
        $sheet->setAutoFilter("A{$headerRow}:{$lastColumn}{$lastRow}");

        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $columnIndex => $value) {
                $sheet->getCell([$columnIndex + 1, $headerRow + $rowIndex + 1])
                    ->setValueExplicit($this->spreadsheetSafe($value), DataType::TYPE_STRING);
            }
        }

        $temporaryFile = tempnam(sys_get_temp_dir(), 'quranschool-students-');
        if ($temporaryFile === false) {
            throw new \RuntimeException('Unable to create the student Excel export.');
        }

        try {
            (new Xlsx($spreadsheet))->save($temporaryFile);
            $contents = file_get_contents($temporaryFile);
            if ($contents === false || ! str_starts_with($contents, 'PK')) {
                throw new \RuntimeException('Unable to read the student Excel export.');
            }
        } finally {
            if (is_file($temporaryFile) && ! unlink($temporaryFile)) {
                throw new \RuntimeException('Unable to clean up the temporary student Excel export.');
            }
            $spreadsheet->disconnectWorksheets();
        }

        return $contents;
    }

    /**
     * @param  array<string, string>  $labels
     * @param  array<int, string>  $filters
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string|null>>  $rows
     */
    private function studentsPdf(
        array $labels,
        string $language,
        string $schoolName,
        string $year,
        int $count,
        string $generatedAt,
        array $filters,
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
        $pdf->WriteHTML(view('exports.students', [
            'language' => $language,
            'labels' => $labels,
            'schoolName' => $schoolName,
            'year' => $year,
            'count' => $count,
            'generatedAt' => $generatedAt,
            'filters' => $filters,
            'headers' => $headers,
            'rows' => $rows,
        ])->render());
        $contents = $pdf->Output('', Destination::STRING_RETURN);
        if (! str_starts_with($contents, '%PDF-')) {
            throw new \RuntimeException('The student PDF export is invalid.');
        }

        return $contents;
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

            if (! $request->has('status') || $request->input('status') === 'all') {
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

        if ($request->filled('room_id')) {
            $query->where('students.room_id', $request->input('room_id'));
        }

        return $query;
    }

    private function spreadsheetSafe(?string $value): string
    {
        $value = (string) ($value ?? '');

        return preg_match('/^[\s]*[=+\-@\t\r]/u', $value) === 1 ? "'".$value : $value;
    }
}
