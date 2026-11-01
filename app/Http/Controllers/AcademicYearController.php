<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

        $years = $rows->map(fn($row) => [
            'id' => $row->id,
            'label' => $row->year_label,
            'start_date' => $row->start_date,
            'end_date' => $row->end_date,
            'is_current' => (bool) $row->is_current,
            'is_active' => (bool) $row->is_active,
            'is_read_only' => $user->role === 'admin' && ! $row->is_active,
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
            'years' => $rows->map(fn($row) => [
                'id' => $row->id,
                'label' => $row->year_label,
                'start_date' => $row->start_date,
                'end_date' => $row->end_date,
                'is_current' => (bool) $row->is_current,
                'is_active' => (bool) $row->is_active,
                'is_read_only' => $user->role !== 'admin' || ! $row->is_active,
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
}
