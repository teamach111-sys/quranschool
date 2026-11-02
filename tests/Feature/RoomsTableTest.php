<?php

namespace Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RoomsTableTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

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
            $table->string('name', 100);
            $table->string('level');
            $table->unsignedTinyInteger('age_min')->nullable();
            $table->unsignedTinyInteger('age_max')->nullable();
            $table->unsignedSmallInteger('capacity')->default(12);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
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
            $table->string('status');
            $table->timestamps();
        });
    }

    public function test_rooms_table_requires_authentication(): void
    {
        $this->getJson('/rooms/table')->assertUnauthorized();
    }

    public function test_admin_sees_school_rooms_with_active_teachers_and_student_counts(): void
    {
        $this->seedRooms();
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $this->getJson('/rooms/table?year_id=1')
            ->assertOk()
            ->assertJsonPath('can_manage', true)
            ->assertJsonCount(2, 'rooms')
            ->assertJsonPath('rooms.0.name', 'Alpha')
            ->assertJsonPath('rooms.0.student_count', 2)
            ->assertJsonPath('rooms.0.assigned_teachers.0.name', 'Teacher One')
            ->assertJsonPath('rooms.1.student_count', 1)
            ->assertJsonCount(2, 'teachers');
    }

    public function test_secretary_can_view_rooms_and_students_but_teacher_only_sees_assigned_active_rooms(): void
    {
        $this->seedRooms();
        $this->actingAs(new GenericUser([
            'id' => 12,
            'school_id' => 1,
            'role' => 'secretary',
        ]));

        $this->getJson('/rooms/table?year_id=1')
            ->assertOk()
            ->assertJsonPath('can_manage', false)
            ->assertJsonPath('can_place', true)
            ->assertJsonCount(4, 'students')
            ->assertJsonMissingPath('teachers')
            ->assertJsonMissingPath('assignments');

        $this->actingAs(new GenericUser([
            'id' => 20,
            'school_id' => 1,
            'role' => 'teacher',
        ]));

        $this->getJson('/rooms/table?year_id=1')
            ->assertOk()
            ->assertJsonPath('is_teacher_view', true)
            ->assertJsonPath('can_place', false)
            ->assertJsonCount(1, 'rooms')
            ->assertJsonPath('rooms.0.name', 'Alpha')
            ->assertJsonPath('rooms.0.assigned_teachers.0.assigned_from', '2020-01-01')
            ->assertJsonPath('rooms.0.student_count', 2)
            ->assertJsonCount(2, 'students')
            ->assertJsonPath('students.0.full_name', 'Paused Student')
            ->assertJsonPath('students.0.status', 'paused')
            ->assertJsonPath('students.1.full_name', 'Student One')
            ->assertJsonMissing(['full_name' => 'Student Two'])
            ->assertJsonMissing(['full_name' => 'Student Three'])
            ->assertJsonMissingPath('teachers')
            ->assertJsonMissingPath('assignments');
    }

    public function test_admin_can_create_and_update_rooms_with_valid_age_range_and_capacity(): void
    {
        $this->seedRooms();
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $this->postJson('/rooms', [
            'year_id' => 1,
            'name' => '  Gamma  ',
            'level' => 'intermediate',
            'age_min' => 10,
            'age_max' => 14,
            'capacity' => 18,
            'is_active' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('room_id', 4);

        $this->assertDatabaseHas('rooms', [
            'id' => 4,
            'school_id' => 1,
            'year_id' => 1,
            'name' => 'Gamma',
            'capacity' => 18,
        ]);

        $this->putJson('/rooms/4', [
            'year_id' => 1,
            'name' => 'Gamma Advanced',
            'level' => 'advanced',
            'age_min' => 12,
            'age_max' => 16,
            'capacity' => 20,
            'is_active' => true,
        ])->assertOk();

        $this->assertDatabaseHas('rooms', [
            'id' => 4,
            'name' => 'Gamma Advanced',
            'level' => 'advanced',
        ]);
    }

    public function test_room_validation_rejects_reversed_ages_and_zero_capacity(): void
    {
        $this->seedRooms();
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));
        $payload = [
            'year_id' => 1,
            'name' => 'Invalid Room',
            'level' => 'beginner',
            'age_min' => 15,
            'age_max' => 10,
            'capacity' => 0,
            'is_active' => true,
        ];

        $this->postJson('/rooms', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['age_min', 'capacity']);

        $this->assertDatabaseMissing('rooms', ['name' => 'Invalid Room']);
    }

    public function test_admin_archives_rooms_without_deleting_them_or_students(): void
    {
        $this->seedRooms();
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $this->patchJson('/rooms/1/archive', ['year_id' => 1])
            ->assertOk()
            ->assertJsonPath('is_active', false);

        $this->assertDatabaseHas('rooms', ['id' => 1, 'is_active' => false]);
        $this->assertDatabaseHas('students', ['id' => 1, 'room_id' => 1]);
    }

    public function test_secretary_can_place_students_in_active_rooms_and_unassign_them(): void
    {
        $this->seedRooms();
        $this->actingAs(new GenericUser([
            'id' => 12,
            'school_id' => 1,
            'role' => 'secretary',
        ]));

        $this->patchJson('/students/1/room', ['year_id' => 1, 'room_id' => 2])
            ->assertOk()
            ->assertJsonPath('room_id', 2);

        $this->assertDatabaseHas('students', ['id' => 1, 'room_id' => 2]);

        $this->patchJson('/students/1/room', ['year_id' => 1, 'room_id' => null])
            ->assertOk()
            ->assertJsonPath('room_id', null);

        $this->assertDatabaseHas('students', ['id' => 1, 'room_id' => null]);
    }

    public function test_student_placement_cannot_exceed_room_capacity(): void
    {
        $this->seedRooms();
        DB::table('rooms')->where('id', 1)->update(['capacity' => 1]);
        $this->actingAs(new GenericUser([
            'id' => 12,
            'school_id' => 1,
            'role' => 'secretary',
        ]));

        $this->patchJson('/students/3/room', ['year_id' => 1, 'room_id' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('room_capacity');

        $this->assertDatabaseHas('students', ['id' => 3, 'room_id' => null]);
    }

    public function test_room_and_placement_mutations_enforce_roles_and_school_scope(): void
    {
        $this->seedRooms();
        $this->actingAs(new GenericUser([
            'id' => 12,
            'school_id' => 1,
            'role' => 'secretary',
        ]));

        $this->postJson('/rooms', [
            'year_id' => 1,
            'name' => 'Unauthorized Room',
            'level' => 'beginner',
            'capacity' => 12,
            'is_active' => true,
        ])->assertForbidden();

        $this->patchJson('/students/1/room', ['year_id' => 1, 'room_id' => 3])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('room_id');

        $this->actingAs(new GenericUser([
            'id' => 20,
            'school_id' => 1,
            'role' => 'teacher',
        ]));

        $this->patchJson('/students/1/room', ['year_id' => 1, 'room_id' => 2])
            ->assertForbidden();

        $this->patchJson('/rooms/1/archive', ['year_id' => 1])
            ->assertForbidden();

        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $this->getJson('/rooms/table?year_id=2')->assertNotFound();
        $this->putJson('/rooms/3', [
            'year_id' => 2,
            'name' => 'Other School Room',
            'level' => 'beginner',
            'capacity' => 12,
            'is_active' => true,
        ])->assertNotFound();
    }

    public function test_room_and_student_placement_can_change_in_open_noncurrent_year_but_not_closed_year(): void
    {
        $this->seedRooms();
        DB::table('academic_years')->insert([
            'id' => 3,
            'school_id' => 1,
            'year_label' => '2024-2025',
            'start_date' => '2024-09-01',
            'end_date' => '2025-06-30',
            'is_current' => false,
            'is_active' => true,
        ]);
        DB::table('rooms')->insert([
            'id' => 4,
            'school_id' => 1,
            'year_id' => 3,
            'name' => 'Closed Room',
            'level' => 'beginner',
            'capacity' => 12,
            'is_active' => true,
        ]);
        DB::table('students')->insert([
            'id' => 6,
            'school_id' => 1,
            'year_id' => 3,
            'room_id' => 4,
            'full_name' => 'Closed Year Student',
            'status' => 'active',
        ]);
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $this->getJson('/rooms/table?year_id=3')
            ->assertOk()
            ->assertJsonPath('year_is_active', true)
            ->assertJsonPath('year_is_writable', true);

        $this->patchJson('/rooms/4/archive', ['year_id' => 3])
            ->assertOk();

        $this->assertDatabaseHas('rooms', ['id' => 4, 'is_active' => false]);

        $this->patchJson('/students/6/room', ['year_id' => 3, 'room_id' => null])
            ->assertOk();

        $this->assertDatabaseHas('students', ['id' => 6, 'room_id' => null]);

        DB::table('academic_years')->where('id', 3)->update(['is_active' => false]);

        $this->patchJson('/rooms/4/archive', ['year_id' => 3])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('year_id');

        $this->patchJson('/students/6/room', ['year_id' => 3, 'room_id' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('year_id');
    }

    public function test_admin_assignment_requires_active_teacher_and_rejects_overlapping_ranges(): void
    {
        $this->seedRooms();
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $this->postJson('/rooms/2/teachers', [
            'year_id' => 1,
            'teacher_id' => 20,
            'assigned_from' => '2026-09-01',
            'assigned_until' => null,
        ])->assertCreated();

        $this->assertDatabaseHas('room_teachers', [
            'room_id' => 2,
            'teacher_id' => 20,
            'year_id' => 1,
        ]);

        $this->postJson('/rooms/1/teachers', [
            'year_id' => 1,
            'teacher_id' => 20,
            'assigned_from' => '2026-01-01',
            'assigned_until' => '2026-12-31',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('teacher_id');

        $this->postJson('/rooms/2/teachers', [
            'year_id' => 1,
            'teacher_id' => 12,
            'assigned_from' => '2026-09-01',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('teacher_id');

        $this->postJson('/rooms/2/teachers', [
            'year_id' => 1,
            'teacher_id' => 22,
            'assigned_from' => '2026-09-01',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('teacher_id');

        $this->postJson('/rooms/2/teachers', [
            'year_id' => 1,
            'teacher_id' => 30,
            'assigned_from' => '2026-09-01',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('teacher_id');

        $this->postJson('/rooms/2/teachers', [
            'year_id' => 1,
            'teacher_id' => 21,
            'assigned_from' => '2026-09-01',
            'assigned_until' => '2026-08-31',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assigned_until');

        $this->actingAs(new GenericUser([
            'id' => 12,
            'school_id' => 1,
            'role' => 'secretary',
        ]));

        $this->postJson('/rooms/2/teachers', [
            'year_id' => 1,
            'teacher_id' => 21,
            'assigned_from' => '2026-09-01',
        ])->assertForbidden();

        $this->assertDatabaseMissing('room_teachers', [
            'room_id' => 2,
            'teacher_id' => 21,
            'assigned_from' => '2026-09-01',
        ]);
    }

    public function test_admin_can_view_all_year_assignments_and_end_an_assignment_without_deleting_history(): void
    {
        $this->seedRooms();
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $this->getJson('/rooms/table?year_id=1')
            ->assertOk()
            ->assertJsonCount(1, 'assignments')
            ->assertJsonPath('assignments.0.room_name', 'Alpha')
            ->assertJsonPath('assignments.0.teacher_name', 'Teacher One')
            ->assertJsonPath('assignments.0.assigned_from', '2020-01-01')
            ->assertJsonPath('assignments.0.assigned_until', null);

        $this->patchJson('/rooms/1/teachers/20/end', [
            'year_id' => 1,
            'assigned_from' => '2020-01-01',
        ])
            ->assertOk()
            ->assertJsonPath('assigned_until', now()->toDateString());

        $this->assertDatabaseHas('room_teachers', [
            'room_id' => 1,
            'teacher_id' => 20,
            'school_id' => 1,
            'year_id' => 1,
            'assigned_from' => '2020-01-01',
            'assigned_until' => now()->toDateString(),
        ]);

        $this->getJson('/rooms/table?year_id=1')
            ->assertOk()
            ->assertJsonCount(1, 'assignments')
            ->assertJsonPath('assignments.0.assigned_until', now()->toDateString());
    }

    public function test_only_admins_can_end_year_scoped_teacher_assignments(): void
    {
        $this->seedRooms();
        $this->actingAs(new GenericUser([
            'id' => 12,
            'school_id' => 1,
            'role' => 'secretary',
        ]));

        $this->patchJson('/rooms/1/teachers/20/end', [
            'year_id' => 1,
            'assigned_from' => '2020-01-01',
        ])->assertForbidden();

        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        DB::table('academic_years')->insert([
            'id' => 3,
            'school_id' => 1,
            'year_label' => '2027-2028',
            'start_date' => '2027-09-01',
            'end_date' => '2028-06-30',
            'is_current' => false,
            'is_active' => true,
        ]);

        $this->patchJson('/rooms/1/teachers/20/end', [
            'year_id' => 3,
            'assigned_from' => '2020-01-01',
        ])->assertNotFound();

        $this->assertDatabaseHas('room_teachers', [
            'room_id' => 1,
            'teacher_id' => 20,
            'assigned_until' => null,
        ]);
    }

    public function test_future_assignments_cannot_be_ended_before_they_start(): void
    {
        $this->seedRooms();
        DB::table('room_teachers')->insert([
            'room_id' => 2,
            'teacher_id' => 21,
            'school_id' => 1,
            'year_id' => 1,
            'assigned_from' => '2999-01-01',
            'assigned_until' => null,
        ]);
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $this->patchJson('/rooms/2/teachers/21/end', [
            'year_id' => 1,
            'assigned_from' => '2999-01-01',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assigned_from');

        $this->assertDatabaseHas('room_teachers', [
            'room_id' => 2,
            'teacher_id' => 21,
            'assigned_from' => '2999-01-01',
            'assigned_until' => null,
        ]);
    }

    private function seedRooms(): void
    {
        DB::table('schools')->insert([
            ['id' => 1, 'name' => 'School One'],
            ['id' => 2, 'name' => 'School Two'],
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
            ['id' => 1, 'school_id' => 1, 'year_id' => 1, 'name' => 'Alpha', 'level' => 'beginner', 'age_min' => 6, 'age_max' => 9, 'capacity' => 12, 'is_active' => true],
            ['id' => 2, 'school_id' => 1, 'year_id' => 1, 'name' => 'Beta', 'level' => 'intermediate', 'age_min' => 10, 'age_max' => 14, 'capacity' => 15, 'is_active' => true],
            ['id' => 3, 'school_id' => 2, 'year_id' => 2, 'name' => 'Other School Room', 'level' => 'beginner', 'age_min' => null, 'age_max' => null, 'capacity' => 12, 'is_active' => true],
        ]);
        DB::table('students')->insert([
            ['id' => 1, 'school_id' => 1, 'year_id' => 1, 'room_id' => 1, 'student_number' => 'S-100', 'full_name' => 'Student One', 'status' => 'active'],
            ['id' => 2, 'school_id' => 1, 'year_id' => 1, 'room_id' => 1, 'student_number' => 'S-101', 'full_name' => 'Student Two', 'status' => 'withdrawn'],
            ['id' => 3, 'school_id' => 1, 'year_id' => 1, 'room_id' => null, 'student_number' => 'S-102', 'full_name' => 'Unassigned Student', 'status' => 'paused'],
            ['id' => 4, 'school_id' => 1, 'year_id' => 1, 'room_id' => 2, 'student_number' => 'S-103', 'full_name' => 'Student Three', 'status' => 'active'],
            ['id' => 5, 'school_id' => 1, 'year_id' => 1, 'room_id' => 1, 'student_number' => 'S-104', 'full_name' => 'Paused Student', 'status' => 'paused'],
        ]);
        DB::table('room_teachers')->insert([
            ['room_id' => 1, 'teacher_id' => 20, 'school_id' => 1, 'year_id' => 1, 'assigned_from' => '2020-01-01', 'assigned_until' => null],
        ]);
    }
}
