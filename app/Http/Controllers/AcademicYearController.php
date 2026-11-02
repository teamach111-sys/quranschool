<?php

namespace App\Http\Controllers;

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
            $query->where('is_active', 1)->where('is_current', 1);
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
            'is_read_only' => $user->role === 'admin' && (! $row->is_active || ! $row->is_current),
        ]);

        $current = $years->firstWhere('is_current', true);

        return response()->json([
            'years' => $years,
            'current_id' => $current['id'] ?? null,
        ]);
    }

    /** Academic-year page data; unlike the selector, this includes history for read-only roles. */
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

        return response()->json([
            'years' => $rows->map(fn ($row) => [
                'id' => $row->id,
                'label' => $row->year_label,
                'start_date' => $row->start_date,
                'end_date' => $row->end_date,
                'is_current' => (bool) $row->is_current,
                'is_active' => (bool) $row->is_active,
                'is_read_only' => $user->role !== 'admin' || ! $row->is_active || ! $row->is_current,
            ]),
            'can_manage' => $user->role === 'admin',
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

        $year = $query->first(['id', 'is_current']);
        abort_unless($year, 404);

        if ($validated['action'] === 'close' && $year->is_current) {
            return response()->json([
                'message' => 'The current academic year cannot be closed.',
            ], 422);
        }

        if ($validated['action'] === 'set_current') {
            DB::transaction(function () use ($schoolId, $yearId) {
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
            });
        } else {
            $query->update([
                'is_active' => $validated['action'] === 'open',
                'updated_at' => now(),
            ]);
        }

        return $this->table();
    }

    public function store(Request $request)
    {
        $schoolId = $this->adminSchoolId();
        $year = $this->validatedYear($request, $schoolId);

        DB::transaction(function () use ($schoolId, $year) {
            if ($year['is_current']) {
                $this->clearCurrentYear($schoolId);
            }

            DB::table('academic_years')->insert([
                ...$year,
                'school_id' => $schoolId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
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

        $existing = $yearQuery->first(['id', 'is_active', 'is_current']);
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

        DB::transaction(function () use ($schoolId, $yearId, $year) {
            if ($year['is_current']) {
                $this->clearCurrentYear($schoolId, $yearId);
            }

            $query = DB::table('academic_years')->where('id', $yearId);

            if (Schema::hasColumn('academic_years', 'school_id')) {
                $query->where('school_id', $schoolId);
            }

            $query->update([...$year, 'updated_at' => now()]);
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
