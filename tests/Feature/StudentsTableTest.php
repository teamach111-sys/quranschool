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
            $table->string('student_number_prefix', 20)->nullable();
            $table->boolean('student_number_use_dash')->default(true);
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->string('full_name');
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
            $table->unsignedSmallInteger('capacity')->default(12);
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
            $table->timestamps();
        });

        Schema::create('attendance', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('room_id')->nullable();
            $table->unsignedBigInteger('year_id');
            $table->date('attendance_date');
            $table->string('status');
        });

        Schema::create('memorization_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('room_id')->nullable();
            $table->unsignedBigInteger('teacher_id')->nullable();
            $table->unsignedBigInteger('year_id');
            $table->date('session_date');
            $table->unsignedTinyInteger('surah_start')->nullable();
            $table->unsignedSmallInteger('ayah_start')->nullable();
            $table->unsignedTinyInteger('surah_end')->nullable();
            $table->unsignedSmallInteger('ayah_end')->nullable();
            $table->string('portion_label');
            $table->string('evaluation');
            $table->text('notes')->nullable();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('year_id');
            $table->string('currency', 3);
            $table->decimal('amount', 10, 2);
            $table->string('status');
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
            $table->json('changes')->nullable();
            $table->string('reason')->nullable();
            $table->string('request_id', 100)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        DB::table('users')->insert([
            ['id' => 10, 'school_id' => 1, 'full_name' => 'Admin User'],
            ['id' => 20, 'school_id' => 1, 'full_name' => 'Teacher User'],
            ['id' => 12, 'school_id' => 1, 'full_name' => 'Secretary User'],
        ]);

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

    public function test_admin_exports_the_filtered_csv_and_records_the_export(): void
    {
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $response = $this->get('/students/export?year_id=1&search=benali&room_id=2&status=all&lang=fr&format=csv');

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertHeader('X-Export-Record-Count', '1');
        $rows = $this->parseCsv($response->getContent());
        $this->assertSame(['Numéro', 'Nom complet', 'Groupe', 'Responsable', 'Statut'], $rows[6]);
        $this->assertCount(5, $rows[7]);
        $this->assertStringContainsString('Nom complet', $response->getContent());
        $this->assertStringContainsString('Responsable', $response->getContent());
        $this->assertStringContainsString('Amina Benali', $response->getContent());
        $this->assertStringContainsString('Benali', $response->getContent());
        $this->assertStringNotContainsString('Zayd Ali', $response->getContent());

        $this->assertDatabaseHas('audit_events', [
            'school_id' => 1,
            'year_id' => 1,
            'actor_user_id' => 10,
            'action_type' => 'exported',
            'entity_type' => 'student',
            'summary_key' => 'students.exported',
        ]);
        $changes = json_decode(DB::table('audit_events')->value('changes'), true);
        $this->assertSame(1, $changes['record_count']);
        $this->assertSame('students', $changes['page']);
        $this->assertSame('Beta', $changes['filters']['room']);
    }

    public function test_teacher_export_is_limited_to_active_assigned_students_and_hides_guardian_data(): void
    {
        $this->actingAs(new GenericUser([
            'id' => 20,
            'school_id' => 1,
            'role' => 'teacher',
        ]));

        $response = $this->get('/students/export?year_id=1&status=all&lang=ar&format=csv');

        $response->assertOk()->assertHeader('X-Export-Record-Count', '2');
        $rows = $this->parseCsv($response->getContent());
        $this->assertSame(['الرقم', 'الاسم الكامل', 'المجموعة', 'الحالة'], $rows[6]);
        $this->assertCount(4, $rows[7]);
        $this->assertStringContainsString('الاسم الكامل', $response->getContent());
        $this->assertStringContainsString('نشط', $response->getContent());
        $this->assertStringNotContainsString('ولي الأمر', $response->getContent());
        $this->assertStringNotContainsString('0600000001', $response->getContent());
        $this->assertStringNotContainsString('Imane Karim', $response->getContent());
    }

    public function test_student_csv_export_neutralizes_spreadsheet_formulas_and_rejects_foreign_rooms(): void
    {
        DB::table('students')->where('id', 1)->update(['full_name' => '=1+1']);
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $this->get('/students/export?year_id=1&status=all&format=csv')
            ->assertOk()
            ->assertSee("'=1+1", false);

        $this->getJson('/students/export?year_id=1&room_id=3')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('room_id');
    }

    public function test_student_csv_export_preserves_the_selected_sort_order(): void
    {
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $response = $this->get('/students/export?year_id=1&status=all&format=csv&sort_key=full_name&sort_direction=desc');

        $rows = $this->parseCsv($response->getContent());
        $this->assertSame('Zayd Ali', $rows[7][1]);
        $this->assertSame('Imane Karim', $rows[8][1]);
        $this->assertSame('Amina Benali', $rows[9][1]);
    }

    public function test_admin_exports_students_as_xlsx_and_pdf_and_audits_each_format(): void
    {
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $this->get('/students/export?year_id=1&status=all&format=xlsx&lang=fr')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->assertHeader('X-Export-Record-Count', '3')
            ->assertSee('PK', false);

        $this->get('/students/export?year_id=1&status=all&format=pdf&lang=fr')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Export-Record-Count', '3')
            ->assertSee('%PDF-', false);

        $exports = DB::table('audit_events')
            ->orderBy('id')
            ->get()
            ->map(fn ($event) => json_decode($event->changes, true)['format'])
            ->all();
        $this->assertSame(['xlsx', 'pdf'], $exports);
    }

    public function test_admin_can_create_a_student_in_an_active_year(): void
    {
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $this->postJson('/students', [
            'year_id' => 1,
            'full_name' => '  New Student  ',
            'student_number' => 'S-103',
            'birth_date' => '2016-04-12',
            'guardian_name' => 'Parent',
            'guardian_phone' => '0600000103',
            'guardian_email' => 'parent@example.com',
            'room_id' => 1,
            'enrollment_date' => '2026-09-10',
            'status' => 'active',
            'notes' => 'First enrollment',
        ])
            ->assertCreated()
            ->assertJsonPath('student.full_name', 'New Student')
            ->assertJsonPath('student.room_name', 'Alpha');

        $this->assertDatabaseHas('students', [
            'school_id' => 1,
            'year_id' => 1,
            'student_number' => 'S-103',
            'full_name' => 'New Student',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'entity_type' => 'student',
            'summary_key' => 'student.created',
            'actor_user_id' => 10,
        ]);
    }

    /**
     * @return list<list<string|null>>
     */
    private function parseCsv(string $contents): array
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $contents);
        rewind($stream);
        $rows = [];

        while (($row = fgetcsv($stream)) !== false) {
            if ($row === [null]) {
                continue;
            }
            if ($rows === []) {
                $row[0] = ltrim((string) $row[0], "\xEF\xBB\xBF");
            }
            $rows[] = $row;
        }

        fclose($stream);

        return $rows;
    }

    public function test_admin_generates_the_next_student_number_when_none_is_provided(): void
    {
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $first = $this->postJson('/students', [
            'year_id' => 1,
            'full_name' => 'New Student One',
            'birth_date' => '2016-04-12',
            'guardian_name' => 'Parent One',
            'guardian_phone' => '0600000103',
            'guardian_email' => 'parent1@example.com',
            'room_id' => 1,
            'enrollment_date' => '2026-09-10',
            'status' => 'active',
            'notes' => 'First enrollment',
        ]);

        $first->assertCreated()->assertJsonPath('student.student_number', 'QS-2026-103');

        $second = $this->postJson('/students', [
            'year_id' => 1,
            'full_name' => 'New Student Two',
            'birth_date' => '2016-04-13',
            'guardian_name' => 'Parent Two',
            'guardian_phone' => '0600000104',
            'guardian_email' => 'parent2@example.com',
            'room_id' => 1,
            'enrollment_date' => '2026-09-11',
            'status' => 'active',
            'notes' => 'Second enrollment',
        ]);

        $second->assertCreated()->assertJsonPath('student.student_number', 'QS-2026-104');

        $this->assertDatabaseHas('students', [
            'school_id' => 1,
            'year_id' => 1,
            'student_number' => 'QS-2026-103',
            'full_name' => 'New Student One',
        ]);

        $this->assertDatabaseHas('students', [
            'school_id' => 1,
            'year_id' => 1,
            'student_number' => 'QS-2026-104',
            'full_name' => 'New Student Two',
        ]);
    }

    public function test_generated_student_number_can_omit_the_dash_separator(): void
    {
        DB::table('schools')->where('id', 1)->update([
            'student_number_prefix' => 'N',
            'student_number_use_dash' => false,
        ]);

        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $this->postJson('/students', [
            'year_id' => 1,
            'full_name' => 'No Dash Student',
            'birth_date' => '2016-04-14',
            'guardian_name' => 'Parent Dash',
            'guardian_phone' => '0600000105',
            'guardian_email' => 'nodash@example.com',
            'room_id' => 1,
            'enrollment_date' => '2026-09-12',
            'status' => 'active',
        ])->assertCreated()->assertJsonPath('student.student_number', 'N001');
    }

    public function test_secretary_can_update_student_details_and_room_in_the_same_year(): void
    {
        $this->actingAs(new GenericUser([
            'id' => 12,
            'school_id' => 1,
            'role' => 'secretary',
        ]));

        $this->putJson('/students/1', [
            'year_id' => 1,
            'full_name' => 'Zayd Ali Updated',
            'student_number' => 'S-100',
            'birth_date' => '2015-01-01',
            'guardian_name' => 'Updated Parent',
            'guardian_phone' => '0600000999',
            'guardian_email' => 'updated@example.com',
            'room_id' => 2,
            'enrollment_date' => '2026-09-01',
            'status' => 'paused',
            'notes' => 'Updated notes',
        ])
            ->assertOk()
            ->assertJsonPath('student.full_name', 'Zayd Ali Updated')
            ->assertJsonPath('student.room_name', 'Beta')
            ->assertJsonPath('student.status', 'paused');

        $this->assertDatabaseHas('students', [
            'id' => 1,
            'school_id' => 1,
            'year_id' => 1,
            'room_id' => 2,
            'status' => 'paused',
        ]);
        $event = DB::table('audit_events')
            ->where('entity_id', 1)
            ->where('summary_key', 'student.updated')
            ->first();
        $changes = json_decode($event->changes, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Zayd Ali', $changes['full_name']['before']);
        $this->assertSame('Zayd Ali Updated', $changes['full_name']['after']);
    }

    public function test_admin_can_pause_a_student_without_changing_student_details(): void
    {
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $this->patchJson('/students/1/status', [
            'year_id' => 1,
            'status' => 'paused',
        ])
            ->assertOk()
            ->assertJsonPath('student.status', 'paused')
            ->assertJsonPath('student.full_name', 'Zayd Ali');

        $this->assertDatabaseHas('students', [
            'id' => 1,
            'status' => 'paused',
            'full_name' => 'Zayd Ali',
            'room_id' => 1,
        ]);
    }

    public function test_secretary_can_withdraw_a_student(): void
    {
        $this->actingAs(new GenericUser([
            'id' => 12,
            'school_id' => 1,
            'role' => 'secretary',
        ]));

        $this->patchJson('/students/1/status', [
            'year_id' => 1,
            'status' => 'withdrawn',
        ])
            ->assertOk()
            ->assertJsonPath('student.status', 'withdrawn');

        $this->assertDatabaseHas('students', [
            'id' => 1,
            'status' => 'withdrawn',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'entity_id' => 1,
            'summary_key' => 'student.status_changed',
        ]);
    }

    public function test_student_archive_rejects_active_status_and_preserves_student(): void
    {
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $this->patchJson('/students/1/status', [
            'year_id' => 1,
            'status' => 'active',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertDatabaseHas('students', [
            'id' => 1,
            'status' => 'active',
        ]);
    }

    public function test_teacher_cannot_archive_a_student_and_other_school_students_are_hidden(): void
    {
        $this->actingAs(new GenericUser([
            'id' => 20,
            'school_id' => 1,
            'role' => 'teacher',
        ]));

        $this->patchJson('/students/1/status', [
            'year_id' => 1,
            'status' => 'paused',
        ])->assertForbidden();

        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $this->patchJson('/students/4/status', [
            'year_id' => 3,
            'status' => 'paused',
        ])->assertNotFound();
    }

    public function test_student_archive_is_allowed_in_an_active_noncurrent_academic_year(): void
    {
        DB::table('academic_years')->insert([
            'id' => 3,
            'school_id' => 1,
            'year_label' => '2024-2025',
            'start_date' => '2024-09-01',
            'end_date' => '2025-06-30',
            'is_current' => false,
            'is_active' => true,
        ]);
        DB::table('students')->insert([
            'id' => 5,
            'school_id' => 1,
            'year_id' => 3,
            'full_name' => 'Closed Year Student',
            'status' => 'active',
            'enrollment_date' => '2024-09-10',
        ]);
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $this->getJson('/students/table?year_id=3')
            ->assertOk()
            ->assertJsonPath('year_is_active', true)
            ->assertJsonPath('year_is_writable', true);

        $this->getJson('/students/options?year_id=3')
            ->assertOk()
            ->assertJsonPath('is_editable', true);

        $this->patchJson('/students/5/status', [
            'year_id' => 3,
            'status' => 'paused',
        ])->assertOk();

        $this->assertDatabaseHas('students', [
            'id' => 5,
            'status' => 'paused',
        ]);
    }

    public function test_student_re_enrollment_prefills_previous_year_details_for_a_new_record(): void
    {
        DB::table('academic_years')->insert([
            'id' => 3,
            'school_id' => 1,
            'year_label' => '2025-2026',
            'start_date' => '2025-09-01',
            'end_date' => '2026-06-30',
            'is_current' => false,
            'is_active' => true,
        ]);

        DB::table('students')->insert([
            'id' => 10,
            'school_id' => 1,
            'year_id' => 3,
            'room_id' => 1,
            'student_number' => 'QS-2025-010',
            'full_name' => 'Returning Student',
            'status' => 'active',
            'enrollment_date' => '2025-09-10',
            'birth_date' => '2014-04-06',
            'guardian_name' => 'Parent Returning',
            'guardian_phone' => '0600000998',
            'guardian_email' => 'returning@example.com',
            'notes' => 'Previous year record',
        ]);

        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $this->getJson('/students/options?year_id=1')
            ->assertOk()
            ->assertJsonPath('previous_year_student.full_name', 'Returning Student')
            ->assertJsonPath('re_enrollment_student.guardian_name', 'Parent Returning')
            ->assertJsonPath('previous_year_students.0.full_name', 'Returning Student');
    }

    public function test_admin_can_re_enroll_a_previous_year_student_from_the_selection_id(): void
    {
        DB::table('students')->insert([
            'id' => 10,
            'school_id' => 1,
            'year_id' => 3,
            'room_id' => 1,
            'student_number' => 'QS-2025-010',
            'full_name' => 'Returning Student',
            'status' => 'paused',
            'enrollment_date' => '2025-09-10',
            'birth_date' => '2014-04-06',
            'guardian_name' => 'Parent Returning',
            'guardian_phone' => '0600000998',
            'guardian_email' => 'returning@example.com',
            'notes' => 'Previous year record',
        ]);

        DB::table('academic_years')->insert([
            'id' => 3,
            'school_id' => 1,
            'year_label' => '2025-2026',
            'start_date' => '2025-09-01',
            'end_date' => '2026-06-30',
            'is_current' => false,
            'is_active' => true,
        ]);

        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $this->postJson('/students', [
            'year_id' => 1,
            're_enrollment_student_id' => 10,
            'student_number' => '',
            'birth_date' => '',
            'guardian_name' => 'Custom Guardian',
            'guardian_phone' => '',
            'guardian_email' => '',
            'room_id' => '',
            'enrollment_date' => '2026-09-15',
            'status' => 'active',
            'full_name' => 'Returning Student',
        ])
            ->assertCreated()
            ->assertJsonPath('student.full_name', 'Returning Student')
            ->assertJsonPath('student.guardian_name', 'Custom Guardian')
            ->assertJsonPath('student.birth_date', '2014-04-06');
    }

    public function test_re_enrollment_selection_must_reference_a_valid_prior_year_student(): void
    {
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $this->postJson('/students', [
            'year_id' => 1,
            'full_name' => 'Invalid Re-enrollment',
            're_enrollment_student_id' => 999,
            'enrollment_date' => '2026-09-10',
            'status' => 'active',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('re_enrollment_student_id');
    }

    public function test_re_enrollment_defaults_to_active_status_for_the_new_year(): void
    {
        DB::table('academic_years')->insert([
            'id' => 3,
            'school_id' => 1,
            'year_label' => '2025-2026',
            'start_date' => '2025-09-01',
            'end_date' => '2026-06-30',
            'is_current' => false,
            'is_active' => true,
        ]);

        DB::table('students')->insert([
            'id' => 10,
            'school_id' => 1,
            'year_id' => 3,
            'room_id' => 1,
            'student_number' => 'QS-2025-010',
            'full_name' => 'Returning Student',
            'status' => 'paused',
            'enrollment_date' => '2025-09-10',
            'birth_date' => '2014-04-06',
            'guardian_name' => 'Parent Returning',
            'guardian_phone' => '0600000998',
            'guardian_email' => 'returning@example.com',
        ]);

        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $this->postJson('/students', [
            'year_id' => 1,
            're_enrollment_student_id' => 10,
            'full_name' => 'Returning Student',
            'enrollment_date' => '2026-09-15',
            'status' => 'active',
        ])
            ->assertCreated()
            ->assertJsonPath('student.status', 'active');
    }

    public function test_re_enrollment_allows_a_user_override_of_the_default_active_status(): void
    {
        DB::table('academic_years')->insert([
            'id' => 3,
            'school_id' => 1,
            'year_label' => '2025-2026',
            'start_date' => '2025-09-01',
            'end_date' => '2026-06-30',
            'is_current' => false,
            'is_active' => true,
        ]);

        DB::table('students')->insert([
            'id' => 10,
            'school_id' => 1,
            'year_id' => 3,
            'room_id' => 1,
            'student_number' => 'QS-2025-010',
            'full_name' => 'Returning Student',
            'status' => 'paused',
            'enrollment_date' => '2025-09-10',
            'birth_date' => '2014-04-06',
            'guardian_name' => 'Parent Returning',
            'guardian_phone' => '0600000998',
            'guardian_email' => 'returning@example.com',
        ]);

        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $this->postJson('/students', [
            'year_id' => 1,
            're_enrollment_student_id' => 10,
            'full_name' => 'Returning Student',
            'enrollment_date' => '2026-09-15',
            'status' => 'paused',
        ])
            ->assertCreated()
            ->assertJsonPath('student.status', 'paused');
    }

    public function test_re_enrollment_ignores_previous_year_room_ids_that_are_not_valid_for_the_current_year(): void
    {
        DB::table('academic_years')->insert([
            'id' => 3,
            'school_id' => 1,
            'year_label' => '2025-2026',
            'start_date' => '2025-09-01',
            'end_date' => '2026-06-30',
            'is_current' => false,
            'is_active' => true,
        ]);

        DB::table('students')->insert([
            'id' => 10,
            'school_id' => 1,
            'year_id' => 3,
            'room_id' => 999,
            'student_number' => 'QS-2025-010',
            'full_name' => 'Returning Student',
            'status' => 'active',
            'enrollment_date' => '2025-09-10',
            'birth_date' => '2014-04-06',
            'guardian_name' => 'Parent Returning',
            'guardian_phone' => '0600000998',
            'guardian_email' => 'returning@example.com',
        ]);

        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $this->postJson('/students', [
            'year_id' => 1,
            're_enrollment_student_id' => 10,
            'full_name' => 'Returning Student',
            'enrollment_date' => '2026-09-15',
            'status' => 'active',
        ])
            ->assertCreated()
            ->assertJsonPath('student.room_id', null);
    }

    public function test_only_immediately_previous_year_students_are_offered_for_re_enrollment(): void
    {
        DB::table('academic_years')->insert([
            'id' => 3,
            'school_id' => 1,
            'year_label' => '2025-2026',
            'start_date' => '2025-09-01',
            'end_date' => '2026-06-30',
            'is_current' => false,
            'is_active' => true,
        ]);

        DB::table('academic_years')->insert([
            'id' => 4,
            'school_id' => 1,
            'year_label' => '2024-2025',
            'start_date' => '2024-09-01',
            'end_date' => '2025-06-30',
            'is_current' => false,
            'is_active' => true,
        ]);

        DB::table('students')->insert([
            'id' => 10,
            'school_id' => 1,
            'year_id' => 3,
            'room_id' => 1,
            'student_number' => 'QS-2025-010',
            'full_name' => 'Returning Student',
            'status' => 'active',
            'enrollment_date' => '2025-09-01',
        ]);

        DB::table('students')->insert([
            'id' => 11,
            'school_id' => 1,
            'year_id' => 4,
            'room_id' => 1,
            'student_number' => 'QS-2024-009',
            'full_name' => 'Older Returning Student',
            'status' => 'active',
            'enrollment_date' => '2024-09-01',
        ]);

        DB::table('students')->insert([
            'id' => 12,
            'school_id' => 1,
            'year_id' => 4,
            'room_id' => 1,
            'student_number' => 'QS-2024-010',
            'full_name' => 'Withdrawn Previous Student',
            'status' => 'withdrawn',
            'enrollment_date' => '2024-09-02',
        ]);

        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $this->getJson('/students/options?year_id=1')
            ->assertOk()
            ->assertJsonPath('previous_year_students.0.full_name', 'Returning Student')
            ->assertJsonCount(1, 'previous_year_students');

        $this->postJson('/students', [
            'year_id' => 1,
            'full_name' => 'Withdrawn Student Attempt',
            're_enrollment_student_id' => 12,
            'enrollment_date' => '2026-09-10',
            'status' => 'active',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('re_enrollment_student_id');
    }

    public function test_re_enrollment_selection_must_reference_the_immediately_previous_year_when_selected_manually(): void
    {
        DB::table('academic_years')->insert([
            'id' => 3,
            'school_id' => 1,
            'year_label' => '2025-2026',
            'start_date' => '2025-09-01',
            'end_date' => '2026-06-30',
            'is_current' => false,
            'is_active' => true,
        ]);

        DB::table('academic_years')->insert([
            'id' => 4,
            'school_id' => 1,
            'year_label' => '2024-2025',
            'start_date' => '2024-09-01',
            'end_date' => '2025-06-30',
            'is_current' => false,
            'is_active' => true,
        ]);

        DB::table('students')->insert([
            'id' => 11,
            'school_id' => 1,
            'year_id' => 4,
            'room_id' => 1,
            'student_number' => 'QS-2024-009',
            'full_name' => 'Older Returning Student',
            'status' => 'active',
            'enrollment_date' => '2024-09-01',
        ]);

        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $this->postJson('/students', [
            'year_id' => 1,
            'full_name' => 'Manual Older Re-enrollment Attempt',
            're_enrollment_student_id' => 11,
            'enrollment_date' => '2026-09-10',
            'status' => 'active',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('re_enrollment_student_id');
    }

    public function test_student_number_must_be_unique_per_year_and_room_must_match_school_and_year(): void
    {
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $payload = [
            'year_id' => 1,
            'full_name' => 'Another Student',
            'student_number' => 'S-100',
            'enrollment_date' => '2026-09-10',
            'status' => 'active',
        ];

        $this->postJson('/students', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('student_number');

        $this->postJson('/students', [...$payload, 'student_number' => 'S-NEW', 'room_id' => 3])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('room_id');
    }

    public function test_student_forms_enforce_room_capacity_without_blocking_existing_placements(): void
    {
        DB::table('rooms')->where('id', 1)->update(['capacity' => 1]);
        DB::table('rooms')->where('id', 2)->update(['capacity' => 1]);
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $this->putJson('/students/1', [
            'year_id' => 1,
            'full_name' => 'Zayd Ali',
            'student_number' => 'S-100',
            'birth_date' => '2015-01-01',
            'guardian_name' => 'Ali',
            'guardian_phone' => '0600000001',
            'guardian_email' => 'ali@example.com',
            'room_id' => 1,
            'enrollment_date' => '2026-09-01',
            'status' => 'active',
        ])->assertOk();

        $payload = [
            'year_id' => 1,
            'full_name' => 'New Student',
            'student_number' => 'S-NEW',
            'room_id' => 2,
            'enrollment_date' => '2026-09-10',
            'status' => 'active',
        ];

        $this->postJson('/students', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('room_capacity');

        $this->putJson('/students/1', [...$payload, 'student_number' => 'S-100'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('room_capacity');

        $this->assertDatabaseMissing('students', ['student_number' => 'S-NEW']);
        $this->assertDatabaseHas('students', [
            'id' => 1,
            'full_name' => 'Zayd Ali',
            'room_id' => 1,
        ]);
    }

    public function test_closed_years_cannot_be_changed_and_teachers_cannot_submit_student_forms(): void
    {
        DB::table('academic_years')->insert([
            'id' => 3,
            'school_id' => 1,
            'year_label' => '2024-2025',
            'start_date' => '2024-09-01',
            'end_date' => '2025-06-30',
            'is_current' => false,
            'is_active' => false,
        ]);

        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $this->postJson('/students', [
            'year_id' => 3,
            'full_name' => 'Closed Year Student',
            'enrollment_date' => '2024-09-10',
            'status' => 'active',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('year_id');

        $this->actingAs(new GenericUser([
            'id' => 20,
            'school_id' => 1,
            'role' => 'teacher',
        ]));

        $this->postJson('/students', [
            'year_id' => 1,
            'full_name' => 'Unauthorized Student',
            'enrollment_date' => '2026-09-10',
            'status' => 'active',
        ])->assertForbidden();
    }

    public function test_teacher_student_detail_is_limited_to_assigned_students_and_teaching_fields(): void
    {
        DB::table('rooms')->insert([
            'id' => 4,
            'school_id' => 1,
            'year_id' => 1,
            'name' => 'Unassigned',
            'is_active' => true,
        ]);
        DB::table('students')->insert([
            'id' => 5,
            'school_id' => 1,
            'year_id' => 1,
            'room_id' => 4,
            'student_number' => 'S-104',
            'full_name' => 'Unassigned Student',
            'status' => 'active',
            'enrollment_date' => '2026-09-04',
        ]);

        $this->actingAs(new GenericUser([
            'id' => 20,
            'school_id' => 1,
            'role' => 'teacher',
        ]));

        $this->getJson('/students/1?year_id=1')
            ->assertOk()
            ->assertJsonPath('student.full_name', 'Zayd Ali')
            ->assertJsonPath('student.room_name', 'Alpha')
            ->assertJsonPath('student.is_read_only', true)
            ->assertJsonMissingPath('student.student_number')
            ->assertJsonMissingPath('student.status')
            ->assertJsonMissingPath('student.enrollment_date')
            ->assertJsonMissingPath('student.guardian_phone')
            ->assertJsonMissingPath('student.payment_summary')
            ->assertJsonCount(30, 'student.attendance_last_30_days')
            ->assertJsonCount(0, 'student.memorization_sessions');

        $this->getJson('/students/5?year_id=1')->assertNotFound();
    }

    public function test_student_profile_returns_year_scoped_attendance_memorization_and_payment_summaries(): void
    {
        $this->travelTo('2026-11-02 12:00:00');
        DB::table('attendance')->insert([
            ['student_id' => 1, 'school_id' => 1, 'room_id' => 1, 'year_id' => 1, 'attendance_date' => '2026-10-04', 'status' => 'late'],
            ['student_id' => 1, 'school_id' => 1, 'room_id' => 1, 'year_id' => 1, 'attendance_date' => '2026-11-02', 'status' => 'present'],
            ['student_id' => 1, 'school_id' => 1, 'room_id' => 1, 'year_id' => 1, 'attendance_date' => '2026-10-03', 'status' => 'absent'],
            ['student_id' => 2, 'school_id' => 1, 'room_id' => 2, 'year_id' => 1, 'attendance_date' => '2026-11-02', 'status' => 'absent'],
        ]);
        DB::table('memorization_sessions')->insert([
            [
                'id' => 1,
                'student_id' => 1,
                'school_id' => 1,
                'room_id' => 1,
                'teacher_id' => 20,
                'year_id' => 1,
                'session_date' => '2026-10-12',
                'surah_start' => 1,
                'ayah_start' => 1,
                'surah_end' => 1,
                'ayah_end' => 7,
                'portion_label' => 'Al-Fatiha',
                'evaluation' => 'excellent',
            ],
            [
                'id' => 2,
                'student_id' => 1,
                'school_id' => 1,
                'room_id' => 1,
                'teacher_id' => 20,
                'year_id' => 2,
                'session_date' => '2026-10-13',
                'surah_start' => 2,
                'ayah_start' => 1,
                'surah_end' => 2,
                'ayah_end' => 5,
                'portion_label' => 'Al-Baqarah',
                'evaluation' => 'good',
            ],
        ]);
        DB::table('payments')->insert([
            ['student_id' => 1, 'school_id' => 1, 'year_id' => 1, 'currency' => 'MAD', 'amount' => 75, 'status' => 'due'],
            ['student_id' => 1, 'school_id' => 1, 'year_id' => 1, 'currency' => 'MAD', 'amount' => 50, 'status' => 'late'],
            ['student_id' => 1, 'school_id' => 1, 'year_id' => 1, 'currency' => 'MAD', 'amount' => 100, 'status' => 'paid'],
            ['student_id' => 1, 'school_id' => 1, 'year_id' => 1, 'currency' => 'MAD', 'amount' => 25, 'status' => 'waived'],
            ['student_id' => 1, 'school_id' => 2, 'year_id' => 2, 'currency' => 'MAD', 'amount' => 500, 'status' => 'late'],
        ]);

        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $this->getJson('/students/1?year_id=1')
            ->assertOk()
            ->assertJsonCount(30, 'student.attendance_last_30_days')
            ->assertJsonPath('student.attendance_last_30_days.0.date', '2026-10-04')
            ->assertJsonPath('student.attendance_last_30_days.0.status', 'late')
            ->assertJsonPath('student.attendance_last_30_days.29.status', 'present')
            ->assertJsonCount(1, 'student.memorization_sessions')
            ->assertJsonPath('student.memorization_sessions.0.id', 1)
            ->assertJsonPath('student.payment_summary.0.currency', 'MAD')
            ->assertJsonPath('student.payment_summary.0.outstanding_amount', '125')
            ->assertJsonPath('student.payment_summary.0.due_amount', '75')
            ->assertJsonPath('student.payment_summary.0.late_amount', '50')
            ->assertJsonPath('student.payment_summary.0.waived_amount', '25')
            ->assertJsonPath('student.payment_summary.0.due_count', 1)
            ->assertJsonPath('student.payment_summary.0.late_count', 1)
            ->assertJsonPath('student.payment_summary.0.waived_count', 1);
    }

    public function test_admin_exports_a_student_progress_register_for_the_selected_year(): void
    {
        DB::table('academic_years')->insert([
            'id' => 3,
            'school_id' => 1,
            'year_label' => '2025-2026',
            'start_date' => '2025-09-01',
            'end_date' => '2026-06-30',
            'is_current' => false,
            'is_active' => false,
        ]);
        DB::table('memorization_sessions')->insert([
            'id' => 10,
            'student_id' => 1,
            'school_id' => 1,
            'room_id' => 1,
            'teacher_id' => 20,
            'year_id' => 1,
            'session_date' => '2026-10-12',
            'surah_start' => 1,
            'ayah_start' => 1,
            'surah_end' => 1,
            'ayah_end' => 7,
            'portion_label' => 'Al-Fatiha',
            'evaluation' => 'excellent',
            'notes' => 'Bonne récitation',
        ]);
        DB::table('memorization_sessions')->insert([
            'id' => 11,
            'student_id' => 1,
            'school_id' => 1,
            'room_id' => 1,
            'teacher_id' => 20,
            'year_id' => 3,
            'session_date' => '2026-10-13',
            'portion_label' => 'Other academic year',
            'evaluation' => 'good',
        ]);
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $response = $this->get('/students/1/progress-export?year_id=1&lang=fr');

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="student-progress-1.pdf"')
            ->assertHeader('X-Export-Record-Count', '1');
        $pdfContents = $response->getContent();
        $this->assertStringStartsWith('%PDF-', $pdfContents);
        $this->assertSame(
            1,
            preg_match('/\/MediaBox\s*\[\s*0\s+0\s+([\d.]+)\s+([\d.]+)\s*\]/', $pdfContents, $pageDimensions),
        );
        $this->assertEqualsWithDelta(595.28, (float) $pageDimensions[1], 0.1);
        $this->assertEqualsWithDelta(841.89, (float) $pageDimensions[2], 0.1);
        $this->assertDatabaseHas('audit_events', [
            'school_id' => 1,
            'year_id' => 1,
            'actor_user_id' => 10,
            'action_type' => 'exported',
            'entity_type' => 'student',
            'entity_id' => 1,
            'summary_key' => 'student.progress_exported',
        ]);
        $changes = json_decode(DB::table('audit_events')->value('changes'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('student_progress', $changes['page']);
        $this->assertSame(1, $changes['record_count']);
    }

    public function test_teacher_progress_register_export_is_limited_to_assigned_students(): void
    {
        $this->actingAs(new GenericUser([
            'id' => 20,
            'school_id' => 1,
            'role' => 'teacher',
        ]));

        $this->get('/students/1/progress-export?year_id=1&lang=ar')
            ->assertOk()
            ->assertHeader('X-Export-Record-Count', '0');

        $this->get('/students/4/progress-export?year_id=2&lang=fr')->assertNotFound();
        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_student_options_are_limited_to_active_rooms_in_the_selected_school_and_year(): void
    {
        DB::table('rooms')->insert([
            'id' => 4,
            'school_id' => 1,
            'year_id' => 1,
            'name' => 'Archived',
            'is_active' => false,
        ]);

        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $this->getJson('/students/options?year_id=1')
            ->assertOk()
            ->assertJsonPath('is_editable', true)
            ->assertJsonCount(2, 'rooms')
            ->assertJsonPath('rooms.0.name', 'Alpha')
            ->assertJsonPath('rooms.1.name', 'Beta');

        $this->getJson('/students/4?year_id=1')->assertNotFound();
    }

    public function test_update_cannot_move_a_student_to_another_academic_year(): void
    {
        DB::table('academic_years')->insert([
            'id' => 3,
            'school_id' => 1,
            'year_label' => '2025-2026',
            'start_date' => '2025-09-01',
            'end_date' => '2026-06-30',
            'is_current' => false,
            'is_active' => true,
        ]);

        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $this->putJson('/students/1', [
            'year_id' => 2,
            'full_name' => 'Cross-year Student',
            'enrollment_date' => '2026-09-01',
            'status' => 'active',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('year_id');

        $this->putJson('/students/1', [
            'year_id' => 3,
            'full_name' => 'Cross-year Student',
            'enrollment_date' => '2026-09-01',
            'status' => 'active',
        ])->assertNotFound();
    }
}
