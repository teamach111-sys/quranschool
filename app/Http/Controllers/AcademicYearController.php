<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class AcademicYearController extends Controller
{
    /**
     * Years available to the persistent year selector in the layout.
     */
    public function index()
    {
        $rows = DB::table('academic_years')
            ->where('is_active', 1)
            ->orderByDesc('start_date')
            ->get(['id', 'year_label', 'start_date', 'end_date', 'is_current']);

        $years = $rows->map(fn ($row) => [
            'id' => $row->id,
            'label' => $row->year_label,
            'start_date' => $row->start_date,
            'end_date' => $row->end_date,
            'is_current' => (bool) $row->is_current,
        ]);

        $current = $years->firstWhere('is_current', true);

        return response()->json([
            'years' => $years,
            'current_id' => $current['id'] ?? null,
        ]);
    }
}
