<?php

namespace App\Http\Controllers;

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
            'is_read_only' => $user->role === 'admin' && ! $row->is_current,
        ]);

        $current = $years->firstWhere('is_current', true);

        return response()->json([
            'years' => $years,
            'current_id' => $current['id'] ?? null,
        ]);
    }
}
