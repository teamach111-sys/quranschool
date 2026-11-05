<?php

namespace App\Http\Controllers;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
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

class SchedulesController extends Controller
{
    public function table(Request $request): JsonResponse
    {
        [$user, $schoolId] = $this->staffContext();
        $yearId = $this->resolveYearId($request, $schoolId);

        $schedulesQuery = $this->schedulesQuery($user, $schoolId, $yearId);

        $schedules = $schedulesQuery
            ->orderBy('schedules.day_of_week')
            ->orderBy('schedules.starts_at')
            ->orderBy('rooms.name')
            ->get([
                'schedules.id',
                'schedules.room_id',
                'schedules.teacher_id',
                'schedules.day_of_week',
                'schedules.starts_at',
                'schedules.ends_at',
                'schedules.valid_from',
                'schedules.valid_until',
                'schedules.label',
                'rooms.name as room_name',
                'users.full_name as teacher_name',
            ])
            ->map(fn ($schedule) => [
                'id' => (int) $schedule->id,
                'room_id' => (int) $schedule->room_id,
                'room_name' => $schedule->room_name,
                'teacher_id' => (int) $schedule->teacher_id,
                'teacher_name' => $schedule->teacher_name,
                'day_of_week' => (int) $schedule->day_of_week,
                'starts_at' => substr($schedule->starts_at, 0, 5),
                'ends_at' => substr($schedule->ends_at, 0, 5),
                'valid_from' => $schedule->valid_from,
                'valid_until' => $schedule->valid_until,
                'label' => $schedule->label,
            ])
            ->values();

        $academicYear = DB::table('academic_years')
            ->where('school_id', $schoolId)
            ->where('id', $yearId)
            ->first(['is_active']);
        abort_unless($academicYear, 404);

        $response = [
            'year_id' => $yearId,
            'year_is_writable' => (bool) $academicYear->is_active,
            'schedules' => $schedules,
            'can_manage' => $user->role === 'admin',
        ];

        if ($user->role === 'admin') {
            $response['year_is_active'] = (bool) $academicYear->is_active;
            $response['rooms'] = DB::table('rooms')
                ->where('school_id', $schoolId)
                ->where('year_id', $yearId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($room) => ['id' => (int) $room->id, 'name' => $room->name])
                ->values();
            $response['teachers'] = DB::table('users')
                ->join('roles', 'roles.id', '=', 'users.role_id')
                ->where('users.school_id', $schoolId)
                ->where('users.is_active', true)
                ->where('roles.role_key', 'teacher')
                ->orderBy('users.full_name')
                ->get(['users.id', 'users.full_name'])
                ->map(fn ($teacher) => ['id' => (int) $teacher->id, 'name' => $teacher->full_name])
                ->values();
        }

        return response()->json($response);
    }

