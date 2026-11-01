<?php

namespace Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StudentsTableTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->string('name_fr');
            $table->string('name_ar');
            $table->timestamps();
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
        });

        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('year_id');
            $table->unsignedBigInteger('room_id')->nullable();
            $table->string('student_number')->nullable();
            $table->string('full_name');
            $table->enum('status', ['active', 'paused', 'withdrawn'])->default('active');
            $table->date('enrollment_date')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('guardian_name')->nullable();
            $table->string('guardian_phone')->nullable();
            $table->string('guardian_email')->nullable();
            $table->text('notes')->nullable();
        });

        DB::table('schools')->insert([
            ['id' => 1, 'name_fr' => 'School A', 'name_ar' => 'مدرسة أ'],
            ['id' => 2, 'name_fr' => 'School B', 'name_ar' => 'مدرسة ب'],
        ]);

        DB::table('academic_years')->insert([
            ['id' => 1, 'school_id' => 1, 'year_label' => '2026-2027', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'is_current' => true, 'is_active' => true],
            ['id' => 2, 'school_id' => 2, 'year_label' => '2026-2027', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'is_current' => true, 'is_active' => true],
        ]);

        DB::table('rooms')->insert([
            ['id' => 1, 'school_id' => 1, 'year_id' => 1, 'name' => 'Alpha', 'is_active' => true],
            ['id' => 2, 'school_id' => 1, 'year_id' => 1, 'name' => 'Beta', 'is_active' => true],
            ['id' => 3, 'school_id' => 2, 'year_id' => 2, 'name' => 'Other', 'is_active' => true],
        ]);

        DB::table('students')->insert([
            ['id' => 1, 'school_id' => 1, 'year_id' => 1, 'room_id' => 1, 'student_number' => 'S-100', 'full_name' => 'Zayd Ali', 'status' => 'active', 'enrollment_date' => '2026-09-01', 'birth_date' => '2015-01-01', 'guardian_name' => 'Ali', 'guardian_phone' => '0600000001', 'guardian_email' => 'ali@example.com', 'notes' => null],
            ['id' => 2, 'school_id' => 1, 'year_id' => 1, 'room_id' => 2, 'student_number' => 'S-101', 'full_name' => 'Amina Benali', 'status' => 'paused', 'enrollment_date' => '2026-09-02', 'birth_date' => '2014-05-01', 'guardian_name' => 'Benali', 'guardian_phone' => '0600000002', 'guardian_email' => 'benali@example.com', 'notes' => null],
            ['id' => 3, 'school_id' => 1, 'year_id' => 1, 'room_id' => 2, 'student_number' => 'S-102', 'full_name' => 'Imane Karim', 'status' => 'withdrawn', 'enrollment_date' => '2026-09-03', 'birth_date' => '2013-08-03', 'guardian_name' => 'Karim', 'guardian_phone' => '0600000003', 'guardian_email' => 'karim@example.com', 'notes' => null],
            ['id' => 4, 'school_id' => 2, 'year_id' => 2, 'room_id' => 3, 'student_number' => 'S-200', 'full_name' => 'Other School Student', 'status' => 'active', 'enrollment_date' => '2026-09-01', 'birth_date' => '2015-06-01', 'guardian_name' => 'Other', 'guardian_phone' => '0600000004', 'guardian_email' => 'other@example.com', 'notes' => null],
        ]);

        DB::table('room_teachers')->insert([
            ['room_id' => 1, 'teacher_id' => 20, 'school_id' => 1, 'year_id' => 1, 'assigned_from' => '2026-09-01', 'assigned_until' => null],
            ['room_id' => 2, 'teacher_id' => 20, 'school_id' => 1, 'year_id' => 1, 'assigned_from' => '2026-09-01', 'assigned_until' => null],
        ]);
    }

    public function test_admin_sees_all_students_in_the_selected_school_and_year(): void
    {
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $this->getJson('/students/table?year_id=1')
            ->assertOk()
            ->assertJsonPath('can_manage', true)
            ->assertJsonCount(3, 'students');
    }

    public function test_teacher_only_sees_assigned_room_students_and_hides_withdrawn_by_default(): void
    {
        $this->actingAs(new GenericUser([
            'id' => 20,
            'school_id' => 1,
            'role' => 'teacher',
        ]));

        $this->getJson('/students/table?year_id=1')
            ->assertOk()
            ->assertJsonPath('can_manage', false)
            ->assertJsonCount(2, 'students')
            ->assertJsonPath('students.0.full_name', 'Amina Benali')
            ->assertJsonPath('students.1.full_name', 'Zayd Ali');
    }
}
