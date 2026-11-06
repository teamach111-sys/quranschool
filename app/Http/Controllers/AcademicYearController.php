<?php

namespace App\Http\Controllers;

use App\Support\AuditEventLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AcademicYearController extends Controller
{
    /**
     * Years available to the persistent year selector in the layout.
     */
    public function index()
    {
        $user = Auth::user();
        abort_unless($user, 403);

        $query = DB::table('academic_years');

        if (Schema::hasColumn('academic_years', 'school_id')) {
            $schoolId = data_get($user, 'school_id');
            abort_unless($schoolId, 403);
            $query->where('school_id', $schoolId);
        }

        if ($user->role !== 'admin') {
            $query->where('is_active', 1);
        }

        $rows = $query
            ->orderByDesc('start_date')
            ->get(['id', 'year_label', 'start_date', 'end_date', 'is_current', 'is_active']);

        $years = $rows->map(fn ($row) => [
            'id' => $row->id,
            'label' => $row->year_label,
            'start_date' => $row->start_date,
            'end_date' => $row->end_date,
            'is_current' => (bool) $row->is_current,
            'is_active' => (bool) $row->is_active,
            'is_read_only' => ! $row->is_active,
        ]);

        $current = $years->firstWhere('is_current', true);

        return response()->json([
            'years' => $years,
            'current_id' => $current['id'] ?? null,
        ]);
    }

    /** Academic-year management data for every year in the current school. */
    public function table()
    {
        $user = Auth::user();
        abort_unless($user, 403);

        $query = DB::table('academic_years');

        if (Schema::hasColumn('academic_years', 'school_id')) {
            $schoolId = data_get($user, 'school_id');
            abort_unless($schoolId, 403);
            $query->where('school_id', $schoolId);
        }

        $rows = $query
            ->orderByDesc('start_date')
            ->get(['id', 'year_label', 'start_date', 'end_date', 'is_current', 'is_active']);

        $years = $rows->map(fn ($row) => [
            'id' => $row->id,
            'label' => $row->year_label,
            'start_date' => $row->start_date,
            'end_date' => $row->end_date,
            'is_current' => (bool) $row->is_current,
            'is_active' => (bool) $row->is_active,
            'is_read_only' => ! $row->is_active,
        ]);

        $currentId = $years->firstWhere('is_current', true)['id'] ?? null;

        return response()->json([
            'years' => $years,
            'can_manage' => $user->role === 'admin',
            'current_id' => $currentId,
        ]);
    }

    public function changeStatus(Request $request, int $yearId)
    {
        $user = Auth::user();
        $schoolId = data_get($user, 'school_id');

        abort_unless(data_get($user, 'role') === 'admin' && $schoolId, 403);

        $validated = $request->validate([
            'action' => ['required', 'in:open,close,set_current'],
        ]);

        $query = DB::table('academic_years')->where('id', $yearId);

        if (Schema::hasColumn('academic_years', 'school_id')) {
            $query->where('school_id', $schoolId);
        }

        $year = $query->first(['id', 'is_current', 'is_active']);
        abort_unless($year, 404);

        if ($validated['action'] === 'close' && $year->is_current) {
            return response()->json([
                'message' => 'The current academic year cannot be closed.',
            ], 422);
        }

        DB::transaction(function () use ($schoolId, $yearId, $year, $validated) {
            if ($validated['action'] === 'set_current') {
                $years = DB::table('academic_years');

                if (Schema::hasColumn('academic_years', 'school_id')) {
                    $years->where('school_id', $schoolId);
                }

                $previousCurrentYears = $years
                    ->where('is_current', true)
                    ->where('id', '<>', $yearId)
                    ->lockForUpdate()
                    ->get(['id']);

                $years = DB::table('academic_years');

                if (Schema::hasColumn('academic_years', 'school_id')) {
                    $years->where('school_id', $schoolId);
                }

                $years->where('is_current', true)->update([
                    'is_current' => false,
                    'updated_at' => now(),
                ]);

                $target = DB::table('academic_years')->where('id', $yearId);

                if (Schema::hasColumn('academic_years', 'school_id')) {
                    $target->where('school_id', $schoolId);
                }

                $target->update([
                    'is_active' => true,
                    'is_current' => true,
                    'updated_at' => now(),
                ]);

                foreach ($previousCurrentYears as $previousYear) {
                    AuditEventLogger::record(
                        (int) $schoolId,
                        (int) $previousYear->id,
                        'academic_year',
                        (int) $previousYear->id,
                        'updated',
                        'academic_year.status_changed',
                        ['is_current' => true],
                        ['is_current' => false],
                    );
                }
            } else {
                $query = DB::table('academic_years')->where('id', $yearId);

                if (Schema::hasColumn('academic_years', 'school_id')) {
                    $query->where('school_id', $schoolId);
                }

                $isActive = $validated['action'] === 'open';
                $query->update([
                    'is_active' => $isActive,
                    'updated_at' => now(),
                ]);
            }

            AuditEventLogger::record(
                (int) $schoolId,
                $yearId,
                'academic_year',
                $yearId,
                'updated',
                'academic_year.status_changed',
                [
                    'is_active' => (bool) $year->is_active,
                    'is_current' => (bool) $year->is_current,
                ],
                [
                    'is_active' => $validated['action'] === 'close' ? false : true,
                    'is_current' => $validated['action'] === 'set_current' ? true : (bool) $year->is_current,
                ],
            );
        });

        return $this->table();
    }

    public function store(Request $request)
    {
        $schoolId = $this->adminSchoolId();
        $year = $this->validatedYear($request, $schoolId);

        $yearId = DB::transaction(function () use ($schoolId, $year) {
            $previousCurrentYears = collect();
            if ($year['is_current']) {
                $query = DB::table('academic_years')->where('is_current', true);
                if (Schema::hasColumn('academic_years', 'school_id')) {
                    $query->where('school_id', $schoolId);
                }
                $previousCurrentYears = $query->lockForUpdate()->get(['id']);
                $this->clearCurrentYear($schoolId);
            }

            $yearId = DB::table('academic_years')->insertGetId([
                ...$year,
                'school_id' => $schoolId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            AuditEventLogger::record(
                $schoolId,
                (int) $yearId,
                'academic_year',
                (int) $yearId,
                'created',
                'academic_year.created',
                [],
                $year,
            );
            foreach ($previousCurrentYears as $previousYear) {
                AuditEventLogger::record(
                    $schoolId,
                    (int) $previousYear->id,
                    'academic_year',
                    (int) $previousYear->id,
                    'updated',
                    'academic_year.status_changed',
                    ['is_current' => true],
                    ['is_current' => false],
                );
            }

            return (int) $yearId;
        });

        return $this->table();
    }

    public function update(Request $request, int $yearId)
    {
        $schoolId = $this->adminSchoolId();
        $yearQuery = DB::table('academic_years')->where('id', $yearId);

        if (Schema::hasColumn('academic_years', 'school_id')) {
            $yearQuery->where('school_id', $schoolId);
        }

        $existing = $yearQuery->first(['id', 'year_label', 'start_date', 'end_date', 'is_active', 'is_current']);
        abort_unless($existing, 404);

        if (! $existing->is_active) {
            throw ValidationException::withMessages([
                'year_label' => 'Closed academic years cannot be edited.',
            ]);
        }

        $year = $this->validatedYear($request, $schoolId, $yearId);

        if ($existing->is_current && ! $year['is_current']) {
            throw ValidationException::withMessages([
                'is_current' => 'Set another year as current before changing this year.',
            ]);
        }

        DB::transaction(function () use ($schoolId, $yearId, $year, $existing) {
            $previousCurrentYears = collect();
            if ($year['is_current']) {
                $query = DB::table('academic_years')
                    ->where('is_current', true)
                    ->where('id', '<>', $yearId);
                if (Schema::hasColumn('academic_years', 'school_id')) {
                    $query->where('school_id', $schoolId);
                }
                $previousCurrentYears = $query->lockForUpdate()->get(['id']);
                $this->clearCurrentYear($schoolId, $yearId);
            }

            $query = DB::table('academic_years')->where('id', $yearId);

            if (Schema::hasColumn('academic_years', 'school_id')) {
                $query->where('school_id', $schoolId);
            }

            $query->update([...$year, 'updated_at' => now()]);
            AuditEventLogger::record(
                $schoolId,
                $yearId,
                'academic_year',
                $yearId,
                'updated',
                'academic_year.updated',
                [
                    'year_label' => $existing->year_label,
                    'start_date' => $existing->start_date,
                    'end_date' => $existing->end_date,
                    'is_active' => (bool) $existing->is_active,
                    'is_current' => (bool) $existing->is_current,
                ],
                $year,
            );
            foreach ($previousCurrentYears as $previousYear) {
                AuditEventLogger::record(
                    $schoolId,
                    (int) $previousYear->id,
                    'academic_year',
                    (int) $previousYear->id,
                    'updated',
                    'academic_year.status_changed',
                    ['is_current' => true],
                    ['is_current' => false],
                );
            }
        });

        return $this->table();
    }

    private function adminSchoolId(): int
    {
        $user = Auth::user();
        $schoolId = data_get($user, 'school_id');

        abort_unless(data_get($user, 'role') === 'admin' && $schoolId, 403);

        return (int) $schoolId;
    }

    private function validatedYear(Request $request, int $schoolId, ?int $yearId = null): array
    {
        $labelRule = Rule::unique('academic_years', 'year_label')
            ->where(fn ($query) => $query->where('school_id', $schoolId));

        if ($yearId !== null) {
            $labelRule->ignore($yearId);
        }

        $year = Validator::make($request->all(), [
            'year_label' => ['required', 'string', 'max:20', $labelRule],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'is_active' => ['required', 'boolean'],
            'is_current' => ['required', 'boolean'],
        ])->validate();

        $year['is_active'] = (bool) $year['is_active'] || (bool) $year['is_current'];
        $year['is_current'] = (bool) $year['is_current'];

        return $year;
    }

    private function clearCurrentYear(int $schoolId, ?int $exceptYearId = null): void
    {
        $query = DB::table('academic_years')->where('is_current', true);

        if (Schema::hasColumn('academic_years', 'school_id')) {
            $query->where('school_id', $schoolId);
        }

        if ($exceptYearId !== null) {
            $query->where('id', '<>', $exceptYearId);
        }

        $query->update([
            'is_current' => false,
            'updated_at' => now(),
        ]);
    }
}
