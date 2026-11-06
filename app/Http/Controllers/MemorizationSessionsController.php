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

class MemorizationSessionsController extends Controller
{
    public function table(Request $request): JsonResponse
    {
        [$user, $schoolId] = $this->staffContext();
        $year = $this->resolveYear($request, $schoolId, $user->role);
        $yearId = (int) $year->id;
        $filters = $this->validatedSessionFilters($request, $schoolId, $yearId);
        $sessionsQuery = $this->sessionsQuery($user, $schoolId, $yearId, $filters);

        $sessions = $sessionsQuery
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
                && in_array($user->role, ['admin', 'teacher'], true),
            'form_options' => $this->formOptionsForYear($schoolId, $yearId, $user->role, (int) $user->id, $year),
        ]);
    }

    public function export(Request $request): Response|JsonResponse
    {
        [$user, $schoolId] = $this->staffContext();
        $year = $this->resolveYear($request, $schoolId, $user->role);
        $yearId = (int) $year->id;
        $filters = $this->validatedSessionFilters($request, $schoolId, $yearId);
        $validated = $request->validate([
            'lang' => ['nullable', Rule::in(['fr', 'ar'])],
            'format' => ['nullable', Rule::in(['csv', 'pdf', 'xlsx'])],
        ]);
        $format = $validated['format'] ?? 'csv';

        $records = $this->sessionsQuery($user, $schoolId, $yearId, $filters)
            ->get([
                'memorization_sessions.session_date',
                'memorization_sessions.portion_label',
                'memorization_sessions.evaluation',
                'memorization_sessions.notes',
                'students.full_name as student_name',
                'rooms.name as room_name',
                'teachers.full_name as teacher_name',
            ]);

        if ($records->isEmpty()) {
            return response()->json(['message' => 'No memorization sessions match the selected filters.'], 422);
        }

        $language = $validated['lang'] ?? 'fr';
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
            'entity_type' => 'memorization_session',
            'entity_id' => null,
            'summary_key' => 'memorization.exported',
            'changes' => json_encode([
                'format' => $format,
                'page' => 'memorization_sessions',
                'record_count' => $records->count(),
                'filters' => [
                    'room' => $filterSummary['room'] ?? null,
                    'teacher' => $filterSummary['teacher'] ?? null,
                    'student' => $filterSummary['student'] ?? null,
                    'session_date' => $filters['session_date'] ?? null,
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
            'Content-Disposition' => 'attachment; filename="memorization-sessions.'.$format.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Export-Record-Count' => (string) $records->count(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedSessionFilters(Request $request, int $schoolId, int $yearId): array
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
            'session_date' => ['nullable', 'date_format:Y-m-d'],
            'sort_key' => ['nullable', Rule::in([
                'session_date',
                'student_name',
                'room_name',
                'teacher_name',
                'portion_label',
                'evaluation',
                'notes',
            ])],
            'sort_direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);
    }

    private function sessionsQuery(object $user, int $schoolId, int $yearId, array $filters): Builder
    {
        $query = DB::table('memorization_sessions')
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
            $this->applyTeacherRoomScope($query, (int) $user->id);
        }

        if (! empty($filters['room_id'])) {
            $query->where('memorization_sessions.room_id', (int) $filters['room_id']);
        }
        if (! empty($filters['teacher_id'])) {
            $query->where('memorization_sessions.teacher_id', (int) $filters['teacher_id']);
        }
        if (! empty($filters['student_id'])) {
            $query->where('memorization_sessions.student_id', (int) $filters['student_id']);
        }
        if (! empty($filters['session_date'])) {
            $query->where('memorization_sessions.session_date', $filters['session_date']);
        }

        $sortColumns = [
            'session_date' => 'memorization_sessions.session_date',
            'student_name' => 'students.full_name',
            'room_name' => 'rooms.name',
            'teacher_name' => 'teachers.full_name',
            'portion_label' => 'memorization_sessions.portion_label',
            'evaluation' => 'memorization_sessions.evaluation',
            'notes' => 'memorization_sessions.notes',
        ];
        $sortKey = $filters['sort_key'] ?? null;
        if ($sortKey !== null) {
            $query->orderBy($sortColumns[$sortKey], $filters['sort_direction'] ?? 'asc');
        } else {
            $query->orderByDesc('memorization_sessions.session_date');
        }

        return $query
            ->orderByDesc('memorization_sessions.session_date')
            ->orderBy('students.full_name')
            ->orderBy('memorization_sessions.id');
    }

    /**
     * @return array<string, string>
     */
    private function exportLabels(string $language): array
    {
        return $language === 'ar'
            ? [
                'title' => 'سجل جلسات الحفظ',
                'school' => 'المدرسة',
                'year' => 'السنة الدراسية',
                'count' => 'عدد الجلسات',
                'generated' => 'تاريخ التصدير',
                'filters' => 'عوامل التصفية',
                'date' => 'التاريخ',
                'student' => 'الطالب',
                'room' => 'المجموعة',
                'teacher' => 'المعلم',
                'portion' => 'المقطع المحفوظ',
                'evaluation' => 'التقييم',
                'notes' => 'ملاحظات',
                'excellent' => 'ممتاز',
                'good' => 'جيد',
                'needs_review' => 'يحتاج إلى مراجعة',
                'allRooms' => 'كل المجموعات',
                'allTeachers' => 'كل المعلمين',
                'allStudents' => 'كل الطلاب',
                'room_name' => 'المجموعة',
                'student_name' => 'الطالب',
                'teacher_name' => 'المعلم',
                'session_date' => 'التاريخ',
                'portion_label' => 'المقطع المحفوظ',
                'evaluation_value' => 'التقييم',
                'notes_value' => 'ملاحظات',
                'sort' => 'الترتيب',
                'ascending' => 'تصاعدي',
                'descending' => 'تنازلي',
            ]
            : [
                'title' => 'Registre des séances de mémorisation',
                'school' => 'Établissement',
                'year' => 'Année scolaire',
                'count' => 'Nombre de séances',
                'generated' => 'Date d’export',
                'filters' => 'Filtres',
                'date' => 'Date',
                'student' => 'Élève',
                'room' => 'Groupe',
                'teacher' => 'Enseignant',
                'portion' => 'Portion mémorisée',
                'evaluation' => 'Évaluation',
                'notes' => 'Notes',
                'excellent' => 'Excellent',
                'good' => 'Bien',
                'needs_review' => 'À revoir',
                'allRooms' => 'Tous les groupes',
                'allTeachers' => 'Tous les enseignants',
                'allStudents' => 'Tous les élèves',
                'room_name' => 'Groupe',
                'student_name' => 'Élève',
                'teacher_name' => 'Enseignant',
                'session_date' => 'Date',
                'portion_label' => 'Portion mémorisée',
                'evaluation_value' => 'Évaluation',
                'notes_value' => 'Notes',
                'sort' => 'Tri',
                'ascending' => 'Croissant',
                'descending' => 'Décroissant',
            ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, string>  $labels
     * @return array<string, string>
     */
    private function exportFilterSummary(array $filters, int $schoolId, int $yearId, array $labels): array
    {
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
        if (! empty($filters['session_date'])) {
            $summary['date'] = $filters['session_date'];
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
     * @param  Collection<int, stdClass>  $records
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
            throw new \RuntimeException('Unable to create the memorization sessions CSV export.');
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
            $labels['portion'],
            $labels['evaluation'],
            $labels['notes'],
        ]);

        foreach ($records as $record) {
            fputcsv($stream, [
                $this->spreadsheetSafe($record->session_date),
                $this->spreadsheetSafe($record->student_name),
                $this->spreadsheetSafe($record->room_name),
                $this->spreadsheetSafe($record->teacher_name),
                $this->spreadsheetSafe($record->portion_label),
                $labels[$record->evaluation] ?? $this->spreadsheetSafe($record->evaluation),
                $this->spreadsheetSafe($record->notes),
            ]);
        }

        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);
        if ($contents === false) {
            throw new \RuntimeException('Unable to read the memorization sessions CSV export.');
        }

        return $contents;
    }

    /**
     * @param  Collection<int, stdClass>  $records
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
        $sheet->setTitle($language === 'ar' ? 'سجل الحفظ' : 'Mémorisation');
        $sheet->setRightToLeft($language === 'ar');

        $filterValues = array_map(
            fn ($key, $value) => ($labels[$key] ?? $key).': '.$value,
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
            $labels['portion'],
            $labels['evaluation'],
            $labels['notes'],
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
                $record->session_date,
                $record->student_name,
                $record->room_name,
                $record->teacher_name,
                $record->portion_label,
                $labels[$record->evaluation] ?? $record->evaluation,
                $record->notes,
            ];
            foreach ($row as $columnIndex => $value) {
                $sheet->getCell([$columnIndex + 1, $headerRow + $rowIndex + 1])
                    ->setValueExplicit(
                        $this->spreadsheetSafe((string) ($value ?? '')),
                        DataType::TYPE_STRING,
                    );
            }
        }

        $temporaryFile = tempnam(sys_get_temp_dir(), 'quranschool-memorization-');
        if ($temporaryFile === false) {
            throw new \RuntimeException('Unable to create the memorization sessions Excel export.');
        }

        try {
            (new Xlsx($spreadsheet))->save($temporaryFile);
            $contents = file_get_contents($temporaryFile);
            if ($contents === false || ! str_starts_with($contents, 'PK')) {
                throw new \RuntimeException('Unable to read the memorization sessions Excel export.');
            }
        } finally {
            if (is_file($temporaryFile) && ! unlink($temporaryFile)) {
                throw new \RuntimeException('Unable to clean up the temporary memorization sessions Excel export.');
            }
            $spreadsheet->disconnectWorksheets();
        }

        return $contents;
    }

    /**
     * @param  Collection<int, stdClass>  $records
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
        $pdfRecords = $records->map(function (stdClass $record): stdClass {
            $pdfRecord = clone $record;
            $pdfRecord->portion_label = $this->isolateArabicRuns($record->portion_label);

            return $pdfRecord;
        });
        $pdf->WriteHTML(view('exports.memorization-sessions', [
            'language' => $language,
            'labels' => $labels,
            'schoolName' => $schoolName,
            'yearLabel' => $yearLabel,
            'records' => $pdfRecords,
            'filters' => $filters,
            'generatedAt' => $generatedAt,
        ])->render());
        $contents = $pdf->Output('', Destination::STRING_RETURN);
        if (! str_starts_with($contents, '%PDF-')) {
            throw new \RuntimeException('The memorization sessions PDF export is invalid.');
        }

        return $contents;
    }

    private function isolateArabicRuns(string $value): string
    {
        $isolated = preg_replace(
            '/(\p{Arabic}[\p{Arabic}\p{M}\p{N}]*)/u',
            "\u{2067}$1\u{2069}",
            $value,
        );
        if ($isolated === null) {
            throw new \RuntimeException('Unable to prepare Arabic text for the memorization sessions PDF export.');
        }

        return $isolated;
    }

    private function spreadsheetSafe(?string $value): string
    {
        $value = (string) ($value ?? '');

        return preg_match('/^[\s]*[=+\-@\t\r]/u', $value) === 1 ? "'".$value : $value;
    }

    public function progress(Request $request): JsonResponse
    {
        [$user, $schoolId] = $this->writerContext();
        $year = $this->resolveWritableYear($request, $schoolId);
        $validated = $request->validate([
            'student_id' => [
                'required',
                'integer',
                Rule::exists('students', 'id')
                    ->where('school_id', $schoolId)
                    ->where('year_id', (int) $year->id),
            ],
        ]);
        $student = DB::table('students')
            ->where('id', (int) $validated['student_id'])
            ->where('school_id', $schoolId)
            ->where('year_id', (int) $year->id)
            ->first(['id', 'room_id']);
        abort_unless($student && $student->room_id !== null, 404);

        $this->ensureSessionRoomAndStudent([
            'student_id' => (int) $student->id,
            'room_id' => (int) $student->room_id,
        ], $user->role, $schoolId, (int) $year->id);
        $this->ensureTeacherAssignment(
            $user->role,
            (int) $user->id,
            (int) $student->room_id,
            $schoolId,
            (int) $year->id,
            now()->toDateString(),
        );

        $sessions = DB::table('memorization_sessions')
            ->where('student_id', (int) $student->id)
            ->where('school_id', $schoolId)
            ->where('year_id', (int) $year->id)
            ->whereNotNull('surah_start')
            ->whereNotNull('ayah_start')
            ->whereNotNull('surah_end')
            ->whereNotNull('ayah_end')
            ->orderBy('session_date')
            ->orderBy('id')
            ->get([
                'id',
                'surah_start',
                'ayah_start',
                'surah_end',
                'ayah_end',
            ])
            ->map(fn ($session) => [
                'id' => (int) $session->id,
                'surah_start' => (int) $session->surah_start,
                'ayah_start' => (int) $session->ayah_start,
                'surah_end' => (int) $session->surah_end,
                'ayah_end' => (int) $session->ayah_end,
            ])
            ->values();

        return response()->json([
            'student_id' => (int) $student->id,
            'sessions' => $sessions,
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

            $sessionId = DB::table('memorization_sessions')->insertGetId([
                ...$validated,
                'school_id' => $schoolId,
                'year_id' => (int) $year->id,
                'teacher_id' => $teacherId,
                'created_at' => now(),
            ]);
            AuditEventLogger::record(
                $schoolId,
                (int) $year->id,
                'memorization_session',
                (int) $sessionId,
                'created',
                'memorization_session.created',
                [],
                [
                    'student_id' => (int) $validated['student_id'],
                    'room_id' => (int) $validated['room_id'],
                    'session_date' => $validated['session_date'],
                    'portion_label' => $validated['portion_label'],
                    'evaluation' => $validated['evaluation'],
                    'teacher_id' => $teacherId,
                ],
            );

            return $sessionId;
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
                ->first(['id', 'teacher_id', 'student_id', 'room_id', 'session_date', 'portion_label', 'evaluation']);
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
            AuditEventLogger::record(
                $schoolId,
                (int) $year->id,
                'memorization_session',
                $sessionId,
                'updated',
                'memorization_session.updated',
                [
                    'student_id' => (int) $session->student_id,
                    'room_id' => (int) $session->room_id,
                    'session_date' => $session->session_date,
                    'portion_label' => $session->portion_label,
                    'evaluation' => $session->evaluation,
                    'teacher_id' => (int) $session->teacher_id,
                ],
                [
                    'student_id' => (int) $validated['student_id'],
                    'room_id' => (int) $validated['room_id'],
                    'session_date' => $validated['session_date'],
                    'portion_label' => $validated['portion_label'],
                    'evaluation' => $validated['evaluation'],
                    'teacher_id' => $teacherId,
                ],
            );
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
        $quranMetadata = json_decode(
            file_get_contents(resource_path('data/quran-metadata.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $surahsById = collect($quranMetadata['surahs'])->keyBy('id');
        $surahIds = $surahsById->keys()->all();

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
            'surah_start' => ['nullable', 'integer', Rule::in($surahIds)],
            'ayah_start' => ['nullable', 'integer', 'min:1'],
            'surah_end' => ['nullable', 'integer', Rule::in($surahIds)],
            'ayah_end' => ['nullable', 'integer', 'min:1'],
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

        if ($rangeHasStart && $surahStart !== null && $ayahStart !== null) {
            $startAyahCount = (int) $surahsById->get($surahStart)['ayah_count'];
            if ($ayahStart > $startAyahCount) {
                throw ValidationException::withMessages([
                    'ayah_start' => "The start ayah exceeds the selected surah's limit of {$startAyahCount}.",
                ]);
            }
        }

        if ($rangeHasEnd && $surahEnd !== null && $ayahEnd !== null) {
            $endAyahCount = (int) $surahsById->get($surahEnd)['ayah_count'];
            if ($ayahEnd > $endAyahCount) {
                throw ValidationException::withMessages([
                    'ayah_end' => "The end ayah exceeds the selected surah's limit of {$endAyahCount}.",
                ]);
            }
        }

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
            ->first(['id', 'start_date', 'end_date', 'is_active']);
        abort_unless($year, 404);

        if (! $year->is_active) {
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
            $query->where('is_active', true);
            if ($yearId === null) {
                $query->where('is_current', true);
            }
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