    public function export(Request $request): Response|JsonResponse
    {
        [$user, $schoolId] = $this->staffContext();
        $yearId = $this->resolveYearId($request, $schoolId);
        $validated = $request->validate([
            'lang' => ['nullable', Rule::in(['fr', 'ar'])],
            'format' => ['required', Rule::in(['csv', 'pdf', 'xlsx'])],
            'audience' => ['nullable', Rule::in(['students'])],
            'room_id' => [
                'required_if:audience,students',
                'nullable',
                'integer',
                Rule::exists('rooms', 'id')
                    ->where('school_id', $schoolId)
                    ->where('year_id', $yearId),
            ],
        ]);
        $language = $validated['lang'] ?? 'fr';
        $format = $validated['format'];
        $studentWeeklyView = ($validated['audience'] ?? null) === 'students';
        $roomId = isset($validated['room_id']) ? (int) $validated['room_id'] : null;
        $records = $this->schedulesQuery($user, $schoolId, $yearId, $roomId)
            ->orderBy('schedules.day_of_week')
            ->orderBy('schedules.starts_at')
            ->orderBy('rooms.name')
            ->get([
                'schedules.day_of_week',
                'schedules.starts_at',
                'schedules.ends_at',
                'schedules.valid_from',
                'schedules.valid_until',
                'schedules.label',
                'rooms.name as room_name',
                'users.full_name as teacher_name',
            ]);

        if ($records->isEmpty()) {
            return response()->json(['message' => 'No schedules are available for this academic year.'], 422);
        }
        if ($studentWeeklyView && $format !== 'pdf') {
            throw ValidationException::withMessages([
                'audience' => 'The student weekly view is available only as a PDF.',
            ]);
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
        $roomName = $roomId === null
            ? $labels['allRooms']
            : DB::table('rooms')
                ->where('id', $roomId)
                ->where('school_id', $schoolId)
                ->where('year_id', $yearId)
                ->value('name');
        abort_unless(is_string($roomName) && $roomName !== '', 500);
        $generatedAt = now()->format('Y-m-d H:i:s');
        $contents = match ($format) {
            'xlsx' => $this->xlsxContents(
                $records,
                $labels,
                $language,
                $schoolName,
                $yearLabel,
                $roomName,
                $generatedAt,
                $studentWeeklyView,
            ),
            'pdf' => $this->pdfContents(
                $records,
                $labels,
                $language,
                $schoolName,
                $yearLabel,
                $roomName,
                $generatedAt,
                $studentWeeklyView,
            ),
            default => $this->csvContents($records, $labels, $schoolName, $yearLabel, $roomName, $generatedAt),
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
            'entity_type' => 'schedule',
            'entity_id' => null,
            'summary_key' => 'schedules.exported',
            'changes' => json_encode([
                'format' => $format,
                'page' => 'schedules',
                'record_count' => $records->count(),
                'scope' => $user->role === 'teacher' ? 'own_schedule' : 'school_schedule',
                'filters' => ['room' => $roomId === null ? null : $roomName],
                'audience' => $studentWeeklyView ? 'students' : null,
                'view' => $studentWeeklyView ? 'weekly' : 'list',
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        return response($contents, 200, [
            'Content-Type' => match ($format) {
                'pdf' => 'application/pdf',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                default => 'text/csv; charset=UTF-8',
            },
            'Content-Disposition' => "attachment; filename*=UTF-8''".rawurlencode($this->exportFilename(
                $language,
                $format,
                $studentWeeklyView,
            )),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Export-Record-Count' => (string) $records->count(),
            'X-Export-View' => $studentWeeklyView ? 'student-weekly' : 'list',
        ]);
    }

    private function schedulesQuery(
        object $user,
        int $schoolId,
        int $yearId,
        ?int $roomId = null,
    ): Builder {
        $query = DB::table('schedules')
            ->join('rooms', function ($join) {
                $join->on('rooms.id', '=', 'schedules.room_id')
                    ->on('rooms.school_id', '=', 'schedules.school_id')
                    ->on('rooms.year_id', '=', 'schedules.year_id');
            })
            ->join('users', function ($join) {
                $join->on('users.id', '=', 'schedules.teacher_id')
                    ->on('users.school_id', '=', 'schedules.school_id');
            })
            ->where('schedules.school_id', $schoolId)
            ->where('schedules.year_id', $yearId);

        if ($user->role === 'teacher') {
            $query->where('schedules.teacher_id', $user->id);
        }
        if ($roomId !== null) {
            $query->where('schedules.room_id', $roomId);
        }

        return $query;
    }

    /**
     * @return array<string, string>
     */
    private function exportLabels(string $language): array
    {
        return $language === 'ar'
            ? [
                'title' => 'الجدول الأسبوعي',
                'studentTitle' => 'الجدول الأسبوعي للمجموعة',
                'school' => 'المدرسة',
                'year' => 'السنة الدراسية',
                'count' => 'عدد المواعيد',
                'generated' => 'تاريخ التصدير',
                'day' => 'اليوم',
                'time' => 'الوقت',
                'room' => 'المجموعة',
                'teacher' => 'المعلم',
                'course' => 'الحصة',
                'validity' => 'فترة الصلاحية',
                'noEndDate' => 'بلا تاريخ انتهاء',
                'allRooms' => 'كل المجموعات',
                'monday' => 'الاثنين',
                'tuesday' => 'الثلاثاء',
                'wednesday' => 'الأربعاء',
                'thursday' => 'الخميس',
                'friday' => 'الجمعة',
                'saturday' => 'السبت',
                'sunday' => 'الأحد',
            ]
            : [
                'title' => 'Planning hebdomadaire',
                'studentTitle' => 'Emploi du temps hebdomadaire',
                'school' => 'Établissement',
                'year' => 'Année scolaire',
                'count' => 'Nombre de créneaux',
                'generated' => 'Date d’export',
                'day' => 'Jour',
                'time' => 'Horaire',
                'room' => 'Groupe',
                'teacher' => 'Enseignant',
                'course' => 'Cours',
                'validity' => 'Période de validité',
                'noEndDate' => 'Sans date de fin',
                'allRooms' => 'Tous les groupes',
                'monday' => 'Lundi',
                'tuesday' => 'Mardi',
                'wednesday' => 'Mercredi',
                'thursday' => 'Jeudi',
                'friday' => 'Vendredi',
                'saturday' => 'Samedi',
                'sunday' => 'Dimanche',
            ];
    }

    /**
     * @param  Collection<int, object>  $records
     * @param  array<string, string>  $labels
     */
    private function csvContents(
        Collection $records,
        array $labels,
        string $schoolName,
        string $yearLabel,
        string $roomName,
        string $generatedAt,
    ): string {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Unable to create the schedule CSV export.');
        }

        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, [$labels['title']]);
        fputcsv($stream, [$labels['school'], $this->spreadsheetSafe($schoolName)]);
        fputcsv($stream, [$labels['year'], $this->spreadsheetSafe($yearLabel)]);
        fputcsv($stream, [$labels['room'], $this->spreadsheetSafe($roomName)]);
        fputcsv($stream, [$labels['count'], $records->count()]);
        fputcsv($stream, [$labels['generated'], $generatedAt]);
        fputcsv($stream, []);
        fputcsv($stream, [
            $labels['day'],
            $labels['time'],
            $labels['room'],
            $labels['teacher'],
            $labels['course'],
            $labels['validity'],
        ]);

        foreach ($records as $record) {
            fputcsv($stream, [
                $this->dayLabel((int) $record->day_of_week, $labels),
                substr($record->starts_at, 0, 5).'–'.substr($record->ends_at, 0, 5),
                $this->spreadsheetSafe($record->room_name),
                $this->spreadsheetSafe($record->teacher_name),
                $this->spreadsheetSafe($record->label),
                $record->valid_from.' – '.($record->valid_until ?: $labels['noEndDate']),
            ]);
        }

        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);
        if ($contents === false) {
            throw new \RuntimeException('Unable to read the schedule CSV export.');
        }

        return $contents;
    }

    /**
     * @param  Collection<int, object>  $records
     * @param  array<string, string>  $labels
     */
    private function pdfContents(
        Collection $records,
        array $labels,
        string $language,
        string $schoolName,
        string $yearLabel,
        string $roomName,
        string $generatedAt,
        bool $studentWeeklyView = false,
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
        $view = $studentWeeklyView ? 'exports.schedule-student-weekly' : 'exports.schedules';
        $pdf->SetTitle($studentWeeklyView ? $labels['studentTitle'] : $labels['title']);
        $pdf->WriteHTML(view($view, [
            'language' => $language,
            'labels' => $labels,
            'schoolName' => $schoolName,
            'yearLabel' => $yearLabel,
            'roomName' => $roomName,
            'records' => $records,
            'generatedAt' => $generatedAt,
            'dayLabel' => fn (int $day) => $this->dayLabel($day, $labels),
            'days' => collect(range(1, 7))->map(fn (int $day) => [
                'number' => $day,
                'label' => $this->dayLabel($day, $labels),
            ]),
            'periods' => $studentWeeklyView ? $this->studentCalendarPeriods($records) : [],
        ])->render());
        $contents = $pdf->Output('', Destination::STRING_RETURN);
        if (! str_starts_with($contents, '%PDF-')) {
            throw new \RuntimeException('The schedule PDF export is invalid.');
        }

        return $contents;
    }

    /**
     * @param  Collection<int, object>  $records
     * @return array<int, array{key: string, label: string}>
     */
    private function studentCalendarPeriods(Collection $records): array
    {
        return $records
            ->map(fn ($record) => [
                'key' => substr($record->starts_at, 0, 5).'|'.substr($record->ends_at, 0, 5),
                'label' => substr($record->starts_at, 0, 5).'–'.substr($record->ends_at, 0, 5),
                'start' => $this->timeToMinutes($record->starts_at),
                'end' => $this->timeToMinutes($record->ends_at),
            ])
            ->unique('key')
            ->sortBy(fn ($period) => sprintf('%05d-%05d', $period['start'], $period['end']))
            ->map(fn ($period) => [
                'key' => $period['key'],
                'label' => $period['label'],
            ])
            ->values()
            ->all();
    }

    private function timeToMinutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', substr($time, 0, 5)));

        return $hours * 60 + $minutes;
    }

