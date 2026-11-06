<?php

namespace App\Http\Controllers;

use App\Support\AuditEventLogger;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
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
use stdClass;

class AttendanceController extends Controller
{
    private const STATUSES = ['present', 'absent', 'late', 'excused'];

    public function table(Request $request): JsonResponse
    {
        [$user, $schoolId] = $this->staffContext();
        $year = $this->resolveYear($request, $schoolId);
        $yearId = (int) $year->id;
        $yearIsWritable = (bool) $year->is_active;
        $yearIsCurrent = (bool) $year->is_current;
        $filters = $this->validatedTableFilters($request, $schoolId, $yearId);

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

        $attendance = $this->attendanceQuery($user, $schoolId, $yearId, $filters)
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
                'can_edit' => $yearIsWritable && ($user->role === 'admin'
                    || $user->role === 'secretary'
                    || (
                        $user->role === 'teacher'
                        && (int) $record->recorded_by === (int) $user->id
                        && $record->attendance_date === now()->toDateString()
                    )),
            ])
            ->values();

        return response()->json([
            'year_id' => $yearId,
            'year_is_writable' => $yearIsWritable,
            'year_is_current' => $yearIsCurrent,
            'today' => now()->toDateString(),
            'attendance' => $attendance,
            'rooms' => $rooms,
            'teachers' => $teachers,
            'students' => $students,
            'statuses' => self::STATUSES,
            'can_correct' => $yearIsWritable && $user->role === 'admin',
            'can_mark' => $yearIsWritable && $yearIsCurrent && $user->role === 'teacher',
            'can_follow_up' => $yearIsWritable && $user->role === 'secretary',
        ]);
    }

    public function export(Request $request): Response|JsonResponse
    {
        [$user, $schoolId] = $this->staffContext();
        $year = $this->resolveYear($request, $schoolId);
        $yearId = (int) $year->id;
        $filters = $this->validatedTableFilters($request, $schoolId, $yearId);
        $validated = $request->validate([
            'lang' => ['nullable', Rule::in(['fr', 'ar'])],
            'format' => ['nullable', Rule::in(['csv', 'pdf', 'xlsx'])],
        ]);
        $language = $validated['lang'] ?? 'fr';
        $format = $validated['format'] ?? 'csv';
        $records = $this->attendanceQuery($user, $schoolId, $yearId, $filters)
            ->get([
                'attendance.attendance_date',
                'students.full_name as student_name',
                'rooms.name as room_name',
                'teachers.full_name as teacher_name',
                'attendance.status',
                'attendance.note',
            ]);

        if ($records->isEmpty()) {
            return response()->json(['message' => 'No attendance matches the selected filters.'], 422);
        }

        $schoolName = DB::table('schools')
            ->where('id', $schoolId)
            ->value($language === 'ar' ? 'name_ar' : 'name_fr');
        abort_unless(is_string($schoolName) && $schoolName !== '', 500);
        $yearLabel = DB::table('academic_years')
            ->where('school_id', $schoolId)
            ->where('id', $yearId)
            ->value('year_label');
        abort_unless(is_string($yearLabel) && $yearLabel !== '', 500);
        $labels = $this->exportLabels($language);
        $filterSummary = $this->exportFilterSummary($filters, $schoolId, $yearId, $labels);
        $generatedAt = now()->format('Y-m-d H:i:s');
        $contents = match ($format) {
            'xlsx' => $this->xlsxContents(
                $records,
                $labels,
                $language,
                $schoolName,
                $yearLabel,
                $filterSummary,
                $generatedAt,
            ),
            'pdf' => $this->pdfContents(
                $records,
                $labels,
                $language,
                $schoolName,
                $yearLabel,
                $filterSummary,
                $generatedAt,
            ),
            default => $this->csvContents(
                $records,
                $labels,
                $schoolName,
                $yearLabel,
                $filterSummary,
                $generatedAt,
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
            'entity_type' => 'attendance',
            'entity_id' => null,
            'summary_key' => 'attendance.exported',
            'changes' => json_encode([
                'format' => $format,
                'page' => 'attendance',
                'record_count' => $records->count(),
                'filters' => [
                    'room' => $filterSummary['room'] ?? null,
                    'teacher' => $filterSummary['teacher'] ?? null,
                    'student' => $filterSummary['student'] ?? null,
                    'attendance_date' => $filters['attendance_date'] ?? null,
                    'status' => $filters['status'] ?? null,
                ],
                'sort_key' => $filters['sort_key'] ?? null,
                'sort_direction' => $filters['sort_direction'] ?? null,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        $mimeType = match ($format) {
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pdf' => 'application/pdf',
            default => 'text/csv; charset=UTF-8',
        };

        return response($contents, 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'attachment; filename="attendance.'.$format.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Export-Record-Count' => (string) $records->count(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedTableFilters(Request $request, int $schoolId, int $yearId): array
    {
        return $request->validate([
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
            'sort_key' => ['nullable', Rule::in([
                'attendance_date',
                'student_name',
                'room_name',
                'teacher_name',
                'status',
                'note',
            ])],
            'sort_direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function attendanceQuery(object $user, int $schoolId, int $yearId, array $filters): Builder
    {
        $query = DB::table('attendance')
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
            $this->applyTeacherRoomScope($query, (int) $user->id);
        }

        if (! empty($filters['room_id'])) {
            $query->where('attendance.room_id', (int) $filters['room_id']);
        }
        if (! empty($filters['teacher_id'])) {
            $query->where('assignments.teacher_id', (int) $filters['teacher_id']);
        }
        if (! empty($filters['student_id'])) {
            $query->where('attendance.student_id', (int) $filters['student_id']);
        }
        if (! empty($filters['attendance_date'])) {
            $query->where('attendance.attendance_date', $filters['attendance_date']);
        }
        if (! empty($filters['status'])) {
            $query->where('attendance.status', $filters['status']);
        }

        $sortColumns = [
            'attendance_date' => 'attendance.attendance_date',
            'student_name' => 'students.full_name',
            'room_name' => 'rooms.name',
            'teacher_name' => 'teachers.full_name',
            'status' => 'attendance.status',
            'note' => 'attendance.note',
        ];
        $sortKey = $filters['sort_key'] ?? null;
        if ($sortKey === null) {
            return $query
                ->orderByDesc('attendance.attendance_date')
                ->orderBy('students.full_name')
                ->orderBy('attendance.id');
        }

        return $query
            ->orderBy($sortColumns[$sortKey], $filters['sort_direction'] ?? 'asc')
            ->orderByDesc('attendance.attendance_date')
            ->orderBy('students.full_name')
            ->orderBy('attendance.id');
    }

    /**
     * @return array<string, string>
     */
    private function exportLabels(string $language): array
    {
        return $language === 'ar'
            ? [
                'title' => 'سجل الحضور',
                'school' => 'المدرسة',
                'year' => 'السنة الدراسية',
                'count' => 'عدد السجلات',
                'generated' => 'تاريخ التصدير',
                'filters' => 'عوامل التصفية',
                'date' => 'التاريخ',
                'student' => 'الطالب',
                'room' => 'المجموعة',
                'teacher' => 'المعلم',
                'status' => 'الحالة',
                'note' => 'ملاحظة',
                'allRooms' => 'كل المجموعات',
                'allTeachers' => 'كل المعلمين',
                'allStudents' => 'كل الطلاب',
                'allStatuses' => 'كل الحالات',
                'present' => 'حاضر',
                'absent' => 'غائب',
                'late' => 'متأخر',
                'excused' => 'بعذر',
                'sort' => 'الترتيب',
                'ascending' => 'تصاعدي',
                'descending' => 'تنازلي',
                'student_name' => 'الطالب',
                'room_name' => 'المجموعة',
                'teacher_name' => 'المعلم',
            ]
            : [
                'title' => 'Registre des présences',
                'school' => 'Établissement',
                'year' => 'Année scolaire',
                'count' => 'Nombre de présences',
                'generated' => 'Date d’export',
                'filters' => 'Filtres',
                'date' => 'Date',
                'student' => 'Élève',
                'room' => 'Groupe',
                'teacher' => 'Enseignant',
                'status' => 'Statut',
                'note' => 'Note',
                'allRooms' => 'Tous les groupes',
                'allTeachers' => 'Tous les enseignants',
                'allStudents' => 'Tous les élèves',
                'allStatuses' => 'Tous les statuts',
                'present' => 'Présent',
                'absent' => 'Absent',
                'late' => 'En retard',
                'excused' => 'Excusé',
                'sort' => 'Tri',
                'ascending' => 'Croissant',
                'descending' => 'Décroissant',
                'student_name' => 'Élève',
                'room_name' => 'Groupe',
                'teacher_name' => 'Enseignant',
            ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, string>  $labels
     * @return array<string, string>
     */
    private function exportFilterSummary(
        array $filters,
        int $schoolId,
        int $yearId,
        array $labels,
    ): array {
        $summary = [];
        foreach ([
            'room_id' => ['rooms', 'name', 'room'],
            'teacher_id' => ['users', 'full_name', 'teacher'],
            'student_id' => ['students', 'full_name', 'student'],
        ] as $filterKey => [$table, $column, $labelKey]) {
            if (! empty($filters[$filterKey])) {
                $summary[$labelKey] = (string) DB::table($table)
                    ->where('id', $filters[$filterKey])
                    ->where('school_id', $schoolId)
                    ->when($table !== 'users', fn ($query) => $query->where('year_id', $yearId))
                    ->value($column);
            }
        }
        if (! empty($filters['attendance_date'])) {
            $summary['date'] = $filters['attendance_date'];
        }
        if (! empty($filters['status'])) {
            $summary['status'] = $labels[$filters['status']];
        }
        if (! empty($filters['sort_key'])) {
            $sortLabel = $labels[$filters['sort_key']] ?? $filters['sort_key'];
            $direction = ($filters['sort_direction'] ?? 'asc') === 'desc'
                ? $labels['descending']
                : $labels['ascending'];
            $summary['sort'] = $sortLabel.' ('.$direction.')';
        }

        return $summary;
    }

    /**
     * @param  Collection<int, object>  $records
     * @param  array<string, string>  $labels
     * @param  array<string, string>  $filters
     */
    private function csvContents(
        Collection $records,
        array $labels,
        string $schoolName,
        string $yearLabel,
        array $filters,
        string $generatedAt,
    ): string {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Unable to create the attendance CSV export.');
        }
        $filterValues = array_map(
            fn ($key, $value) => $labels[$key].': '.$value,
            array_keys($filters),
            array_values($filters),
        );

        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, [$labels['title']]);
        fputcsv($stream, [$labels['school'], $this->spreadsheetSafe($schoolName)]);
        fputcsv($stream, [$labels['year'], $this->spreadsheetSafe($yearLabel)]);
        fputcsv($stream, [$labels['count'], $records->count()]);
        fputcsv($stream, [$labels['generated'], $generatedAt]);
        fputcsv($stream, [$labels['filters'], $this->spreadsheetSafe(implode(' | ', $filterValues))]);
        fputcsv($stream, []);
        fputcsv($stream, [
            $labels['date'],
            $labels['student'],
            $labels['room'],
            $labels['teacher'],
            $labels['status'],
            $labels['note'],
        ]);
        foreach ($records as $record) {
            fputcsv($stream, [
                $this->spreadsheetSafe($record->attendance_date),
                $this->spreadsheetSafe($record->student_name),
                $this->spreadsheetSafe($record->room_name),
                $this->spreadsheetSafe($record->teacher_name),
                $labels[$record->status] ?? $this->spreadsheetSafe($record->status),
                $this->spreadsheetSafe($record->note),
            ]);
        }

        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);
        if ($contents === false) {
            throw new \RuntimeException('Unable to read the attendance CSV export.');
        }

        return $contents;
    }

    /**
     * @param  Collection<int, object>  $records
     * @param  array<string, string>  $labels
     * @param  array<string, string>  $filters
     */
    private function xlsxContents(
        Collection $records,
        array $labels,
        string $language,
        string $schoolName,
        string $yearLabel,
        array $filters,
        string $generatedAt,
    ): string {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($language === 'ar' ? 'سجل الحضور' : 'Présences');
        $sheet->setRightToLeft($language === 'ar');

        $filterValues = array_map(
            fn ($key, $value) => $labels[$key].': '.$value,
            array_keys($filters),
            array_values($filters),
        );
        $metadata = [
            [$labels['title']],
            [$labels['school'], $schoolName],
            [$labels['year'], $yearLabel],
            [$labels['count'], (string) $records->count()],
            [$labels['generated'], $generatedAt],
            [$labels['filters'], implode(' | ', $filterValues)],
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
        $headings = [
            $labels['date'],
            $labels['student'],
            $labels['room'],
            $labels['teacher'],
            $labels['status'],
            $labels['note'],
        ];
        foreach ($headings as $columnIndex => $heading) {
            $sheet->getCell([$columnIndex + 1, $headerRow])
                ->setValueExplicit($heading, DataType::TYPE_STRING);
            $sheet->getColumnDimensionByColumn($columnIndex + 1)->setAutoSize(true);
        }
        $lastColumn = Coordinate::stringFromColumnIndex(count($headings));
        $lastRow = $headerRow + $records->count();
        $sheet->getStyle("A{$headerRow}:{$lastColumn}{$headerRow}")->getFont()->setBold(true);
        $sheet->freezePane('A'.($headerRow + 1));
        $sheet->setAutoFilter("A{$headerRow}:{$lastColumn}{$lastRow}");

        foreach ($records as $rowIndex => $record) {
            $row = [
                $record->attendance_date,
                $record->student_name,
                $record->room_name,
                $record->teacher_name,
                $labels[$record->status] ?? $record->status,
                $record->note,
            ];
            foreach ($row as $columnIndex => $value) {
                $sheet->getCell([$columnIndex + 1, $headerRow + $rowIndex + 1])
                    ->setValueExplicit($this->spreadsheetSafe((string) ($value ?? '')), DataType::TYPE_STRING);
            }
        }

        $temporaryFile = tempnam(sys_get_temp_dir(), 'quranschool-attendance-');
        if ($temporaryFile === false) {
            throw new \RuntimeException('Unable to create the attendance Excel export.');
        }

        try {
            (new Xlsx($spreadsheet))->save($temporaryFile);
            $contents = file_get_contents($temporaryFile);
            if ($contents === false || ! str_starts_with($contents, 'PK')) {
                throw new \RuntimeException('Unable to read the attendance Excel export.');
            }
        } finally {
            if (is_file($temporaryFile) && ! unlink($temporaryFile)) {
                throw new \RuntimeException('Unable to clean up the temporary attendance Excel export.');
            }
            $spreadsheet->disconnectWorksheets();
        }

        return $contents;
    }

    /**
     * @param  Collection<int, object>  $records
     * @param  array<string, string>  $labels
     * @param  array<string, string>  $filters
     */
    private function pdfContents(
        Collection $records,
        array $labels,
        string $language,
        string $schoolName,
        string $yearLabel,
        array $filters,
        string $generatedAt,
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
        $pdf->WriteHTML(view('exports.attendance', [
            'language' => $language,
            'labels' => $labels,
            'schoolName' => $schoolName,
            'yearLabel' => $yearLabel,
            'records' => $records,
            'filters' => $filters,
            'generatedAt' => $generatedAt,
        ])->render());
        $contents = $pdf->Output('', Destination::STRING_RETURN);
        if (! str_starts_with($contents, '%PDF-')) {
            throw new \RuntimeException('The attendance PDF export is invalid.');
        }

        return $contents;
    }

    private function spreadsheetSafe(?string $value): string
    {
        $value = (string) ($value ?? '');

        return preg_match('/^[\s]*[=+\-@\t\r]/u', $value) === 1 ? "'".$value : $value;
    }

    public function roster(Request $request): JsonResponse
    {
        [$user, $schoolId] = $this->staffContext();
        abort_unless($user->role === 'teacher', 403);

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
            ->first(['id', 'is_current', 'is_active']);
        abort_unless($year, 404);

        if (! $year->is_current || ! $year->is_active) {
            throw ValidationException::withMessages([
                'year_id' => 'Attendance rosters are only available for the current active academic year.',
            ]);
        }

        $today = now()->toDateString();
        $rooms = DB::table('rooms')
            ->join('room_teachers', function (JoinClause $join) {
                $join->on('room_teachers.room_id', '=', 'rooms.id')
                    ->on('room_teachers.school_id', '=', 'rooms.school_id')
                    ->on('room_teachers.year_id', '=', 'rooms.year_id');
            })
            ->where('rooms.school_id', $schoolId)
            ->where('rooms.year_id', $yearId)
            ->where('rooms.is_active', true)
            ->where('room_teachers.teacher_id', $user->id)
            ->where('room_teachers.assigned_from', '<=', $today)
            ->where(function (Builder $dates) use ($today) {
                $dates->whereNull('room_teachers.assigned_until')
                    ->orWhere('room_teachers.assigned_until', '>=', $today);
            })
            ->orderBy('rooms.name')
            ->get(['rooms.id', 'rooms.name']);

        $students = DB::table('students')
            ->leftJoin('attendance', function (JoinClause $join) use ($today) {
                $join->on('attendance.student_id', '=', 'students.id')
                    ->on('attendance.school_id', '=', 'students.school_id')
                    ->on('attendance.year_id', '=', 'students.year_id')
                    ->where('attendance.attendance_date', $today);
            })
            ->where('students.school_id', $schoolId)
            ->where('students.year_id', $yearId)
            ->where('students.status', 'active')
            ->whereIn('students.room_id', $rooms->pluck('id'))
            ->orderBy('students.full_name')
            ->get([
                'students.id',
                'students.full_name as name',
                'students.student_number',
                'students.room_id',
                'attendance.status',
                'attendance.recorded_by',
            ])
            ->groupBy('room_id');

        return response()->json([
            'year_id' => (int) $yearId,
            'attendance_date' => $today,
            'rooms' => $rooms->map(fn ($room) => [
                'id' => (int) $room->id,
                'name' => $room->name,
                'students' => ($students->get($room->id, collect()))
                    ->map(fn ($student) => [
                        'id' => (int) $student->id,
                        'name' => $student->name,
                        'student_number' => $student->student_number,
                        'status' => $student->status,
                        'can_edit' => $student->status === null
                            || (int) $student->recorded_by === (int) $user->id,
                    ])
                    ->values(),
            ])->values(),
        ]);
    }

    public function storeBulk(Request $request): JsonResponse
    {
        [$user, $schoolId] = $this->staffContext();
        abort_unless($user->role === 'teacher', 403);

        $yearId = (int) $request->validate([
            'year_id' => [
                'required',
                'integer',
                Rule::exists('academic_years', 'id')->where('school_id', $schoolId),
            ],
        ])['year_id'];
        $entries = $request->validate([
            'entries' => ['required', 'array', 'min:1', 'max:200'],
            'entries.*' => ['required', 'array:student_id,status'],
            'entries.*.student_id' => [
                'required',
                'integer',
                'distinct',
            ],
            'entries.*.status' => ['required', Rule::in(self::STATUSES)],
        ]);
        $today = now()->toDateString();

        $savedCount = DB::transaction(function () use ($entries, $user, $schoolId, $yearId, $today) {
            $year = DB::table('academic_years')
                ->where('id', $yearId)
                ->where('school_id', $schoolId)
                ->lockForUpdate()
                ->first(['is_current', 'is_active', 'start_date', 'end_date']);
            abort_unless($year, 404);

            if (
                ! $year->is_current
                || ! $year->is_active
                || $today < $year->start_date
                || $today > $year->end_date
            ) {
                throw ValidationException::withMessages([
                    'year_id' => 'Attendance can only be marked for the current active academic year.',
                ]);
            }

            $studentIds = collect($entries['entries'])
                ->pluck('student_id')
                ->map(fn ($studentId) => (int) $studentId)
                ->all();
            $students = $this->lockBulkStudentsInAssignedRooms(
                $studentIds,
                $schoolId,
                $yearId,
                (int) $user->id,
                $today,
            );

            $existingAttendance = DB::table('attendance')
                ->where('school_id', $schoolId)
                ->where('year_id', $yearId)
                ->where('attendance_date', $today)
                ->whereIn('student_id', $studentIds)
                ->lockForUpdate()
                ->get(['student_id', 'recorded_by'])
                ->keyBy('student_id');

            if ($existingAttendance->contains(
                fn ($record) => (int) $record->recorded_by !== (int) $user->id,
            )) {
                abort(403);
            }

            $now = now();
            $rows = collect($entries['entries'])
                ->map(fn (array $entry) => [
                    'student_id' => (int) $entry['student_id'],
                    'school_id' => $schoolId,
                    'room_id' => (int) $students->get((int) $entry['student_id'])->room_id,
                    'year_id' => $yearId,
                    'recorded_by' => (int) $user->id,
                    'attendance_date' => $today,
                    'status' => $entry['status'],
                    'note' => null,
                    'created_at' => $now,
                ])
                ->all();

            DB::table('attendance')->upsert(
                $rows,
                ['student_id', 'year_id', 'attendance_date'],
                ['room_id', 'recorded_by', 'status'],
            );
            AuditEventLogger::record(
                $schoolId,
                $yearId,
                'attendance',
                null,
                'created',
                'attendance.bulk_marked',
                [],
                [
                    'record_count' => count($rows),
                    'attendance_date' => $today,
                    'status_counts' => collect($rows)->countBy('status')->all(),
                ],
            );

            return count($rows);
        });

        return response()->json(['saved_count' => $savedCount]);
    }

    public function undoBulk(Request $request): JsonResponse
    {
        [$user, $schoolId] = $this->staffContext();
        abort_unless($user->role === 'teacher', 403);

        $yearId = (int) $request->validate([
            'year_id' => [
                'required',
                'integer',
                Rule::exists('academic_years', 'id')->where('school_id', $schoolId),
            ],
        ])['year_id'];
        $entries = $request->validate([
            'entries' => ['required', 'array', 'min:1', 'max:200'],
            'entries.*' => ['required', 'array:student_id,saved_status,previous_status'],
            'entries.*.student_id' => [
                'required',
                'integer',
                'distinct',
            ],
            'entries.*.saved_status' => ['required', Rule::in(self::STATUSES)],
            'entries.*.previous_status' => ['present', 'nullable', Rule::in(self::STATUSES)],
        ]);
        $today = now()->toDateString();

        $undoneCount = DB::transaction(function () use ($entries, $user, $schoolId, $yearId, $today) {
            $year = DB::table('academic_years')
                ->where('id', $yearId)
                ->where('school_id', $schoolId)
                ->lockForUpdate()
                ->first(['is_current', 'is_active', 'start_date', 'end_date']);
            abort_unless($year, 404);

            if (
                ! $year->is_current
                || ! $year->is_active
                || $today < $year->start_date
                || $today > $year->end_date
            ) {
                abort(409);
            }

            $studentIds = collect($entries['entries'])
                ->pluck('student_id')
                ->map(fn ($studentId) => (int) $studentId)
                ->all();
            $this->lockBulkStudentsInAssignedRooms(
                $studentIds,
                $schoolId,
                $yearId,
                (int) $user->id,
                $today,
            );
            $attendance = DB::table('attendance')
                ->where('school_id', $schoolId)
                ->where('year_id', $yearId)
                ->where('attendance_date', $today)
                ->whereIn('student_id', $studentIds)
                ->lockForUpdate()
                ->get(['id', 'student_id', 'status', 'recorded_by'])
                ->keyBy('student_id');

            foreach ($entries['entries'] as $entry) {
                $record = $attendance->get((int) $entry['student_id']);
                if (
                    ! $record
                    || (int) $record->recorded_by !== (int) $user->id
                    || $record->status !== $entry['saved_status']
                ) {
                    abort(409);
                }
            }

            $createdStudentIds = [];
            $restoreByStatus = [];
            foreach ($entries['entries'] as $entry) {
                if ($entry['previous_status'] === null) {
                    $createdStudentIds[] = (int) $entry['student_id'];

                    continue;
                }

                $restoreByStatus[$entry['previous_status']][] = (int) $entry['student_id'];
            }

            if ($createdStudentIds !== []) {
                DB::table('attendance')
                    ->where('school_id', $schoolId)
                    ->where('year_id', $yearId)
                    ->where('attendance_date', $today)
                    ->where('recorded_by', $user->id)
                    ->whereIn('student_id', $createdStudentIds)
                    ->delete();
            }

            foreach ($restoreByStatus as $status => $studentIdsForStatus) {
                DB::table('attendance')
                    ->where('school_id', $schoolId)
                    ->where('year_id', $yearId)
                    ->where('attendance_date', $today)
                    ->where('recorded_by', $user->id)
                    ->whereIn('student_id', $studentIdsForStatus)
                    ->update(['status' => $status]);
            }
            AuditEventLogger::record(
                $schoolId,
                $yearId,
                'attendance',
                null,
                'updated',
                'attendance.bulk_undone',
                ['record_count' => 0],
                ['record_count' => count($entries['entries'])],
            );

            return count($entries['entries']);
        });

        return response()->json(['undone_count' => $undoneCount]);
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
            ->first(['start_date', 'end_date', 'is_active']);
        abort_unless($year, 404);
        if (! $year->is_active) {
            throw ValidationException::withMessages([
                'year_id' => 'Attendance in a read-only academic year cannot be changed.',
            ]);
        }

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
                ->first(['id', 'room_id', 'recorded_by', 'status', 'note']);

            if ($user->role === 'secretary') {
                abort_unless($existing, 404);

                DB::table('attendance')
                    ->where('id', $existing->id)
                    ->where('school_id', $schoolId)
                    ->update(['note' => $validated['note']]);
                AuditEventLogger::record(
                    $schoolId,
                    (int) $yearId,
                    'attendance',
                    (int) $existing->id,
                    'updated',
                    'attendance.updated',
                    ['note' => $existing->note],
                    ['note' => $validated['note']],
                );

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
                    'recorded_by' => $user->id,
                    'attendance_date' => $validated['attendance_date'],
                    'status' => $validated['status'],
                    'note' => $validated['note'] ?? null,
                    'created_at' => $existing ? null : $now,
                ]],
                ['student_id', 'year_id', 'attendance_date'],
                ['room_id', 'recorded_by', 'status', 'note'],
            );

            $attendanceId = DB::table('attendance')
                ->where('student_id', $student->id)
                ->where('year_id', $yearId)
                ->where('attendance_date', $validated['attendance_date'])
                ->value('id');
            AuditEventLogger::record(
                $schoolId,
                (int) $yearId,
                'attendance',
                (int) $attendanceId,
                $existing ? 'updated' : 'created',
                $existing ? 'attendance.updated' : 'attendance.created',
                $existing ? [
                    'status' => $existing->status,
                    'note' => $existing->note,
                    'room_id' => $existing->room_id === null ? null : (int) $existing->room_id,
                ] : [],
                [
                    'student_id' => (int) $student->id,
                    'attendance_date' => $validated['attendance_date'],
                    'status' => $validated['status'],
                    'room_id' => $roomId === null ? null : (int) $roomId,
                ],
            );

            return ['attendance_id' => (int) $attendanceId, 'created' => ! $existing];
        });

        return response()->json($result, $result['created'] ? 201 : 200);
    }

    public function destroy(int $attendanceId): JsonResponse
    {
        [$user, $schoolId] = $this->staffContext();
        abort_unless($user->role === 'teacher', 403);

        $yearId = DB::table('academic_years')
            ->where('school_id', $schoolId)
            ->where('is_current', true)
            ->where('is_active', true)
            ->value('id');
        abort_unless($yearId, 404);

        $attendanceDate = now()->toDateString();

        DB::transaction(function () use ($attendanceId, $attendanceDate, $schoolId, $user, $yearId) {
            $attendance = DB::table('attendance')
                ->where('id', $attendanceId)
                ->where('school_id', $schoolId)
                ->where('year_id', $yearId)
                ->where('attendance_date', $attendanceDate)
                ->lockForUpdate()
                ->first(['student_id', 'room_id', 'recorded_by', 'status', 'attendance_date']);
            abort_unless($attendance, 404);

            abort_unless((int) $attendance->recorded_by === (int) $user->id, 403);
            abort_unless(
                $attendance->room_id !== null
                    && $this->teacherOwnsActiveRoom(
                        (int) $user->id,
                        (int) $attendance->room_id,
                        $schoolId,
                        (int) $yearId,
                        $attendanceDate,
                    ),
                403,
            );

            DB::table('attendance')
                ->where('id', $attendanceId)
                ->where('school_id', $schoolId)
                ->where('year_id', $yearId)
                ->where('attendance_date', $attendanceDate)
                ->delete();
            AuditEventLogger::record(
                $schoolId,
                (int) $yearId,
                'attendance',
                $attendanceId,
                'deleted',
                'attendance.deleted',
                [
                    'student_id' => (int) $attendance->student_id,
                    'room_id' => $attendance->room_id === null ? null : (int) $attendance->room_id,
                    'attendance_date' => $attendance->attendance_date,
                    'status' => $attendance->status,
                ],
                [],
            );
        });

        return response()->json(['deleted' => true]);
    }

    private function lockBulkStudentsInAssignedRooms(
        array $studentIds,
        int $schoolId,
        int $yearId,
        int $teacherId,
        string $attendanceDate,
    ): Collection {
        $students = DB::table('students')
            ->where('school_id', $schoolId)
            ->where('year_id', $yearId)
            ->whereIn('id', $studentIds)
            ->lockForUpdate()
            ->get(['id', 'room_id', 'status'])
            ->keyBy('id');

        if ($students->count() !== count($studentIds) || $students->contains(
            fn ($student) => $student->status !== 'active',
        )) {
            abort(403);
        }

        $roomIds = $students->pluck('room_id')->filter()->unique()->values();
        $assignedRoomIds = DB::table('rooms')
            ->join('room_teachers', function (JoinClause $join) {
                $join->on('room_teachers.room_id', '=', 'rooms.id')
                    ->on('room_teachers.school_id', '=', 'rooms.school_id')
                    ->on('room_teachers.year_id', '=', 'rooms.year_id');
            })
            ->where('rooms.school_id', $schoolId)
            ->where('rooms.year_id', $yearId)
            ->where('rooms.is_active', true)
            ->whereIn('rooms.id', $roomIds)
            ->where('room_teachers.teacher_id', $teacherId)
            ->where('room_teachers.assigned_from', '<=', $attendanceDate)
            ->where(function (Builder $dates) use ($attendanceDate) {
                $dates->whereNull('room_teachers.assigned_until')
                    ->orWhere('room_teachers.assigned_until', '>=', $attendanceDate);
            })
            ->lockForUpdate()
            ->pluck('rooms.id')
            ->map(fn ($roomId) => (int) $roomId)
            ->all();

        if ($students->contains(
            fn ($student) => $student->room_id === null
                || ! in_array((int) $student->room_id, $assignedRoomIds, true),
        )) {
            abort(403);
        }

        return $students;
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

        $year = $query->orderByDesc('start_date')->first([
            'id',
            'start_date',
            'end_date',
            'is_current',
            'is_active',
        ]);
        abort_unless($year, 404);

        return $year;
    }
}
