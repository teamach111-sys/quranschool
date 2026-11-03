<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

#[Signature('payments:rollover-late')]
#[Description('Mark due payments late after the grace period')]
class RolloverLatePayments extends Command
{
    public const LATE_GRACE_DAYS = 7;

    public static function reportCacheKey(int $schoolId, int $yearId): string
    {
        return "payments:late-rollover:{$schoolId}:{$yearId}";
    }

    public function handle(): int
    {
        $lateCutoff = now()->subDays(self::LATE_GRACE_DAYS)->toDateString();
        $currentYears = DB::table('academic_years')
            ->join('schools', 'schools.id', '=', 'academic_years.school_id')
            ->where('academic_years.is_current', true)
            ->where('academic_years.is_active', true)
            ->orderBy('academic_years.school_id')
            ->get([
                'academic_years.id as year_id',
                'academic_years.school_id',
                'schools.name_fr',
                'schools.name_ar',
            ]);

        foreach ($currentYears as $currentYear) {
            $schoolId = (int) $currentYear->school_id;
            $yearId = (int) $currentYear->year_id;
            $result = DB::transaction(function () use ($schoolId, $yearId, $lateCutoff) {
                $year = DB::table('academic_years')
                    ->where('id', $yearId)
                    ->where('school_id', $schoolId)
                    ->where('is_current', true)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->first(['id']);

                if (! $year) {
                    return null;
                }

                $count = DB::table('payments')
                    ->where('school_id', $schoolId)
                    ->where('year_id', $yearId)
                    ->where('status', 'due')
                    ->where('period_end', '<', $lateCutoff)
                    ->update([
                        'status' => 'late',
                        'updated_at' => now(),
                    ]);

                return [
                    'count' => $count,
                    'ran_at' => now()->toIso8601String(),
                ];
            });

            if ($result === null) {
                continue;
            }

            Cache::forever(self::reportCacheKey($schoolId, $yearId), $result);
            $count = $result['count'];
            $paymentWord = $count === 1 ? 'paiement' : 'paiements';
            $frenchVerb = $count === 1
                ? 'a été automatiquement passé'
                : 'ont été automatiquement passés';

            $this->line(
                "{$currentYear->name_fr} / {$currentYear->name_ar}: "
                ."{$count} {$paymentWord} {$frenchVerb} en retard / تم تحويل عدد {$count} من الدفعات إلى حالة التأخر"
            );
        }

        return self::SUCCESS;
    }
}