    private function exportFilename(string $language, string $format, bool $studentWeeklyView): string
    {
        if ($language === 'ar') {
            return $studentWeeklyView
                ? 'جدول-الطلاب.pdf'
                : 'الجدول-الأسبوعي.'.$format;
        }

        return $studentWeeklyView
            ? 'emploi-du-temps-eleves.pdf'
            : 'emploi-du-temps.'.$format;
    }

    /**
     * @param  Collection<int, object>  $records
     * @param  array<string, string>  $labels
     */
    private function xlsxContents(
        Collection $records,
        array $labels,
        string $language,
        string $schoolName,
        string $yearLabel,
        string $roomName,
        string $generatedAt,
    ): string {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($language === 'ar' ? 'الجدول الأسبوعي' : 'Planning');
        $sheet->setRightToLeft($language === 'ar');

        $metadata = [
            [$labels['title']],
            [$labels['school'], $schoolName],
            [$labels['year'], $yearLabel],
            [$labels['room'], $roomName],
            [$labels['count'], (string) $records->count()],
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
        $headings = [
            $labels['day'],
            $labels['time'],
            $labels['room'],
            $labels['teacher'],
            $labels['course'],
            $labels['validity'],
        ];
        foreach ($headings as $index => $heading) {
            $sheet->getCell([$index + 1, $headerRow])
                ->setValueExplicit($heading, DataType::TYPE_STRING);
            $sheet->getColumnDimensionByColumn($index + 1)->setAutoSize(true);
        }
        $lastColumn = Coordinate::stringFromColumnIndex(count($headings));
        $lastRow = $headerRow + $records->count();
        $sheet->getStyle("A{$headerRow}:{$lastColumn}{$headerRow}")->getFont()->setBold(true);
        $sheet->freezePane('A'.($headerRow + 1));
        $sheet->setAutoFilter("A{$headerRow}:{$lastColumn}{$lastRow}");

        foreach ($records as $rowIndex => $record) {
            $row = [
                $this->dayLabel((int) $record->day_of_week, $labels),
                substr($record->starts_at, 0, 5).'–'.substr($record->ends_at, 0, 5),
                $record->room_name,
                $record->teacher_name,
                $record->label,
                $record->valid_from.' – '.($record->valid_until ?: $labels['noEndDate']),
            ];
            foreach ($row as $columnIndex => $value) {
                $sheet->getCell([$columnIndex + 1, $headerRow + $rowIndex + 1])
                    ->setValueExplicit(
                        $this->spreadsheetSafe((string) ($value ?? '')),
                        DataType::TYPE_STRING,
                    );
            }
        }

        $temporaryFile = tempnam(sys_get_temp_dir(), 'quranschool-schedules-');
        if ($temporaryFile === false) {
            throw new \RuntimeException('Unable to create the schedule Excel export.');
        }

        try {
            (new Xlsx($spreadsheet))->save($temporaryFile);
            $contents = file_get_contents($temporaryFile);
            if ($contents === false || ! str_starts_with($contents, 'PK')) {
                throw new \RuntimeException('Unable to read the schedule Excel export.');
            }
        } finally {
            if (is_file($temporaryFile) && ! unlink($temporaryFile)) {
                throw new \RuntimeException('Unable to clean up the temporary schedule Excel export.');
            }
            $spreadsheet->disconnectWorksheets();
        }

        return $contents;
    }

    /**
     * @param  array<string, string>  $labels
     */
    private function dayLabel(int $dayOfWeek, array $labels): string
    {
        $dayKey = [
            1 => 'monday',
            2 => 'tuesday',
            3 => 'wednesday',
            4 => 'thursday',
            5 => 'friday',
            6 => 'saturday',
            7 => 'sunday',
        ][$dayOfWeek] ?? null;

        return $dayKey === null ? (string) $dayOfWeek : $labels[$dayKey];
    }

    private function spreadsheetSafe(?string $value): string
    {
        $value = (string) ($value ?? '');

        return preg_match('/^[\s]*[=+\-@\t\r]/u', $value) === 1 ? "'".$value : $value;
    }

    public function store(Request $request): JsonResponse
    {
        [, $schoolId] = $this->adminContext();
        $yearId = $this->validateYearId($request, $schoolId);
        $this->ensureYearIsEditable($schoolId, $yearId);
        $validated = $this->validatedSchedule($request);

        $scheduleId = DB::transaction(function () use ($validated, $schoolId, $yearId) {
            $this->lockScheduleResources($validated, $schoolId, $yearId);
            $this->ensureNoScheduleOverlap($validated, $schoolId, $yearId);

            return DB::table('schedules')->insertGetId([
                ...$validated,
                'school_id' => $schoolId,
                'year_id' => $yearId,
                'created_at' => now(),
            ]);
        });

        return response()->json(['schedule_id' => $scheduleId], 201);
    }

    public function update(Request $request, int $scheduleId): JsonResponse
    {
        [, $schoolId] = $this->adminContext();
        $yearId = $this->validateYearId($request, $schoolId);
        $this->ensureYearIsEditable($schoolId, $yearId);
        $validated = $this->validatedSchedule($request);

        DB::transaction(function () use ($validated, $scheduleId, $schoolId, $yearId) {
            $schedule = DB::table('schedules')
                ->where('id', $scheduleId)
                ->where('school_id', $schoolId)
                ->where('year_id', $yearId)
                ->lockForUpdate()
                ->first(['id']);
            abort_unless($schedule, 404);

            $this->lockScheduleResources($validated, $schoolId, $yearId);
            $this->ensureNoScheduleOverlap($validated, $schoolId, $yearId, $scheduleId);

            DB::table('schedules')
                ->where('id', $scheduleId)
                ->where('school_id', $schoolId)
                ->where('year_id', $yearId)
                ->update($validated);
        });

        return response()->json(['schedule_id' => $scheduleId]);
    }

    /**
     * @return array{room_id: int, teacher_id: int, day_of_week: int, starts_at: string, ends_at: string, valid_from: string, valid_until: ?string, label: string}
     */
    private function validatedSchedule(Request $request): array
    {
        $validator = Validator::make($request->all(), [
            'room_id' => ['required', 'integer'],
            'teacher_id' => ['required', 'integer'],
            'day_of_week' => ['required', 'integer', 'between:1,7'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i'],
            'valid_from' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'label' => ['nullable', 'string', 'max:100'],
        ]);
        $validator->after(function ($validator) use ($request) {
            if (
                ! $validator->errors()->hasAny(['starts_at', 'ends_at'])
                && $request->input('ends_at') <= $request->input('starts_at')
            ) {
                $validator->errors()->add('ends_at', 'The end time must be after the start time.');
            }
        });
        $validated = $validator->validate();

        return [
            'room_id' => (int) $validated['room_id'],
            'teacher_id' => (int) $validated['teacher_id'],
            'day_of_week' => (int) $validated['day_of_week'],
            'starts_at' => $validated['starts_at'],
            'ends_at' => $validated['ends_at'],
            'valid_from' => $validated['valid_from'],
            'valid_until' => $validated['valid_until'] ?? null,
            'label' => ($validated['label'] ?? null) ?: 'Hifz',
        ];
    }

    /**
     * @param  array{room_id: int, teacher_id: int}  $validated
     */
    private function lockScheduleResources(array $validated, int $schoolId, int $yearId): void
    {
        $room = DB::table('rooms')
            ->where('id', $validated['room_id'])
            ->where('school_id', $schoolId)
            ->where('year_id', $yearId)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first(['id']);
        if (! $room) {
            throw ValidationException::withMessages([
                'room_id' => 'The selected room is not active in this academic year.',
            ]);
        }

        $teacher = DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('users.id', $validated['teacher_id'])
            ->where('users.school_id', $schoolId)
            ->where('users.is_active', true)
            ->where('roles.role_key', 'teacher')
            ->lockForUpdate()
            ->first(['users.id']);
        if (! $teacher) {
            throw ValidationException::withMessages([
                'teacher_id' => 'The selected user is not an active teacher in this school.',
            ]);
        }
    }

    /**
     * @param  array{room_id: int, teacher_id: int, day_of_week: int, starts_at: string, ends_at: string, valid_from: string, valid_until: ?string}  $validated
     */
    private function ensureNoScheduleOverlap(
        array $validated,
        int $schoolId,
        int $yearId,
        ?int $scheduleId = null,
    ): void {
        $overlaps = DB::table('schedules')
            ->where('school_id', $schoolId)
            ->where('year_id', $yearId)
            ->where('day_of_week', $validated['day_of_week'])
            ->where(function ($resources) use ($validated) {
                $resources->where('room_id', $validated['room_id'])
                    ->orWhere('teacher_id', $validated['teacher_id']);
            })
            ->where('valid_from', '<=', $validated['valid_until'] ?? '9999-12-31')
            ->where(function ($dates) use ($validated) {
                $dates->whereNull('valid_until')
                    ->orWhere('valid_until', '>=', $validated['valid_from']);
            })
            ->where('starts_at', '<', $validated['ends_at'])
            ->where('ends_at', '>', $validated['starts_at']);

        if ($scheduleId !== null) {
            $overlaps->where('id', '!=', $scheduleId);
        }

        if ($overlaps->exists()) {
            throw ValidationException::withMessages([
                'schedule' => 'The room or teacher already has an overlapping schedule.',
            ]);
        }
    }

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

    private function adminContext(): array
    {
        [$user, $schoolId] = $this->staffContext();
        abort_unless($user->role === 'admin', 403);

        return [$user, $schoolId];
    }

    private function resolveYearId(Request $request, int $schoolId): int
    {
        $yearId = $request->input('year_id');
        $query = DB::table('academic_years')->where('school_id', $schoolId);

        if ($yearId !== null) {
            $query->where('id', $yearId);
        } else {
            $query->where('is_current', true)->where('is_active', true);
        }

        $year = $query->orderByDesc('start_date')->first(['id']);
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
                'year_id' => 'Schedules in a read-only academic year cannot be changed.',
            ]);
        }
    }
}
