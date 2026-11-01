<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AcademicYearSeeder extends Seeder
{
    public function run(): void
    {
        $years = [
            ['year_label' => '2024-2025', 'start_date' => '2024-09-01', 'end_date' => '2025-06-30', 'is_current' => false],
            ['year_label' => '2025-2026', 'start_date' => '2025-09-01', 'end_date' => '2026-06-30', 'is_current' => false],
            ['year_label' => '2026-2027', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'is_current' => true],
        ];

        $now = now();

        foreach ($years as $year) {
            $exists = DB::table('academic_years')->where('year_label', $year['year_label'])->first();

            if ($exists) {
                DB::table('academic_years')->where('year_label', $year['year_label'])->update([
                    'start_date' => $year['start_date'],
                    'end_date' => $year['end_date'],
                    'is_current' => $year['is_current'],
                    'is_active' => true,
                    'updated_at' => $now,
                ]);
            } else {
                DB::table('academic_years')->insert([
                    'year_label' => $year['year_label'],
                    'start_date' => $year['start_date'],
                    'end_date' => $year['end_date'],
                    'is_current' => $year['is_current'],
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }
}
