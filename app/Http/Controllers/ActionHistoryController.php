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
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ActionHistoryController extends Controller
{
    public function table(Request $request): JsonResponse
    {
        $schoolId = $this->adminSchoolId();
        $filters = $this->validatedFilters($request);

        $events = $this->eventsQuery($schoolId, $filters)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get([
                'id',
                'year_id',
                'actor_user_id',
                'actor_name',
                'action_type',
                'entity_type',
                'entity_id',
                'summary_key',
                'reason',
                'created_at',
            ])
            ->map(fn ($event) => [
                'id' => (int) $event->id,
                'year_id' => $event->year_id === null ? null : (int) $event->year_id,
                'actor_user_id' => (int) $event->actor_user_id,
                'actor_name' => $event->actor_name,
                'action_type' => $event->action_type,
                'entity_type' => $event->entity_type,
                'entity_id' => $event->entity_id === null ? null : (int) $event->entity_id,
                'summary_key' => $event->summary_key,
                'reason' => $event->reason,
                'created_at' => $event->created_at,
            ])
            ->values();

        $actors = DB::table('audit_events')
            ->where('school_id', $schoolId)
            ->select('actor_user_id')
            ->selectRaw('MAX(actor_name) as actor_name')
            ->groupBy('actor_user_id')
            ->orderBy('actor_name')
            ->get()
            ->map(fn ($actor) => [
                'id' => (int) $actor->actor_user_id,
                'name' => $actor->actor_name,
            ])
            ->values();

        return response()->json([
            'events' => $events,
            'count' => $events->count(),
            'actors' => $actors,
            'actions' => DB::table('audit_events')
                ->where('school_id', $schoolId)
                ->distinct()
                ->orderBy('action_type')
                ->pluck('action_type')
                ->values(),
            'entities' => DB::table('audit_events')
                ->where('school_id', $schoolId)
                ->distinct()
                ->orderBy('entity_type')
                ->pluck('entity_type')
                ->values(),
            'years' => DB::table('academic_years')
                ->where('school_id', $schoolId)
                ->orderByDesc('start_date')
                ->orderByDesc('id')
                ->get(['id', 'year_label'])
                ->map(fn ($year) => [
                    'id' => (int) $year->id,
                    'label' => $year->year_label,
                ])
                ->values(),
        ]);
    }

    public function show(int $eventId): JsonResponse
    {
        $schoolId = $this->adminSchoolId();
        $event = DB::table('audit_events')
            ->where('school_id', $schoolId)
            ->where('id', $eventId)
            ->first([
                'id',
                'year_id',
                'actor_user_id',
                'actor_name',
                'action_type',
                'entity_type',
                'entity_id',
                'summary_key',
                'changes',
                'reason',
                'created_at',
            ]);

        abort_unless($event, 404);

        return response()->json([
            'event' => [
                'id' => (int) $event->id,
                'year_id' => $event->year_id === null ? null : (int) $event->year_id,
                'actor_user_id' => (int) $event->actor_user_id,
                'actor_name' => $event->actor_name,
                'action_type' => $event->action_type,
                'entity_type' => $event->entity_type,
                'entity_id' => $event->entity_id === null ? null : (int) $event->entity_id,
                'summary_key' => $event->summary_key,
                'changes' => $event->changes === null
                    ? null
                    : json_decode($event->changes, true, 512, JSON_THROW_ON_ERROR),
                'reason' => $event->reason,
                'created_at' => $event->created_at,
            ],
        ]);
    }

    public function export(Request $request): Response|JsonResponse
    {
        $schoolId = $this->adminSchoolId();
        $filters = $this->validatedFilters($request);
        $validated = $request->validate([
            'format' => ['required', Rule::in(['csv', 'pdf', 'xlsx'])],
            'lang' => ['nullable', Rule::in(['fr', 'ar'])],
        ]);
        $language = $validated['lang'] ?? 'fr';
        $events = $this->eventsQuery($schoolId, $filters)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get([
                'year_id',
                'actor_name',
                'action_type',
                'entity_type',
                'entity_id',
                'summary_key',
                'reason',
                'created_at',
            ]);

        if ($events->isEmpty()) {
            return response()->json(['message' => 'No action history matches the selected filters.'], 422);
        }

        $schoolName = DB::table('schools')
            ->where('id', $schoolId)
            ->value($language === 'ar' ? 'name_ar' : 'name_fr');
        abort_unless(is_string($schoolName) && $schoolName !== '', 500);

        $labels = $this->exportLabels($language);
        $generatedAt = now()->format('Y-m-d H:i:s');
        $format = $validated['format'];
        $localizedFilters = $this->localizedFilterSummary($filters, $language, $schoolId);
        $contents = match ($format) {
            'csv' => $this->csvContents(
                $events,
                $labels,
                $language,
                $schoolName,
                $localizedFilters,
                $generatedAt,
            ),
            'xlsx' => $this->xlsxContents(
                $events,
                $labels,
                $language,
                $schoolName,
                $localizedFilters,
                $generatedAt,
            ),
            default => $this->pdfContents(
                $events,
                $labels,
                $language,
                $schoolName,
                $localizedFilters,
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
            'year_id' => isset($filters['year_id']) ? (int) $filters['year_id'] : null,
            'actor_user_id' => (int) $actor->id,
            'actor_name' => $actor->full_name,
            'action_type' => 'exported',
            'entity_type' => 'audit_event',
            'entity_id' => null,
            'summary_key' => 'action_history.exported',
            'changes' => json_encode([
                'format' => $format,
                'page' => 'action_history',
                'record_count' => $events->count(),
                'filters' => $this->safeFilterSummary($filters),
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        $mimeType = match ($format) {
            'csv' => 'text/csv; charset=UTF-8',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            default => 'application/pdf',
        };
        $filename = "action-history.{$format}";

        return response($contents, 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Export-Record-Count' => (string) $events->count(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedFilters(Request $request): array
    {
        $schoolId = $this->adminSchoolId();
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'actor_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where('school_id', $schoolId),
            ],
            'action_type' => ['nullable', 'string', 'max:48'],
            'entity_type' => ['nullable', 'string', 'max:64'],
            'year_id' => [
                'nullable',
                'integer',
                Rule::exists('academic_years', 'id')->where('school_id', $schoolId),
            ],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        if (
            isset($filters['date_from'], $filters['date_to'])
            && $filters['date_to'] < $filters['date_from']
        ) {
            throw ValidationException::withMessages([
                'date_to' => 'The end date must be on or after the start date.',
            ]);
        }

        return $filters;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function eventsQuery(int $schoolId, array $filters): Builder
    {
        return DB::table('audit_events')
            ->where('school_id', $schoolId)
            ->when($filters['actor_id'] ?? null, fn ($query, $actorId) => $query->where('actor_user_id', $actorId))
            ->when($filters['action_type'] ?? null, fn ($query, $action) => $query->where('action_type', $action))
            ->when($filters['entity_type'] ?? null, fn ($query, $entity) => $query->where('entity_type', $entity))
            ->when($filters['year_id'] ?? null, fn ($query, $yearId) => $query->where('year_id', $yearId))
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '<=', $date))
            ->when(trim($filters['search'] ?? '') !== '', function ($query) use ($filters) {
                $search = '%'.trim($filters['search']).'%';
                $query->where(function ($query) use ($search) {
                    $query->where('actor_name', 'like', $search)
                        ->orWhere('action_type', 'like', $search)
                        ->orWhere('entity_type', 'like', $search)
                        ->orWhere('summary_key', 'like', $search)
                        ->orWhere('reason', 'like', $search)
                        ->orWhere('entity_id', 'like', $search);
                });
            });
    }

    /**
     * @param  Collection<int, object>  $events
     * @param  array<string, string>  $labels
     * @param  array<int, string>  $filters
     */
    private function csvContents(
        $events,
        array $labels,
        string $language,
        string $schoolName,
        array $filters,
        string $generatedAt,
    ): string {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Unable to create the action history CSV export.');
        }

        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, [$labels['title']]);
        fputcsv($stream, [$labels['school'], $this->spreadsheetSafe($schoolName)]);
        fputcsv($stream, [$labels['generatedAt'], $generatedAt]);
        fputcsv($stream, [$labels['recordCount'], $events->count()]);
        fputcsv($stream, [$labels['filters'], $this->spreadsheetSafe(implode(' | ', $filters))]);
        fputcsv($stream, []);
        fputcsv($stream, [
            $labels['summary'],
            $labels['action'],
            $labels['entity'],
            $labels['record'],
            $labels['actor'],
            $labels['date'],
            $labels['reason'],
        ]);

        foreach ($events as $event) {
            fputcsv($stream, [
                $this->spreadsheetSafe($this->localizedSummary($event->summary_key, $language)),
                $this->spreadsheetSafe($this->localizedAction($event->action_type, $language)),
                $this->spreadsheetSafe($this->localizedEntity($event->entity_type, $language)),
                $event->entity_id === null ? '' : (string) $event->entity_id,
                $this->spreadsheetSafe($event->actor_name),
                $event->created_at,
                $this->spreadsheetSafe($event->reason ?? ''),
            ]);
        }

        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);
        if ($contents === false) {
            throw new \RuntimeException('Unable to read the action history CSV export.');
        }

        return $contents;
    }

    /**
     * @param  Collection<int, object>  $events
     * @param  array<string, string>  $labels
     * @param  array<int, string>  $filters
     */
    private function xlsxContents(
        $events,
        array $labels,
        string $language,
        string $schoolName,
        array $filters,
        string $generatedAt,
    ): string {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($language === 'ar' ? 'سجل الإجراءات' : 'Historique');
        $sheet->setRightToLeft($language === 'ar');

        $metadata = [
            [$labels['title']],
            [$labels['school'], $schoolName],
            [$labels['generatedAt'], $generatedAt],
            [$labels['recordCount'], (string) $events->count()],
            [$labels['filters'], implode(' | ', $filters)],
        ];
        foreach ($metadata as $rowIndex => $row) {
            foreach ($row as $columnIndex => $value) {
                $sheet->getCell([$columnIndex + 1, $rowIndex + 1])
                    ->setValueExplicit(
                        $columnIndex === 0 && $rowIndex === 3 ? (int) $value : (string) $value,
                        $columnIndex === 0 && $rowIndex === 3 ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING,
                    );
            }
        }
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);

        $headerRow = count($metadata) + 2;
        $headings = [
            $labels['summary'],
            $labels['action'],
            $labels['entity'],
            $labels['record'],
            $labels['actor'],
            $labels['date'],
            $labels['reason'],
        ];
        foreach ($headings as $index => $heading) {
            $sheet->getCell([$index + 1, $headerRow])
                ->setValueExplicit($heading, DataType::TYPE_STRING);
            $sheet->getColumnDimensionByColumn($index + 1)->setAutoSize(true);
        }
        $lastColumn = Coordinate::stringFromColumnIndex(count($headings));
        $lastRow = $headerRow + $events->count();
        $sheet->getStyle("A{$headerRow}:{$lastColumn}{$headerRow}")->getFont()->setBold(true);
        $sheet->freezePane('A'.($headerRow + 1));
        $sheet->setAutoFilter("A{$headerRow}:{$lastColumn}{$lastRow}");

        foreach ($events as $rowIndex => $event) {
            $row = [
                $this->localizedSummary($event->summary_key, $language),
                $this->localizedAction($event->action_type, $language),
                $this->localizedEntity($event->entity_type, $language),
                $event->entity_id === null ? '' : (string) $event->entity_id,
                $event->actor_name,
                $event->created_at,
                $event->reason ?? '',
            ];
            foreach ($row as $columnIndex => $value) {
                $sheet->getCell([$columnIndex + 1, $headerRow + $rowIndex + 1])
                    ->setValueExplicit($this->spreadsheetSafe((string) $value), DataType::TYPE_STRING);
            }
        }

        $temporaryFile = tempnam(sys_get_temp_dir(), 'quranschool-action-history-');
        if ($temporaryFile === false) {
            throw new \RuntimeException('Unable to create the action history Excel export.');
        }

        try {
            (new Xlsx($spreadsheet))->save($temporaryFile);
            $contents = file_get_contents($temporaryFile);
            if ($contents === false || ! str_starts_with($contents, 'PK')) {
                throw new \RuntimeException('Unable to read the action history Excel export.');
            }
        } finally {
            if (is_file($temporaryFile) && ! unlink($temporaryFile)) {
                throw new \RuntimeException('Unable to clean up the temporary action history Excel export.');
            }
            $spreadsheet->disconnectWorksheets();
        }

        return $contents;
    }

    /**
     * @param  Collection<int, object>  $events
     * @param  array<string, string>  $labels
     * @param  array<int, string>  $filters
     */
    private function pdfContents($events, array $labels, string $language, string $schoolName, array $filters, string $generatedAt): string
    {
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
        $pdf->WriteHTML(view('exports.action-history', [
            'language' => $language,
            'labels' => $labels,
            'schoolName' => $schoolName,
            'events' => $events,
            'filters' => $filters,
            'generatedAt' => $generatedAt,
            'localizedAction' => fn (string $action) => $this->localizedAction($action, $language),
            'localizedEntity' => fn (string $entity) => $this->localizedEntity($entity, $language),
            'localizedSummary' => fn (string $summary) => $this->localizedSummary($summary, $language),
        ])->render());
        $contents = $pdf->Output('', Destination::STRING_RETURN);
        if (! str_starts_with($contents, '%PDF-')) {
            throw new \RuntimeException('The action history PDF export is invalid.');
        }

        return $contents;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function safeFilterSummary(array $filters): array
    {
        return [
            'search_applied' => trim($filters['search'] ?? '') !== '',
            'actor_id' => $filters['actor_id'] ?? null,
            'action_type' => $filters['action_type'] ?? null,
            'entity_type' => $filters['entity_type'] ?? null,
            'year_id' => $filters['year_id'] ?? null,
            'date_from' => $filters['date_from'] ?? null,
            'date_to' => $filters['date_to'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, string>
     */
    private function localizedFilterSummary(array $filters, string $language, int $schoolId): array
    {
        $summary = [];
        if (trim($filters['search'] ?? '') !== '') {
            $summary[] = $language === 'ar' ? 'بحث مطبق' : 'Recherche appliquée';
        }
        if (isset($filters['actor_id'])) {
            $actorName = DB::table('audit_events')
                ->where('school_id', $schoolId)
                ->where('actor_user_id', $filters['actor_id'])
                ->orderByDesc('id')
                ->value('actor_name');
            $summary[] = ($language === 'ar' ? 'المنفذ' : 'Acteur').': '.($actorName ?: '');
        }
        if (isset($filters['action_type'])) {
            $summary[] = ($language === 'ar' ? 'الإجراء' : 'Action').': '
                .$this->localizedAction($filters['action_type'], $language);
        }
        if (isset($filters['entity_type'])) {
            $summary[] = ($language === 'ar' ? 'العنصر' : 'Élément').': '
                .$this->localizedEntity($filters['entity_type'], $language);
        }
        if (isset($filters['year_id'])) {
            $yearLabel = DB::table('academic_years')
                ->where('school_id', $schoolId)
                ->where('id', $filters['year_id'])
                ->value('year_label');
            $summary[] = ($language === 'ar' ? 'السنة' : 'Année').': '.($yearLabel ?: '');
        }
        foreach ([
            'date_from' => $language === 'ar' ? 'من' : 'Du',
            'date_to' => $language === 'ar' ? 'إلى' : 'Au',
        ] as $key => $label) {
            if (isset($filters[$key])) {
                $summary[] = "{$label}: {$filters[$key]}";
            }
        }

        return $summary ?: [$language === 'ar' ? 'كل الإجراءات' : 'Toutes les actions'];
    }

    private function spreadsheetSafe(string $value): string
    {
        return preg_match('/^[\p{Z}\s]*[=+\-@]/u', $value) === 1
            ? "'".$value
            : $value;
    }

    /**
     * @return array<string, string>
     */
    private function exportLabels(string $language): array
    {
        return $language === 'ar'
            ? [
                'title' => 'سجل الإجراءات',
                'school' => 'المؤسسة',
                'generatedAt' => 'تاريخ الإنشاء',
                'recordCount' => 'عدد الإجراءات',
                'filters' => 'عوامل التصفية',
                'summary' => 'الإجراء المنفذ',
                'action' => 'النوع',
                'entity' => 'العنصر',
                'record' => 'معرّف السجل',
                'actor' => 'المنفذ',
                'date' => 'التاريخ والوقت',
                'reason' => 'السبب',
            ]
            : [
                'title' => 'Historique des actions',
                'school' => 'Établissement',
                'generatedAt' => 'Généré le',
                'recordCount' => 'Nombre d’actions',
                'filters' => 'Filtres appliqués',
                'summary' => 'Action effectuée',
                'action' => 'Type',
                'entity' => 'Élément',
                'record' => 'ID de l’enregistrement',
                'actor' => 'Acteur',
                'date' => 'Date et heure',
                'reason' => 'Motif',
            ];
    }

    private function localizedAction(string $action, string $language): string
    {
        $labels = [
            'created' => ['fr' => 'Création', 'ar' => 'إنشاء'],
            'updated' => ['fr' => 'Modification', 'ar' => 'تعديل'],
            'archived' => ['fr' => 'Archivage', 'ar' => 'أرشفة'],
            'exported' => ['fr' => 'Export', 'ar' => 'تصدير'],
        ];

        return $labels[$action][$language] ?? $action;
    }

    private function localizedEntity(string $entity, string $language): string
    {
        $labels = [
            'user' => ['fr' => 'Utilisateur', 'ar' => 'مستخدم'],
            'payment' => ['fr' => 'Paiement', 'ar' => 'دفعة'],
            'audit_event' => ['fr' => 'Événement d’audit', 'ar' => 'حدث تدقيق'],
        ];

        return $labels[$entity][$language] ?? $entity;
    }

    private function localizedSummary(string $summary, string $language): string
    {
        $labels = [
            'user.role_changed' => ['fr' => 'Rôle utilisateur modifié', 'ar' => 'تغيير دور المستخدم'],
            'user.status_changed' => ['fr' => 'Statut du compte modifié', 'ar' => 'تغيير حالة الحساب'],
            'payment.table_exported' => ['fr' => 'Liste des paiements exportée', 'ar' => 'تصدير قائمة المدفوعات'],
            'payment.receipt_exported' => ['fr' => 'Reçu de paiement exporté', 'ar' => 'تصدير إيصال الدفع'],
            'action_history.exported' => ['fr' => 'Historique des actions exporté', 'ar' => 'تصدير سجل الإجراءات'],
        ];

        return $labels[$summary][$language] ?? $summary;
    }

    private function adminSchoolId(): int
    {
        $user = Auth::user();
        $schoolId = data_get($user, 'school_id');

        abort_unless(data_get($user, 'role') === 'admin' && $schoolId, 403);

        return (int) $schoolId;
    }
}
