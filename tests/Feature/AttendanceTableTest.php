<?php

namespace Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AttendanceTableTest extends TestCase
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

        Schema::create('roles', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('role_key');
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedInteger('role_id');
            $table->string('full_name');
            $table->boolean('is_active')->default(true);
        });

        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('year_id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
        });

        Schema::create('room_teachers', function (Blueprint $table) {
            $table->unsignedBigInteger('room_id');
            $table->unsignedBigInteger('teacher_id');
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('year_id');
            $table->date('assigned_from');
            $table->date('assigned_until')->nullable();
            $table->primary(['room_id', 'teacher_id', 'assigned_from']);
        });

        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('year_id');
            $table->unsignedBigInteger('room_id')->nullable();
            $table->string('student_number')->nullable();
            $table->string('full_name');
            $table->string('status')->default('active');
        });

        Schema::create('attendance', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('room_id')->nullable();
            $table->unsignedBigInteger('year_id');
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->date('attendance_date');
            $table->string('status');
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['student_id', 'year_id', 'attendance_date']);
        });
    }

    public function test_attendance_table_requires_authentication(): void
    {
        $this->getJson('/attendance/table')->assertUnauthorized();
    }

    public function test_attendance_roster_requires_authentication(): void
    {
        $this->getJson('/attendance/roster?year_id=1')->assertUnauthorized();
    }

    public function test_bulk_attendance_requires_authentication(): void
    {
        $this->postJson('/attendance/bulk', [])->assertUnauthorized();
        $this->postJson('/attendance/bulk/undo', [])->assertUnauthorized();
    }

    public function test_non_teacher_cannot_save_bulk_attendance(): void
    {
        $this->seedAttendance();
        $this->travelTo('2026-10-10 12:00:00');
        $this->actingAs($this->user(10, 1, 'admin'));

        $this->postJson('/attendance/bulk', [
            'year_id' => 1,
            'entries' => [
                ['student_id' => 1, 'status' => 'present'],
            ],
        ])->assertForbidden();

        $this->assertDatabaseCount('attendance', 5);
    }

    public function test_teacher_can_save_mixed_attendance_for_assigned_students_in_one_request(): void
    {
        $this->seedAttendance();
        $this->travelTo('2026-10-10 12:00:00');
        DB::table('attendance')->where('id', 1)->update(['note' => 'Keep this note']);
        DB::table('students')->insert(collect(range(5, 15))
            ->map(fn (int $studentId) => [
                'id' => $studentId,
                'school_id' => 1,
                'year_id' => 1,
                'room_id' => 1,
                'student_number' => 'S-'.(100 + $studentId),
                'full_name' => "Student {$studentId}",
                'status' => 'active',
            ])
            ->all());
        $this->actingAs($this->user(20, 1, 'teacher'));

        $entries = [
            ['student_id' => 1, 'status' => 'late'],
            ['student_id' => 5, 'status' => 'absent'],
            ...collect(range(6, 15))
                ->map(fn (int $studentId) => ['student_id' => $studentId, 'status' => 'present'])
                ->all(),
        ];
        $this->postJson('/attendance/bulk', [
            'year_id' => 1,
            'entries' => $entries,
        ])
            ->assertOk()
            ->assertJsonPath('saved_count', 12);

        $this->assertDatabaseCount('attendance', 16);
        $this->assertDatabaseHas('attendance', [
            'student_id' => 1,
            'attendance_date' => '2026-10-10',
            'status' => 'late',
            'recorded_by' => 20,
            'note' => 'Keep this note',
        ]);
        $this->assertDatabaseHas('attendance', [
            'student_id' => 5,
            'attendance_date' => '2026-10-10',
            'status' => 'absent',
            'recorded_by' => 20,
        ]);
        $this->assertDatabaseHas('attendance', [
            'student_id' => 15,
            'attendance_date' => '2026-10-10',
            'status' => 'present',
            'recorded_by' => 20,
        ]);
    }

    public function test_saving_the_same_attendance_day_twice_updates_existing_rows_without_duplicates(): void
    {
        $this->seedAttendance();
        $this->travelTo('2026-10-10 12:00:00');
        DB::table('students')->insert([
            'id' => 5,
            'school_id' => 1,
            'year_id' => 1,
            'room_id' => 1,
            'student_number' => 'S-102',
            'full_name' => 'Adam Active',
            'status' => 'active',
        ]);
        $this->actingAs($this->user(20, 1, 'teacher'));

        $this->postJson('/attendance/bulk', [
            'year_id' => 1,
            'entries' => [
                ['student_id' => 1, 'status' => 'late'],
                ['student_id' => 5, 'status' => 'absent'],
            ],
        ])->assertOk();

        $this->postJson('/attendance/bulk', [
            'year_id' => 1,
            'entries' => [
                ['student_id' => 1, 'status' => 'excused'],
                ['student_id' => 5, 'status' => 'present'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('saved_count', 2);

        $this->assertDatabaseCount('attendance', 6);
        $this->assertDatabaseHas('attendance', [
            'student_id' => 1,
            'school_id' => 1,
            'year_id' => 1,
            'attendance_date' => '2026-10-10',
            'status' => 'excused',
            'recorded_by' => 20,
        ]);
        $this->assertDatabaseHas('attendance', [
            'student_id' => 5,
            'school_id' => 1,
            'year_id' => 1,
            'attendance_date' => '2026-10-10',
            'status' => 'present',
            'recorded_by' => 20,
        ]);
    }

    public function test_partial_bulk_save_keeps_attendance_rows_omitted_from_the_request(): void
    {
        $this->seedAttendance();
        $this->travelTo('2026-10-10 12:00:00');
        DB::table('students')->insert([
            'id' => 5,
            'school_id' => 1,
            'year_id' => 1,
            'room_id' => 1,
            'student_number' => 'S-102',
            'full_name' => 'Adam Active',
            'status' => 'active',
        ]);
        $this->actingAs($this->user(20, 1, 'teacher'));
        $this->postJson('/attendance/bulk', [
            'year_id' => 1,
            'entries' => [
                ['student_id' => 1, 'status' => 'late'],
                ['student_id' => 5, 'status' => 'absent'],
            ],
        ])->assertOk();

        $this->postJson('/attendance/bulk', [
            'year_id' => 1,
            'entries' => [
                ['student_id' => 1, 'status' => 'excused'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('saved_count', 1);

        $this->assertDatabaseCount('attendance', 6);
        $this->assertDatabaseHas('attendance', [
            'student_id' => 1,
            'attendance_date' => '2026-10-10',
            'status' => 'excused',
            'recorded_by' => 20,
        ]);
        $this->assertDatabaseHas('attendance', [
            'student_id' => 5,
            'attendance_date' => '2026-10-10',
            'status' => 'absent',
            'recorded_by' => 20,
        ]);
    }

    public function test_bulk_attendance_rejects_a_room_outside_the_teacher_assignment_without_partial_writes(): void
    {
        $this->seedAttendance();
        $this->travelTo('2026-10-10 12:00:00');
        DB::table('students')->insert([
            'id' => 5,
            'school_id' => 1,
            'year_id' => 1,
            'room_id' => 1,
            'student_number' => 'S-102',
            'full_name' => 'Adam Active',
            'status' => 'active',
        ]);
        $this->actingAs($this->user(20, 1, 'teacher'));

        $this->postJson('/attendance/bulk', [
            'year_id' => 1,
            'entries' => [
                ['student_id' => 5, 'status' => 'present'],
                ['student_id' => 2, 'status' => 'absent'],
            ],
        ])->assertForbidden();

        $this->assertDatabaseCount('attendance', 5);
        $this->assertDatabaseMissing('attendance', [
            'student_id' => 5,
            'attendance_date' => '2026-10-10',
        ]);
    }

    public function test_bulk_attendance_is_limited_to_the_current_active_academic_year(): void
    {
        $this->seedAttendance();
        $this->travelTo('2026-10-10 12:00:00');
        $this->actingAs($this->user(20, 1, 'teacher'));

        $this->postJson('/attendance/bulk', [
            'year_id' => 3,
            'entries' => [
                ['student_id' => 4, 'status' => 'present'],
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('year_id');

        $this->assertDatabaseCount('attendance', 5);
    }

    public function test_bulk_attendance_cannot_overwrite_another_teachers_record(): void
    {
        $this->seedAttendance();
        $this->travelTo('2026-10-10 12:00:00');
        DB::table('attendance')->where('id', 1)->update(['recorded_by' => 21]);
        $this->actingAs($this->user(20, 1, 'teacher'));

        $this->postJson('/attendance/bulk', [
            'year_id' => 1,
            'entries' => [
                ['student_id' => 1, 'status' => 'absent'],
            ],
        ])->assertForbidden();

        $this->assertDatabaseHas('attendance', [
            'id' => 1,
            'status' => 'present',
            'recorded_by' => 21,
        ]);
    }

    public function test_teacher_can_undo_a_saved_attendance_batch(): void
    {
        $this->seedAttendance();
        $this->travelTo('2026-10-10 12:00:00');
        DB::table('attendance')->where('id', 1)->update(['note' => 'Keep this note']);
        DB::table('students')->insert([
            'id' => 5,
            'school_id' => 1,
            'year_id' => 1,
            'room_id' => 1,
            'student_number' => 'S-102',
            'full_name' => 'Adam Active',
            'status' => 'active',
        ]);
        $this->actingAs($this->user(20, 1, 'teacher'));

        $this->postJson('/attendance/bulk', [
            'year_id' => 1,
            'entries' => [
                ['student_id' => 1, 'status' => 'late'],
                ['student_id' => 5, 'status' => 'absent'],
            ],
        ])->assertOk();

        $this->postJson('/attendance/bulk/undo', [
            'year_id' => 1,
            'entries' => [
                ['student_id' => 1, 'saved_status' => 'late', 'previous_status' => 'present'],
                ['student_id' => 5, 'saved_status' => 'absent', 'previous_status' => null],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('undone_count', 2);

        $this->assertDatabaseCount('attendance', 5);
        $this->assertDatabaseHas('attendance', [
            'id' => 1,
            'status' => 'present',
            'recorded_by' => 20,
            'note' => 'Keep this note',
        ]);
        $this->assertDatabaseMissing('attendance', [
            'student_id' => 5,
            'attendance_date' => '2026-10-10',
        ]);
    }

    public function test_bulk_undo_rejects_stale_records_without_partial_changes(): void
    {
        $this->seedAttendance();
        $this->travelTo('2026-10-10 12:00:00');
        DB::table('students')->insert([
            'id' => 5,
            'school_id' => 1,
            'year_id' => 1,
            'room_id' => 1,
            'student_number' => 'S-102',
            'full_name' => 'Adam Active',
            'status' => 'active',
        ]);
        $this->actingAs($this->user(20, 1, 'teacher'));
        $this->postJson('/attendance/bulk', [
            'year_id' => 1,
            'entries' => [
                ['student_id' => 1, 'status' => 'late'],
                ['student_id' => 5, 'status' => 'absent'],
            ],
        ])->assertOk();
        DB::table('attendance')->where('id', 1)->update(['status' => 'excused']);

        $this->postJson('/attendance/bulk/undo', [
            'year_id' => 1,
            'entries' => [
                ['student_id' => 1, 'saved_status' => 'late', 'previous_status' => 'present'],
                ['student_id' => 5, 'saved_status' => 'absent', 'previous_status' => null],
            ],
        ])->assertConflict();

        $this->assertDatabaseHas('attendance', ['id' => 1, 'status' => 'excused']);
        $this->assertDatabaseHas('attendance', [
            'student_id' => 5,
            'attendance_date' => '2026-10-10',
            'status' => 'absent',
        ]);
    }

    public function test_non_teacher_cannot_undo_bulk_attendance(): void
    {
        $this->seedAttendance();
        $this->actingAs($this->user(10, 1, 'admin'));

        $this->postJson('/attendance/bulk/undo', [
            'year_id' => 1,
            'entries' => [
                ['student_id' => 1, 'saved_status' => 'present', 'previous_status' => null],
            ],
        ])->assertForbidden();

        $this->assertDatabaseCount('attendance', 5);
    }

    public function test_bulk_attendance_rejects_invalid_duplicate_and_unexpected_entry_fields(): void
    {
        $this->seedAttendance();
        $this->travelTo('2026-10-10 12:00:00');
        $this->actingAs($this->user(20, 1, 'teacher'));

        $this->postJson('/attendance/bulk', [
            'year_id' => 1,
            'entries' => [
                ['student_id' => 1, 'status' => 'present'],
                ['student_id' => '1', 'status' => 'absent'],
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('entries.1.student_id');

        $this->postJson('/attendance/bulk', [
            'year_id' => 1,
            'entries' => [
                ['student_id' => 1, 'status' => 'unknown', 'recorded_by' => 21],
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['entries.0', 'entries.0.status']);

        $this->assertDatabaseCount('attendance', 5);
    }

    public function test_bulk_attendance_rolls_back_the_whole_batch_when_the_database_rejects_an_insert(): void
    {
        $this->seedAttendance();
        $this->travelTo('2026-10-10 12:00:00');
        DB::table('students')->insert([
            'id' => 5,
            'school_id' => 1,
            'year_id' => 1,
            'room_id' => 1,
            'student_number' => 'S-102',
            'full_name' => 'Adam Active',
            'status' => 'active',
        ]);
        DB::statement(
            "CREATE TRIGGER reject_bulk_attendance_insert
            BEFORE INSERT ON attendance
            WHEN NEW.student_id = 5
            BEGIN
                SELECT RAISE(ABORT, 'simulated batch failure');
            END",
        );
        $this->actingAs($this->user(20, 1, 'teacher'));

        $this->postJson('/attendance/bulk', [
            'year_id' => 1,
            'entries' => [
                ['student_id' => 1, 'status' => 'late'],
                ['student_id' => 5, 'status' => 'absent'],
            ],
        ])->assertServerError();

        $this->assertDatabaseHas('attendance', [
            'id' => 1,
            'status' => 'present',
        ]);
        $this->assertDatabaseMissing('attendance', [
            'student_id' => 5,
            'attendance_date' => '2026-10-10',
        ]);
        $this->assertDatabaseCount('attendance', 5);
    }

    public function test_admin_sees_only_school_records_for_the_selected_year(): void
    {
        $this->seedAttendance();
        $this->actingAs($this->user(10, 1, 'admin'));

        $this->getJson('/attendance/table?year_id=1')
            ->assertOk()
            ->assertJsonPath('can_correct', true)
            ->assertJsonPath('can_mark', false)
            ->assertJsonPath('can_follow_up', false)
            ->assertJsonCount(3, 'attendance')
            ->assertJsonPath('attendance.0.student_name', 'Zayd Ali')
            ->assertJsonPath('attendance.2.teacher_name', 'Teacher One')
            ->assertJsonCount(2, 'rooms')
            ->assertJsonCount(2, 'students')
            ->assertJsonCount(2, 'teachers');
    }

    public function test_active_noncurrent_year_attendance_can_be_corrected_but_closed_year_cannot(): void
    {
        $this->seedAttendance();
        DB::table('academic_years')->where('id', 3)->update(['is_active' => true]);
        $this->actingAs($this->user(10, 1, 'admin'));

        $this->getJson('/attendance/table?year_id=3')
            ->assertOk()
            ->assertJsonPath('year_is_writable', true)
            ->assertJsonPath('can_correct', true)
            ->assertJsonPath('can_mark', false)
            ->assertJsonPath('can_follow_up', false)
            ->assertJsonPath('attendance.0.can_edit', true);

        $this->postJson('/attendance', [
            'year_id' => 3,
            'student_id' => 4,
            'attendance_date' => '2026-01-10',
            'status' => 'late',
            'note' => 'Historical correction',
        ])->assertOk();

        DB::table('academic_years')->where('id', 3)->update(['is_active' => false]);

        $this->postJson('/attendance', [
            'year_id' => 3,
            'student_id' => 4,
            'attendance_date' => '2026-01-10',
            'status' => 'late',
            'note' => 'Historical correction',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('year_id');

        $this->assertDatabaseHas('attendance', [
            'id' => 5,
            'status' => 'late',
            'note' => 'Historical correction',
        ]);
    }

    public function test_teacher_can_view_attendance_for_an_open_noncurrent_year(): void
    {
        $this->seedAttendance();
        DB::table('academic_years')->where('id', 3)->update(['is_active' => true]);
        $this->actingAs($this->user(20, 1, 'teacher'));

        $this->getJson('/attendance/table?year_id=3')
            ->assertOk()
            ->assertJsonPath('year_id', 3)
            ->assertJsonPath('year_is_writable', true)
            ->assertJsonPath('can_mark', false);
    }

    public function test_teacher_only_sees_records_from_rooms_assigned_on_the_attendance_date(): void
    {
        $this->seedAttendance();
        $this->travelTo('2026-10-10 12:00:00');
        $this->actingAs($this->user(20, 1, 'teacher'));

        $this->getJson('/attendance/table?year_id=1')
            ->assertOk()
            ->assertJsonPath('can_mark', true)
            ->assertJsonPath('can_correct', false)
            ->assertJsonPath('attendance.0.can_edit', true)
            ->assertJsonCount(1, 'attendance')
            ->assertJsonPath('attendance.0.student_name', 'Zayd Ali')
            ->assertJsonPath('attendance.0.room_name', 'Alpha')
            ->assertJsonCount(1, 'rooms')
            ->assertJsonCount(1, 'students');
    }

    public function test_secretary_can_view_school_attendance_and_is_marked_for_follow_up(): void
    {
        $this->seedAttendance();
        $this->actingAs($this->user(12, 1, 'secretary'));

        $this->getJson('/attendance/table?year_id=1')
            ->assertOk()
            ->assertJsonPath('can_correct', false)
            ->assertJsonPath('can_mark', false)
            ->assertJsonPath('can_follow_up', true)
            ->assertJsonCount(3, 'attendance');
    }

    public function test_attendance_table_filters_by_room_teacher_student_date_and_status(): void
    {
        $this->seedAttendance();
        $this->actingAs($this->user(10, 1, 'admin'));

        $this->getJson('/attendance/table?year_id=1&room_id=2&teacher_id=21&student_id=2&attendance_date=2026-10-10&status=absent')
            ->assertOk()
            ->assertJsonCount(1, 'attendance')
            ->assertJsonPath('attendance.0.id', 2)
            ->assertJsonPath('attendance.0.note', 'Family informed');
    }

    public function test_attendance_table_rejects_an_invalid_status_filter(): void
    {
        $this->seedAttendance();
        $this->actingAs($this->user(10, 1, 'admin'));

        $this->getJson('/attendance/table?year_id=1&status=unknown')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_attendance_table_rejects_a_student_filter_from_another_school(): void
    {
        $this->seedAttendance();
        $this->actingAs($this->user(10, 1, 'admin'));

        $this->getJson('/attendance/table?year_id=1&student_id=3')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('student_id');
    }

    public function test_teacher_roster_contains_only_active_students_in_current_assigned_rooms_without_writing(): void
    {
        $this->seedAttendance();
        $this->travelTo('2026-10-10 12:00:00');
        DB::table('students')->insert([
            ['id' => 5, 'school_id' => 1, 'year_id' => 1, 'room_id' => 1, 'student_number' => 'S-102', 'full_name' => 'Adam Active', 'status' => 'active'],
            ['id' => 6, 'school_id' => 1, 'year_id' => 1, 'room_id' => 1, 'student_number' => 'S-103', 'full_name' => 'Lina Paused', 'status' => 'paused'],
            ['id' => 7, 'school_id' => 1, 'year_id' => 1, 'room_id' => 1, 'student_number' => 'S-104', 'full_name' => 'Mina Withdrawn', 'status' => 'withdrawn'],
        ]);
        $this->actingAs($this->user(20, 1, 'teacher'));

        $this->getJson('/attendance/roster?year_id=1')
            ->assertOk()
            ->assertJsonPath('year_id', 1)
            ->assertJsonPath('attendance_date', '2026-10-10')
            ->assertJsonCount(1, 'rooms')
            ->assertJsonPath('rooms.0.id', 1)
            ->assertJsonCount(2, 'rooms.0.students')
            ->assertJsonPath('rooms.0.students.0.name', 'Adam Active')
            ->assertJsonPath('rooms.0.students.0.status', null)
            ->assertJsonPath('rooms.0.students.1.name', 'Zayd Ali')
            ->assertJsonPath('rooms.0.students.1.status', 'present');

        $this->assertDatabaseCount('attendance', 5);
    }

    public function test_attendance_roster_requires_a_teacher_and_scopes_the_selected_year_to_the_school(): void
    {
        $this->seedAttendance();
        $this->actingAs($this->user(10, 1, 'admin'));

        $this->getJson('/attendance/roster?year_id=1')->assertForbidden();

        $this->actingAs($this->user(20, 1, 'teacher'));

        $this->getJson('/attendance/roster?year_id=2')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('year_id');
    }

    public function test_teacher_roster_includes_only_rooms_assigned_on_the_roster_date(): void
    {
        $this->seedAttendance();
        $this->travelTo('2026-10-10 12:00:00');
        $this->actingAs($this->user(21, 1, 'teacher'));

        $this->getJson('/attendance/roster?year_id=1')
            ->assertOk()
            ->assertJsonCount(1, 'rooms')
            ->assertJsonPath('rooms.0.id', 2)
            ->assertJsonCount(1, 'rooms.0.students')
            ->assertJsonPath('rooms.0.students.0.name', 'Amina Benali');
    }

    public function test_teacher_roster_marks_another_teachers_record_as_read_only(): void
    {
        $this->seedAttendance();
        $this->travelTo('2026-10-10 12:00:00');
        DB::table('attendance')->where('id', 1)->update(['recorded_by' => 21]);
        $this->actingAs($this->user(20, 1, 'teacher'));

        $this->getJson('/attendance/roster?year_id=1')
            ->assertOk()
            ->assertJsonPath('rooms.0.students.0.can_edit', false);
    }

    public function test_attendance_roster_is_unavailable_for_a_past_year(): void
    {
        $this->seedAttendance();
        $this->actingAs($this->user(20, 1, 'teacher'));

        $this->getJson('/attendance/roster?year_id=3')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('year_id');
    }

    public function test_attendance_table_does_not_resolve_another_schools_year(): void
    {
        $this->seedAttendance();
        $this->actingAs($this->user(10, 1, 'admin'));

        $this->getJson('/attendance/table?year_id=2')->assertNotFound();
    }

    public function test_admin_correction_updates_existing_record_and_records_the_correcting_admin(): void
    {
        $this->seedAttendance();
        $this->actingAs($this->user(10, 1, 'admin'));

        $this->postJson('/attendance', [
            'year_id' => 1,
            'student_id' => 2,
            'attendance_date' => '2026-10-10',
            'status' => 'late',
            'note' => 'Arrived after class started',
        ])
            ->assertOk()
            ->assertJsonPath('attendance_id', 2)
            ->assertJsonPath('created', false);

        $this->assertDatabaseCount('attendance', 5);
        $this->assertDatabaseHas('attendance', [
            'id' => 2,
            'recorded_by' => 10,
            'status' => 'late',
            'note' => 'Arrived after class started',
        ]);
    }

    public function test_admin_cannot_create_an_attendance_record_from_the_correction_form(): void
    {
        $this->seedAttendance();
        $this->actingAs($this->user(10, 1, 'admin'));

        $this->postJson('/attendance', [
            'year_id' => 1,
            'student_id' => 1,
            'attendance_date' => '2026-10-11',
            'status' => 'present',
            'note' => null,
        ])->assertNotFound();

        $this->assertDatabaseMissing('attendance', [
            'student_id' => 1,
            'year_id' => 1,
            'attendance_date' => '2026-10-11',
        ]);
    }

    public function test_teacher_can_mark_and_remark_an_attendance_record_only_once_per_day(): void
    {
        $this->seedAttendance();
        $this->travelTo('2026-10-11 12:00:00');
        $this->actingAs($this->user(20, 1, 'teacher'));
        $payload = [
            'year_id' => 1,
            'student_id' => 1,
            'attendance_date' => '2026-10-11',
            'status' => 'present',
            'note' => null,
        ];

        $this->postJson('/attendance', $payload)
            ->assertCreated()
            ->assertJsonPath('created', true);

        $this->postJson('/attendance', [
            ...$payload,
            'status' => 'late',
            'note' => 'Arrived late',
        ])
            ->assertOk()
            ->assertJsonPath('created', false);

        $this->assertDatabaseCount('attendance', 6);
        $this->assertDatabaseHas('attendance', [
            'student_id' => 1,
            'attendance_date' => '2026-10-11',
            'recorded_by' => 20,
            'status' => 'late',
            'note' => 'Arrived late',
        ]);
    }

    public function test_teacher_cannot_change_another_teachers_attendance_record(): void
    {
        $this->seedAttendance();
        $this->travelTo('2026-10-10 12:00:00');
        $this->actingAs($this->user(20, 1, 'teacher'));

        $this->postJson('/attendance', [
            'year_id' => 1,
            'student_id' => 2,
            'attendance_date' => '2026-10-10',
            'status' => 'present',
            'note' => null,
        ])->assertForbidden();

        $this->assertDatabaseHas('attendance', [
            'id' => 2,
            'recorded_by' => 21,
            'status' => 'absent',
            'note' => 'Family informed',
        ]);
    }

    public function test_teacher_cannot_correct_attendance_after_the_same_calendar_day(): void
    {
        $this->seedAttendance();
        $this->travelTo('2026-11-03 12:00:00');
        $this->actingAs($this->user(20, 1, 'teacher'));

        $this->postJson('/attendance', [
            'year_id' => 1,
            'student_id' => 1,
            'attendance_date' => '2026-11-02',
            'status' => 'absent',
            'note' => null,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('attendance_date');

        $this->assertDatabaseHas('attendance', [
            'id' => 3,
            'recorded_by' => 20,
            'status' => 'late',
        ]);
    }

    public function test_teacher_cannot_mark_students_in_an_unassigned_room(): void
    {
        $this->seedAttendance();
        $this->travelTo('2026-10-10 12:00:00');
        $this->actingAs($this->user(20, 1, 'teacher'));

        $this->postJson('/attendance', [
            'year_id' => 1,
            'student_id' => 2,
            'attendance_date' => '2026-10-10',
            'status' => 'present',
            'note' => null,
        ])->assertForbidden();

        $this->assertDatabaseHas('attendance', [
            'id' => 2,
            'recorded_by' => 21,
            'status' => 'absent',
        ]);
    }

    public function test_teacher_cannot_mark_attendance_in_an_archived_room(): void
    {
        $this->seedAttendance();
        DB::table('rooms')->where('id', 1)->update(['is_active' => false]);
        $this->travelTo('2026-10-11 12:00:00');
        $this->actingAs($this->user(20, 1, 'teacher'));

        $this->postJson('/attendance', [
            'year_id' => 1,
            'student_id' => 1,
            'attendance_date' => '2026-10-11',
            'status' => 'present',
            'note' => null,
        ])->assertForbidden();

        $this->assertDatabaseMissing('attendance', [
            'student_id' => 1,
            'year_id' => 1,
            'attendance_date' => '2026-10-11',
        ]);
    }

    public function test_teacher_cannot_mark_attendance_for_an_inactive_student(): void
    {
        $this->seedAttendance();
        DB::table('students')->where('id', 1)->update(['status' => 'inactive']);
        $this->travelTo('2026-10-11 12:00:00');
        $this->actingAs($this->user(20, 1, 'teacher'));

        $this->postJson('/attendance', [
            'year_id' => 1,
            'student_id' => 1,
            'attendance_date' => '2026-10-11',
            'status' => 'present',
            'note' => null,
        ])->assertForbidden();

        $this->assertDatabaseMissing('attendance', [
            'student_id' => 1,
            'year_id' => 1,
            'attendance_date' => '2026-10-11',
        ]);
    }

    public function test_unauthenticated_request_cannot_undo_attendance(): void
    {
        $this->deleteJson('/attendance/1')->assertUnauthorized();
    }

    public function test_teacher_can_undo_their_own_attendance_for_today(): void
    {
        $this->seedAttendance();
        $this->travelTo('2026-10-10 12:00:00');
        $this->actingAs($this->user(20, 1, 'teacher'));

        $this->deleteJson('/attendance/1')
            ->assertOk()
            ->assertJsonPath('deleted', true);

        $this->assertDatabaseMissing('attendance', [
            'id' => 1,
        ]);
    }

    public function test_teacher_cannot_undo_another_teachers_attendance(): void
    {
        $this->seedAttendance();
        $this->travelTo('2026-10-10 12:00:00');
        $this->actingAs($this->user(20, 1, 'teacher'));

        $this->deleteJson('/attendance/2')->assertForbidden();

        $this->assertDatabaseHas('attendance', [
            'id' => 2,
            'recorded_by' => 21,
            'status' => 'absent',
        ]);
    }

    public function test_teacher_cannot_undo_another_schools_attendance(): void
    {
        $this->seedAttendance();
        $this->travelTo('2026-10-10 12:00:00');
        $this->actingAs($this->user(20, 1, 'teacher'));

        $this->deleteJson('/attendance/4')->assertNotFound();

        $this->assertDatabaseHas('attendance', [
            'id' => 4,
            'school_id' => 2,
        ]);
    }

    public function test_teacher_cannot_undo_attendance_after_today(): void
    {
        $this->seedAttendance();
        $this->travelTo('2026-10-11 12:00:00');
        $this->actingAs($this->user(20, 1, 'teacher'));

        $this->deleteJson('/attendance/1')->assertNotFound();

        $this->assertDatabaseHas('attendance', [
            'id' => 1,
            'status' => 'present',
        ]);
    }

    public function test_non_teacher_cannot_undo_attendance(): void
    {
        $this->seedAttendance();
        $this->travelTo('2026-10-10 12:00:00');
        $this->actingAs($this->user(10, 1, 'admin'));

        $this->deleteJson('/attendance/1')->assertForbidden();

        $this->assertDatabaseHas('attendance', [
            'id' => 1,
            'status' => 'present',
        ]);
    }

    public function test_secretary_can_change_a_follow_up_note_without_changing_status(): void
    {
        $this->seedAttendance();
        $this->actingAs($this->user(12, 1, 'secretary'));

        $this->postJson('/attendance', [
            'year_id' => 1,
            'student_id' => 2,
            'attendance_date' => '2026-10-10',
            'note' => 'Guardian contacted',
        ])
            ->assertOk()
            ->assertJsonPath('attendance_id', 2)
            ->assertJsonPath('created', false);

        $this->assertDatabaseHas('attendance', [
            'id' => 2,
            'status' => 'absent',
            'note' => 'Guardian contacted',
            'recorded_by' => 21,
        ]);
    }

    public function test_secretary_cannot_change_attendance_status(): void
    {
        $this->seedAttendance();
        $this->actingAs($this->user(12, 1, 'secretary'));

        $this->postJson('/attendance', [
            'year_id' => 1,
            'student_id' => 2,
            'attendance_date' => '2026-10-10',
            'status' => 'present',
            'note' => 'Guardian contacted',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertDatabaseHas('attendance', [
            'id' => 2,
            'status' => 'absent',
            'note' => 'Family informed',
        ]);
    }

    private function seedAttendance(): void
    {
        DB::table('academic_years')->insert([
            ['id' => 1, 'school_id' => 1, 'year_label' => '2026-2027', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'is_current' => true, 'is_active' => true],
            ['id' => 2, 'school_id' => 2, 'year_label' => '2026-2027', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'is_current' => true, 'is_active' => true],
            ['id' => 3, 'school_id' => 1, 'year_label' => '2025-2026', 'start_date' => '2025-09-01', 'end_date' => '2026-06-30', 'is_current' => false, 'is_active' => false],
        ]);
        DB::table('roles')->insert([
            ['id' => 1, 'role_key' => 'admin'],
            ['id' => 2, 'role_key' => 'teacher'],
            ['id' => 3, 'role_key' => 'secretary'],
        ]);
        DB::table('users')->insert([
            ['id' => 10, 'school_id' => 1, 'role_id' => 1, 'full_name' => 'Admin One'],
            ['id' => 12, 'school_id' => 1, 'role_id' => 3, 'full_name' => 'Secretary One'],
            ['id' => 20, 'school_id' => 1, 'role_id' => 2, 'full_name' => 'Teacher One'],
            ['id' => 21, 'school_id' => 1, 'role_id' => 2, 'full_name' => 'Teacher Two'],
            ['id' => 30, 'school_id' => 2, 'role_id' => 2, 'full_name' => 'Teacher Other'],
        ]);
        DB::table('rooms')->insert([
            ['id' => 1, 'school_id' => 1, 'year_id' => 1, 'name' => 'Alpha', 'is_active' => true],
            ['id' => 2, 'school_id' => 1, 'year_id' => 1, 'name' => 'Beta', 'is_active' => true],
            ['id' => 3, 'school_id' => 2, 'year_id' => 2, 'name' => 'Other', 'is_active' => true],
            ['id' => 4, 'school_id' => 1, 'year_id' => 3, 'name' => 'Previous Year', 'is_active' => false],
        ]);
        DB::table('room_teachers')->insert([
            ['room_id' => 1, 'teacher_id' => 20, 'school_id' => 1, 'year_id' => 1, 'assigned_from' => '2026-09-01', 'assigned_until' => '2026-10-31'],
            ['room_id' => 2, 'teacher_id' => 21, 'school_id' => 1, 'year_id' => 1, 'assigned_from' => '2026-09-01', 'assigned_until' => null],
        ]);
        DB::table('students')->insert([
            ['id' => 1, 'school_id' => 1, 'year_id' => 1, 'room_id' => 1, 'student_number' => 'S-100', 'full_name' => 'Zayd Ali'],
            ['id' => 2, 'school_id' => 1, 'year_id' => 1, 'room_id' => 2, 'student_number' => 'S-101', 'full_name' => 'Amina Benali'],
            ['id' => 3, 'school_id' => 2, 'year_id' => 2, 'room_id' => 3, 'student_number' => 'S-200', 'full_name' => 'Other Student'],
            ['id' => 4, 'school_id' => 1, 'year_id' => 3, 'room_id' => 4, 'student_number' => 'S-099', 'full_name' => 'Previous Year Student'],
        ]);
        DB::table('attendance')->insert([
            ['id' => 1, 'student_id' => 1, 'school_id' => 1, 'room_id' => 1, 'year_id' => 1, 'recorded_by' => 20, 'attendance_date' => '2026-10-10', 'status' => 'present', 'note' => null],
            ['id' => 2, 'student_id' => 2, 'school_id' => 1, 'room_id' => 2, 'year_id' => 1, 'recorded_by' => 21, 'attendance_date' => '2026-10-10', 'status' => 'absent', 'note' => 'Family informed'],
            ['id' => 3, 'student_id' => 1, 'school_id' => 1, 'room_id' => 1, 'year_id' => 1, 'recorded_by' => 20, 'attendance_date' => '2026-11-02', 'status' => 'late', 'note' => null],
            ['id' => 4, 'student_id' => 3, 'school_id' => 2, 'room_id' => 3, 'year_id' => 2, 'recorded_by' => 30, 'attendance_date' => '2026-10-10', 'status' => 'present', 'note' => null],
            ['id' => 5, 'student_id' => 4, 'school_id' => 1, 'room_id' => 4, 'year_id' => 3, 'recorded_by' => null, 'attendance_date' => '2026-01-10', 'status' => 'present', 'note' => null],
        ]);
    }

    private function user(int $id, int $schoolId, string $role): GenericUser
    {
        return new GenericUser([
            'id' => $id,
            'school_id' => $schoolId,
            'role' => $role,
        ]);
    }
}
