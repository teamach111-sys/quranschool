<?php

namespace Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class SchedulesTableTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('academic_years', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->string('year_label');
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_current');
            $table->boolean('is_active');
        });

        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->string('name_fr');
            $table->string('name_ar');
        });

        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('year_id')->nullable();
            $table->unsignedBigInteger('actor_user_id');
            $table->string('actor_name');
            $table->string('action_type');
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('summary_key');
            $table->text('changes');
            $table->string('reason')->nullable();
            $table->string('request_id', 100)->nullable();
            $table->dateTime('created_at');
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('role_key');
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedInteger('role_id');
            $table->string('full_name');
            $table->boolean('is_active');
        });

        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('year_id');
            $table->string('name');
            $table->boolean('is_active');
        });

        Schema::create('schedules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('room_id');
            $table->unsignedBigInteger('teacher_id');
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('year_id');
            $table->unsignedTinyInteger('day_of_week');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->string('label', 100)->default('Hifz');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function test_admin_lists_school_schedules_and_receives_active_form_options(): void
    {
        $this->seedScheduleData();
        DB::table('schedules')->insert([
            'id' => 1,
            'room_id' => 1,
            'teacher_id' => 20,
            'school_id' => 1,
            'year_id' => 1,
            'day_of_week' => 1,
            'starts_at' => '09:00:00',
            'ends_at' => '10:00:00',
            'valid_from' => '2026-09-01',
            'valid_until' => null,
            'label' => 'Morning Hifz',
        ]);
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $this->getJson('/schedules/table?year_id=1')
            ->assertOk()
            ->assertJsonPath('can_manage', true)
            ->assertJsonCount(1, 'schedules')
            ->assertJsonPath('schedules.0.room_name', 'Alpha')
            ->assertJsonPath('schedules.0.teacher_name', 'Teacher One')
            ->assertJsonPath('schedules.0.starts_at', '09:00')
            ->assertJsonPath('schedules.0.label', 'Morning Hifz')
            ->assertJsonCount(2, 'rooms')
            ->assertJsonCount(2, 'teachers');
    }

    public function test_schedule_table_requires_authentication(): void
    {
        $this->getJson('/schedules/table')->assertUnauthorized();
    }

    public function test_teacher_sees_only_their_own_school_year_schedules(): void
    {
        $this->seedScheduleData();
        DB::table('schedules')->insert([
            [
                'id' => 1,
                'room_id' => 1,
                'teacher_id' => 20,
                'school_id' => 1,
                'year_id' => 1,
                'day_of_week' => 1,
                'starts_at' => '09:00:00',
                'ends_at' => '10:00:00',
                'valid_from' => '2026-09-01',
                'label' => 'Own slot',
            ],
            [
                'id' => 2,
                'room_id' => 2,
                'teacher_id' => 21,
                'school_id' => 1,
                'year_id' => 1,
                'day_of_week' => 2,
                'starts_at' => '11:00:00',
                'ends_at' => '12:00:00',
                'valid_from' => '2026-09-01',
                'label' => 'Other teacher slot',
            ],
        ]);
        $this->actingAs(new GenericUser([
            'id' => 20,
            'school_id' => 1,
            'role' => 'teacher',
        ]));

        $this->getJson('/schedules/table?year_id=1')
            ->assertOk()
            ->assertJsonPath('can_manage', false)
            ->assertJsonCount(1, 'schedules')
            ->assertJsonPath('schedules.0.teacher_id', 20)
            ->assertJsonMissingPath('rooms')
            ->assertJsonMissingPath('teachers');
    }

    public function test_admin_exports_school_schedule_as_csv_and_pdf_with_audit_events(): void
    {
        $this->seedScheduleData();
        DB::table('schedules')->insert([
            [
                'id' => 1,
                'room_id' => 1,
                'teacher_id' => 20,
                'school_id' => 1,
                'year_id' => 1,
                'day_of_week' => 1,
                'starts_at' => '09:00:00',
                'ends_at' => '10:00:00',
                'valid_from' => '2026-09-01',
                'valid_until' => null,
                'label' => 'Morning Hifz',
            ],
            [
                'id' => 2,
                'room_id' => 2,
                'teacher_id' => 21,
                'school_id' => 1,
                'year_id' => 1,
                'day_of_week' => 1,
                'starts_at' => '09:00:00',
                'ends_at' => '10:00:00',
                'valid_from' => '2026-09-01',
                'valid_until' => null,
                'label' => '=HYPERLINK("https://example.test")',
            ],
        ]);
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $csv = $this->get('/schedules/export?year_id=1&lang=fr&format=csv');
        $csv->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertHeader('Content-Disposition', "attachment; filename*=UTF-8''emploi-du-temps.csv")
            ->assertHeader('X-Export-Record-Count', '2');
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv->getContent());
        $this->assertStringContainsString('Lundi', $csv->getContent());
        $this->assertStringContainsString("'=HYPERLINK", $csv->getContent());

        $pdf = $this->get('/schedules/export?year_id=1&lang=ar&format=pdf');
        $pdf->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', "attachment; filename*=UTF-8''%D8%A7%D9%84%D8%AC%D8%AF%D9%88%D9%84-%D8%A7%D9%84%D8%A3%D8%B3%D8%A8%D9%88%D8%B9%D9%8A.pdf")
            ->assertHeader('X-Export-Record-Count', '2')
            ->assertHeader('X-Export-View', 'calendar');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->assertDatabaseCount('audit_events', 2);
        $formats = DB::table('audit_events')
            ->orderBy('id')
            ->get()
            ->map(fn ($event) => json_decode($event->changes, true)['format'])
            ->all();
        $this->assertSame(['csv', 'pdf'], $formats);
    }

    public function test_admin_exports_one_group_as_xlsx_and_audits_the_group_filter(): void
    {
        $this->seedScheduleData();
        DB::table('schedules')->insert([
            [
                'id' => 1,
                'room_id' => 1,
                'teacher_id' => 20,
                'school_id' => 1,
                'year_id' => 1,
                'day_of_week' => 1,
                'starts_at' => '09:00:00',
                'ends_at' => '10:00:00',
                'valid_from' => '2026-09-01',
                'label' => 'Alpha session',
            ],
            [
                'id' => 2,
                'room_id' => 2,
                'teacher_id' => 21,
                'school_id' => 1,
                'year_id' => 1,
                'day_of_week' => 1,
                'starts_at' => '09:00:00',
                'ends_at' => '10:00:00',
                'valid_from' => '2026-09-01',
                'label' => 'Beta session',
            ],
        ]);
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $csv = $this->get('/schedules/export?year_id=1&lang=fr&format=csv&room_id=2');
        $csv->assertOk()->assertHeader('X-Export-Record-Count', '1');
        $this->assertStringContainsString('Beta session', $csv->getContent());
        $this->assertStringNotContainsString('Alpha session', $csv->getContent());

        $xlsx = $this->get('/schedules/export?year_id=1&lang=ar&format=xlsx&room_id=2');
        $xlsx->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->assertHeader('Content-Disposition', "attachment; filename*=UTF-8''%D8%A7%D9%84%D8%AC%D8%AF%D9%88%D9%84-%D8%A7%D9%84%D8%A3%D8%B3%D8%A8%D9%88%D8%B9%D9%8A.xlsx")
            ->assertHeader('X-Export-Record-Count', '1');
        $this->assertStringStartsWith('PK', $xlsx->getContent());

        $temporaryFile = tempnam(sys_get_temp_dir(), 'schedule-test-');
        $this->assertNotFalse($temporaryFile);
        $workbook = null;
        try {
            $this->assertNotFalse(file_put_contents($temporaryFile, $xlsx->getContent()));
            $workbook = IOFactory::load($temporaryFile);
            $sheet = $workbook->getActiveSheet();
            $this->assertTrue($sheet->getRightToLeft());
            $this->assertSame('Beta', $sheet->getCell('C9')->getValue());
            $this->assertSame('Beta session', $sheet->getCell('E9')->getValue());
            $this->assertSame('A8:F9', $sheet->getAutoFilter()->getRange());
        } finally {
            if ($workbook) {
                $workbook->disconnectWorksheets();
            }
            if (is_file($temporaryFile)) {
                unlink($temporaryFile);
            }
        }

        $this->assertDatabaseCount('audit_events', 2);
        $xlsxAudit = json_decode(DB::table('audit_events')->orderByDesc('id')->value('changes'), true);
        $this->assertSame(['room' => 'Beta'], $xlsxAudit['filters']);

        $this->getJson('/schedules/export?year_id=1&format=xlsx&room_id=3')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('room_id');
    }

    public function test_admin_exports_a_group_student_weekly_pdf(): void
    {
        $this->seedScheduleData();
        DB::table('schedules')->insert([
            [
                'id' => 1,
                'room_id' => 1,
                'teacher_id' => 20,
                'school_id' => 1,
                'year_id' => 1,
                'day_of_week' => 1,
                'starts_at' => '07:00:00',
                'ends_at' => '08:00:00',
                'valid_from' => '2026-09-01',
                'label' => 'Morning Hifz',
            ],
            [
                'id' => 2,
                'room_id' => 1,
                'teacher_id' => 20,
                'school_id' => 1,
                'year_id' => 1,
                'day_of_week' => 3,
                'starts_at' => '10:00:00',
                'ends_at' => '11:00:00',
                'valid_from' => '2026-09-01',
                'label' => 'Review',
            ],
        ]);
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $pdf = $this->get('/schedules/export?year_id=1&lang=fr&format=pdf&room_id=1&audience=students');
        $pdf->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', "attachment; filename*=UTF-8''emploi-du-temps-eleves.pdf")
            ->assertHeader('X-Export-View', 'student-weekly')
            ->assertHeader('X-Export-Record-Count', '2');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());

        $audit = json_decode(DB::table('audit_events')->value('changes'), true);
        $this->assertSame('students', $audit['audience']);
        $this->assertSame('weekly', $audit['view']);
        $this->assertSame(['room' => 'Alpha'], $audit['filters']);

        $this->getJson('/schedules/export?year_id=1&format=pdf&audience=students')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('room_id');

        $this->get('/schedules/export?year_id=1&lang=fr&format=pdf&room_id=1')
            ->assertOk()
            ->assertHeader('X-Export-View', 'calendar');

        $calendarAudit = json_decode(DB::table('audit_events')->orderByDesc('id')->value('changes'), true);
        $this->assertSame('calendar', $calendarAudit['view']);
    }

    public function test_teacher_schedule_export_includes_only_own_schedule_and_validates_year_scope(): void
    {
        $this->seedScheduleData();
        DB::table('schedules')->insert([
            [
                'id' => 1,
                'room_id' => 1,
                'teacher_id' => 20,
                'school_id' => 1,
                'year_id' => 1,
                'day_of_week' => 1,
                'starts_at' => '09:00:00',
                'ends_at' => '10:00:00',
                'valid_from' => '2026-09-01',
                'label' => 'Own slot',
            ],
            [
                'id' => 2,
                'room_id' => 2,
                'teacher_id' => 21,
                'school_id' => 1,
                'year_id' => 1,
                'day_of_week' => 2,
                'starts_at' => '11:00:00',
                'ends_at' => '12:00:00',
                'valid_from' => '2026-09-01',
                'label' => 'Other teacher slot',
            ],
        ]);
        $this->actingAs(new GenericUser([
            'id' => 20,
            'school_id' => 1,
            'role' => 'teacher',
        ]));

        $this->get('/schedules/export?year_id=1&lang=fr&format=csv')
            ->assertOk()
            ->assertHeader('X-Export-Record-Count', '1')
            ->assertSeeText('Own slot')
            ->assertDontSeeText('Other teacher slot');
        $this->getJson('/schedules/export?year_id=1&format=csv&room_id=2')->assertUnprocessable();
        $this->getJson('/schedules/export?year_id=2&format=csv')
            ->assertNotFound();
    }

    public function test_admin_creates_and_updates_a_schedule_slot(): void
    {
        $this->seedScheduleData();
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));
        $schedule = [
            'year_id' => 1,
            'room_id' => 1,
            'teacher_id' => 20,
            'day_of_week' => 1,
            'starts_at' => '09:00',
            'ends_at' => '10:00',
            'valid_from' => '2026-09-01',
            'valid_until' => null,
            'label' => 'Morning Hifz',
        ];

        $response = $this->postJson('/schedules', $schedule)->assertCreated();
        $scheduleId = $response->json('schedule_id');

        $this->assertDatabaseHas('schedules', [
            'id' => $scheduleId,
            'room_id' => 1,
            'teacher_id' => 20,
            'school_id' => 1,
            'year_id' => 1,
            'label' => 'Morning Hifz',
        ]);

        $this->putJson("/schedules/{$scheduleId}", [
            ...$schedule,
            'room_id' => 2,
            'teacher_id' => 21,
            'starts_at' => '10:00',
            'ends_at' => '11:30',
            'label' => 'Updated class',
        ])->assertOk();

        $this->assertDatabaseHas('schedules', [
            'id' => $scheduleId,
            'room_id' => 2,
            'teacher_id' => 21,
            'starts_at' => '10:00',
            'ends_at' => '11:30',
            'label' => 'Updated class',
        ]);
    }

    public function test_admin_can_schedule_different_groups_at_the_same_time(): void
    {
        $this->seedScheduleData();
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));
        $schedule = [
            'year_id' => 1,
            'day_of_week' => 1,
            'starts_at' => '07:00',
            'ends_at' => '08:00',
            'valid_from' => '2026-09-01',
        ];

        $this->postJson('/schedules', [
            ...$schedule,
            'room_id' => 1,
            'teacher_id' => 20,
            'label' => 'Group Alpha',
        ])->assertCreated();
        $this->postJson('/schedules', [
            ...$schedule,
            'room_id' => 2,
            'teacher_id' => 21,
            'label' => 'Group Beta',
        ])->assertCreated();

        $this->getJson('/schedules/table?year_id=1')
            ->assertOk()
            ->assertJsonCount(2, 'schedules')
            ->assertJsonPath('schedules.0.label', 'Group Alpha')
            ->assertJsonPath('schedules.1.label', 'Group Beta');
    }

    public function test_schedule_write_rejects_a_day_outside_the_week(): void
    {
        $this->seedScheduleData();
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $this->postJson('/schedules', [
            'year_id' => 1,
            'room_id' => 1,
            'teacher_id' => 20,
            'day_of_week' => 8,
            'starts_at' => '09:00',
            'ends_at' => '10:00',
            'valid_from' => '2026-09-01',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('day_of_week');

        $this->assertDatabaseCount('schedules', 0);
    }

    public function test_schedule_writes_reject_invalid_times_and_room_or_teacher_overlaps(): void
    {
        $this->seedScheduleData();
        DB::table('schedules')->insert([
            'id' => 1,
            'room_id' => 1,
            'teacher_id' => 20,
            'school_id' => 1,
            'year_id' => 1,
            'day_of_week' => 1,
            'starts_at' => '09:00:00',
            'ends_at' => '10:00:00',
            'valid_from' => '2026-09-01',
            'valid_until' => null,
            'label' => 'Existing',
        ]);
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));
        $schedule = [
            'year_id' => 1,
            'room_id' => 2,
            'teacher_id' => 21,
            'day_of_week' => 1,
            'starts_at' => '09:30',
            'ends_at' => '10:30',
            'valid_from' => '2026-09-01',
        ];

        $this->postJson('/schedules', [
            ...$schedule,
            'ends_at' => '09:00',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ends_at');

        $this->postJson('/schedules', [
            ...$schedule,
            'teacher_id' => 20,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('schedule');

        $this->postJson('/schedules', [
            ...$schedule,
            'room_id' => 1,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('schedule');

        $this->postJson('/schedules', [
            ...$schedule,
            'teacher_id' => 12,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('teacher_id');

        $this->postJson('/schedules', [
            ...$schedule,
            'teacher_id' => 22,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('teacher_id');

        $this->postJson('/schedules', [
            ...$schedule,
            'room_id' => 4,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('room_id');

        $this->assertDatabaseCount('schedules', 1);
    }

    public function test_schedule_update_rejects_an_overlapping_teacher_slot_without_changing_the_existing_schedule(): void
    {
        $this->seedScheduleData();
        DB::table('schedules')->insert([
            [
                'id' => 1,
                'room_id' => 1,
                'teacher_id' => 20,
                'school_id' => 1,
                'year_id' => 1,
                'day_of_week' => 1,
                'starts_at' => '09:00:00',
                'ends_at' => '10:00:00',
                'valid_from' => '2026-09-01',
                'valid_until' => null,
                'label' => 'Existing',
            ],
            [
                'id' => 2,
                'room_id' => 2,
                'teacher_id' => 21,
                'school_id' => 1,
                'year_id' => 1,
                'day_of_week' => 1,
                'starts_at' => '11:00:00',
                'ends_at' => '12:00:00',
                'valid_from' => '2026-09-01',
                'valid_until' => null,
                'label' => 'Editable',
            ],
        ]);
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $this->putJson('/schedules/2', [
            'year_id' => 1,
            'room_id' => 2,
            'teacher_id' => 20,
            'day_of_week' => 1,
            'starts_at' => '09:30',
            'ends_at' => '10:30',
            'valid_from' => '2026-09-01',
            'valid_until' => null,
            'label' => 'Conflicting update',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('schedule');

        $this->assertDatabaseHas('schedules', [
            'id' => 2,
            'room_id' => 2,
            'teacher_id' => 21,
            'starts_at' => '11:00:00',
            'ends_at' => '12:00:00',
            'label' => 'Editable',
        ]);
    }

    public function test_schedule_slots_can_reuse_a_room_and_teacher_after_validity_ends(): void
    {
        $this->seedScheduleData();
        DB::table('schedules')->insert([
            'id' => 1,
            'room_id' => 1,
            'teacher_id' => 20,
            'school_id' => 1,
            'year_id' => 1,
            'day_of_week' => 1,
            'starts_at' => '09:00:00',
            'ends_at' => '10:00:00',
            'valid_from' => '2026-09-01',
            'valid_until' => '2026-12-31',
            'label' => 'First term',
        ]);
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $this->postJson('/schedules', [
            'year_id' => 1,
            'room_id' => 1,
            'teacher_id' => 20,
            'day_of_week' => 1,
            'starts_at' => '09:00',
            'ends_at' => '10:00',
            'valid_from' => '2027-01-01',
            'label' => 'Second term',
        ])->assertCreated();

        $this->assertDatabaseCount('schedules', 2);
    }

    public function test_secretary_can_read_schedules_but_cannot_write_them(): void
    {
        $this->seedScheduleData();
        $this->actingAs(new GenericUser([
            'id' => 12,
            'school_id' => 1,
            'role' => 'secretary',
        ]));

        $this->getJson('/schedules/table?year_id=1')->assertOk();

        $this->postJson('/schedules', [])->assertForbidden();
    }

    public function test_schedule_writes_are_allowed_for_an_active_noncurrent_year(): void
    {
        $this->seedScheduleData();
        DB::table('academic_years')->where('id', 1)->update(['is_current' => false]);
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $this->getJson('/schedules/table?year_id=1')
            ->assertOk()
            ->assertJsonPath('year_is_active', true)
            ->assertJsonPath('year_is_writable', true);

        $this->postJson('/schedules', [
            'year_id' => 1,
            'room_id' => 1,
            'teacher_id' => 20,
            'day_of_week' => 1,
            'starts_at' => '09:00',
            'ends_at' => '10:00',
            'valid_from' => '2026-09-01',
            'label' => 'Historical slot',
        ])->assertCreated();

        $this->assertDatabaseHas('schedules', [
            'year_id' => 1,
            'label' => 'Historical slot',
        ]);

        DB::table('academic_years')->where('id', 1)->update(['is_active' => false]);

        $this->postJson('/schedules', [
            'year_id' => 1,
            'room_id' => 1,
            'teacher_id' => 20,
            'day_of_week' => 1,
            'starts_at' => '09:00',
            'ends_at' => '10:00',
            'valid_from' => '2026-09-01',
            'label' => 'Historical slot',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('year_id');

        $this->assertDatabaseCount('schedules', 1);
    }

    public function test_schedule_access_is_scoped_to_the_users_school(): void
    {
        $this->seedScheduleData();
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $this->getJson('/schedules/table?year_id=2')->assertNotFound();

        $this->postJson('/schedules', [
            'year_id' => 2,
            'room_id' => 3,
            'teacher_id' => 30,
            'day_of_week' => 1,
            'starts_at' => '09:00',
            'ends_at' => '10:00',
            'valid_from' => '2026-09-01',
        ])->assertUnprocessable();

        $this->assertDatabaseCount('schedules', 0);
    }

    private function seedScheduleData(): void
    {
        DB::table('schools')->insert([
            ['id' => 1, 'name_fr' => 'École exemple', 'name_ar' => 'مدرسة تجريبية'],
            ['id' => 2, 'name_fr' => 'Autre école', 'name_ar' => 'مدرسة أخرى'],
        ]);
        DB::table('academic_years')->insert([
            ['id' => 1, 'school_id' => 1, 'year_label' => '2026-2027', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'is_current' => true, 'is_active' => true],
            ['id' => 2, 'school_id' => 2, 'year_label' => '2026-2027', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'is_current' => true, 'is_active' => true],
        ]);
        DB::table('roles')->insert([
            ['id' => 1, 'role_key' => 'admin'],
            ['id' => 2, 'role_key' => 'teacher'],
            ['id' => 3, 'role_key' => 'secretary'],
        ]);
        DB::table('users')->insert([
            ['id' => 10, 'school_id' => 1, 'role_id' => 1, 'full_name' => 'Admin', 'is_active' => true],
            ['id' => 12, 'school_id' => 1, 'role_id' => 3, 'full_name' => 'Secretary', 'is_active' => true],
            ['id' => 20, 'school_id' => 1, 'role_id' => 2, 'full_name' => 'Teacher One', 'is_active' => true],
            ['id' => 21, 'school_id' => 1, 'role_id' => 2, 'full_name' => 'Teacher Two', 'is_active' => true],
            ['id' => 22, 'school_id' => 1, 'role_id' => 2, 'full_name' => 'Inactive Teacher', 'is_active' => false],
            ['id' => 30, 'school_id' => 2, 'role_id' => 2, 'full_name' => 'Other School Teacher', 'is_active' => true],
        ]);
        DB::table('rooms')->insert([
            ['id' => 1, 'school_id' => 1, 'year_id' => 1, 'name' => 'Alpha', 'is_active' => true],
            ['id' => 2, 'school_id' => 1, 'year_id' => 1, 'name' => 'Beta', 'is_active' => true],
            ['id' => 3, 'school_id' => 2, 'year_id' => 2, 'name' => 'Other School Room', 'is_active' => true],
            ['id' => 4, 'school_id' => 1, 'year_id' => 1, 'name' => 'Archived Room', 'is_active' => false],
        ]);
    }
}
