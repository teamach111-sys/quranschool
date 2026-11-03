<?php

namespace Tests\Feature;

use App\Console\Commands\RolloverLatePayments;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RolloverLatePaymentsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->string('name_fr');
            $table->string('name_ar');
        });

        Schema::create('academic_years', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->boolean('is_current');
            $table->boolean('is_active');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('year_id');
            $table->date('period_end');
            $table->string('status');
            $table->timestamps();
        });
    }

    public function test_command_marks_only_due_payments_past_the_grace_period_in_current_active_years(): void
    {
        $this->seedRolloverData();
        $this->travelTo('2026-11-03 00:05:00');

        $this->artisan('payments:rollover-late')
            ->expectsOutputToContain('École Une / مدرسة واحدة: 1 paiement a été automatiquement passé en retard / تم تحويل عدد 1 من الدفعات إلى حالة التأخر')
            ->expectsOutputToContain('École Deux / مدرسة اثنتان: 2 paiements ont été automatiquement passés en retard / تم تحويل عدد 2 من الدفعات إلى حالة التأخر')
            ->assertExitCode(0);

        $this->assertDatabaseHas('payments', ['id' => 1, 'status' => 'late']);
        $this->assertDatabaseHas('payments', ['id' => 2, 'status' => 'due']);
        $this->assertDatabaseHas('payments', ['id' => 3, 'status' => 'paid']);
        $this->assertDatabaseHas('payments', ['id' => 4, 'status' => 'waived']);
        $this->assertDatabaseHas('payments', ['id' => 5, 'status' => 'due']);
        $this->assertDatabaseHas('payments', ['id' => 6, 'status' => 'late']);
        $this->assertDatabaseHas('payments', ['id' => 7, 'status' => 'late']);
        $this->assertSame(
            [
                'count' => 1,
                'ran_at' => '2026-11-03T00:05:00+00:00',
            ],
            Cache::get(RolloverLatePayments::reportCacheKey(1, 1)),
        );
    }

    public function test_rerunning_the_command_changes_no_additional_rows_and_reports_zero(): void
    {
        $this->seedRolloverData();
        $this->travelTo('2026-11-03 00:05:00');
        $this->artisan('payments:rollover-late')->assertExitCode(0);

        $this->artisan('payments:rollover-late')
            ->expectsOutputToContain('École Une / مدرسة واحدة: 0 paiements ont été automatiquement passés en retard / تم تحويل عدد 0 من الدفعات إلى حالة التأخر')
            ->expectsOutputToContain('École Deux / مدرسة اثنتان: 0 paiements ont été automatiquement passés en retard / تم تحويل عدد 0 من الدفعات إلى حالة التأخر')
            ->assertExitCode(0);

        $this->assertDatabaseHas('payments', ['id' => 1, 'status' => 'late']);
        $this->assertSame(
            [
                'count' => 0,
                'ran_at' => '2026-11-03T00:05:00+00:00',
            ],
            Cache::get(RolloverLatePayments::reportCacheKey(1, 1)),
        );
        $this->assertSame(
            [
                'count' => 0,
                'ran_at' => '2026-11-03T00:05:00+00:00',
            ],
            Cache::get(RolloverLatePayments::reportCacheKey(2, 2)),
        );
    }

    private function seedRolloverData(): void
    {
        DB::table('schools')->insert([
            ['id' => 1, 'name_fr' => 'École Une', 'name_ar' => 'مدرسة واحدة'],
            ['id' => 2, 'name_fr' => 'École Deux', 'name_ar' => 'مدرسة اثنتان'],
        ]);
        DB::table('academic_years')->insert([
            ['id' => 1, 'school_id' => 1, 'is_current' => true, 'is_active' => true],
            ['id' => 2, 'school_id' => 2, 'is_current' => true, 'is_active' => true],
            ['id' => 3, 'school_id' => 1, 'is_current' => false, 'is_active' => false],
        ]);
        DB::table('payments')->insert([
            ['id' => 1, 'student_id' => 1, 'school_id' => 1, 'year_id' => 1, 'period_end' => '2026-10-26', 'status' => 'due'],
            ['id' => 2, 'student_id' => 1, 'school_id' => 1, 'year_id' => 1, 'period_end' => '2026-10-27', 'status' => 'due'],
            ['id' => 3, 'student_id' => 1, 'school_id' => 1, 'year_id' => 1, 'period_end' => '2026-10-01', 'status' => 'paid'],
            ['id' => 4, 'student_id' => 1, 'school_id' => 1, 'year_id' => 1, 'period_end' => '2026-10-01', 'status' => 'waived'],
            ['id' => 5, 'student_id' => 1, 'school_id' => 1, 'year_id' => 3, 'period_end' => '2026-09-01', 'status' => 'due'],
            ['id' => 6, 'student_id' => 1, 'school_id' => 2, 'year_id' => 2, 'period_end' => '2026-09-01', 'status' => 'due'],
            ['id' => 7, 'student_id' => 2, 'school_id' => 2, 'year_id' => 2, 'period_end' => '2026-10-26', 'status' => 'due'],
        ]);
    }
}
