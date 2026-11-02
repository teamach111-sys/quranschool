<?php

namespace Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MemorizationSessionFormTest extends TestCase
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
            $table->string('status')->default('active');
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
            $table->string('portion_label', 160);
            $table->string('evaluation');
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function test_teacher_creates_session_for_assigned_room_with_teacher_id_from_session(): void
    {
        $this->seedMemorizationData();
        $this->actingAs($this->user(20, 1, 'teacher'));

        $this->postJson('/memorization-sessions', [
            ...$this->validSession(),
            'surah_start' => 2,
            'ayah_start' => 280,
            'surah_end' => 3,
            'ayah_end' => 5,
            'portion_label' => 'End of Al-Baqarah through start of Al-Imran',
        ])
            ->assertCreated()
            ->assertJsonStructure(['session_id']);

        $this->assertDatabaseHas('memorization_sessions', [
            'student_id' => 1,
            'school_id' => 1,
            'room_id' => 1,
            'teacher_id' => 20,
            'year_id' => 1,
            'session_date' => '2026-10-10',
            'surah_start' => 2,
            'ayah_start' => 280,
            'surah_end' => 3,
            'ayah_end' => 5,
            'evaluation' => 'excellent',
        ]);
    }

    public function test_admin_creates_session_for_any_school_teacher(): void
    {
        $this->seedMemorizationData();
        $this->actingAs($this->user(10, 1, 'admin'));

        $this->postJson('/memorization-sessions', [
            ...$this->validSession(),
            'student_id' => 3,
            'room_id' => 2,
            'teacher_id' => 21,
        ])->assertCreated();

        $this->assertDatabaseHas('memorization_sessions', [
            'student_id' => 3,
            'room_id' => 2,
            'teacher_id' => 21,
            'school_id' => 1,
            'year_id' => 1,
        ]);
    }

    public function test_teacher_cannot_create_a_session_for_an_unassigned_room(): void
    {
        $this->seedMemorizationData();
        $this->actingAs($this->user(20, 1, 'teacher'));

        $this->postJson('/memorization-sessions', [
            ...$this->validSession(),
            'student_id' => 3,
            'room_id' => 2,
        ])->assertForbidden();

        $this->assertDatabaseCount('memorization_sessions', 3);
    }

    public function test_teacher_cannot_create_a_session_in_an_archived_room(): void
    {
        $this->seedMemorizationData();
        $this->actingAs($this->user(20, 1, 'teacher'));

        $this->postJson('/memorization-sessions', [
            ...$this->validSession(),
            'room_id' => 3,
        ])->assertNotFound();

        $this->assertDatabaseCount('memorization_sessions', 3);
    }

    public function test_teacher_updates_own_session(): void
    {
        $this->seedMemorizationData();
        $this->actingAs($this->user(20, 1, 'teacher'));

        $this->putJson('/memorization-sessions/1', [
            ...$this->validSession(),
            'portion_label' => 'Updated portion',
            'evaluation' => 'needs_review',
            'notes' => 'Review again next class',
        ])
            ->assertOk()
            ->assertJsonPath('session_id', 1);

        $this->assertDatabaseHas('memorization_sessions', [
            'id' => 1,
            'teacher_id' => 20,
            'portion_label' => 'Updated portion',
            'evaluation' => 'needs_review',
            'notes' => 'Review again next class',
        ]);
    }

    public function test_teacher_cannot_update_another_teachers_session(): void
    {
        $this->seedMemorizationData();
        $this->actingAs($this->user(20, 1, 'teacher'));

        $this->putJson('/memorization-sessions/2', [
            ...$this->validSession(),
            'student_id' => 3,
            'room_id' => 2,
        ])->assertForbidden();

        $this->assertDatabaseHas('memorization_sessions', [
            'id' => 2,
            'teacher_id' => 21,
            'portion_label' => 'Original other teacher session',
        ]);
    }

    public function test_admin_updates_a_session_from_the_same_school(): void
    {
        $this->seedMemorizationData();
        $this->actingAs($this->user(10, 1, 'admin'));

        $this->putJson('/memorization-sessions/2', [
            ...$this->validSession(),
            'student_id' => 3,
            'room_id' => 2,
            'teacher_id' => 20,
            'portion_label' => 'Admin correction',
        ])->assertOk();

        $this->assertDatabaseHas('memorization_sessions', [
            'id' => 2,
            'teacher_id' => 20,
            'portion_label' => 'Admin correction',
        ]);
    }

    public function test_memorization_range_returns_422_for_surah_above_limit(): void
    {
        $this->seedMemorizationData();
        $this->actingAs($this->user(20, 1, 'teacher'));
        $payload = $this->validSession();

        $this->postJson('/memorization-sessions', [
            ...$payload,
            'surah_start' => 115,
            'ayah_start' => 1,
            'surah_end' => 115,
            'ayah_end' => 2,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('surah_start');

        $this->assertDatabaseCount('memorization_sessions', 3);
    }

    public function test_memorization_range_returns_422_when_start_ayah_exceeds_its_surah_count(): void
    {
        $this->seedMemorizationData();
        $this->actingAs($this->user(20, 1, 'teacher'));
        $surah = $this->surahMetadata(4);
        $invalidAyah = $surah['ayah_count'] + 1;

        $this->postJson('/memorization-sessions', [
            ...$this->validSession(),
            'surah_start' => 4,
            'ayah_start' => $invalidAyah,
            'surah_end' => 4,
            'ayah_end' => 1,
        ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.ayah_start.0',
                "The start ayah exceeds the selected surah's limit of {$surah['ayah_count']}.",
            );

        $this->assertDatabaseCount('memorization_sessions', 3);
    }

    public function test_memorization_range_returns_422_when_end_ayah_exceeds_its_surah_count(): void
    {
        $this->seedMemorizationData();
        $this->actingAs($this->user(20, 1, 'teacher'));
        $surah = $this->surahMetadata(3);
        $invalidAyah = $surah['ayah_count'] + 1;

        $this->postJson('/memorization-sessions', [
            ...$this->validSession(),
            'surah_start' => 2,
            'ayah_start' => 1,
            'surah_end' => 3,
            'ayah_end' => $invalidAyah,
        ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.ayah_end.0',
                "The end ayah exceeds the selected surah's limit of {$surah['ayah_count']}.",
            );

        $this->assertDatabaseCount('memorization_sessions', 3);
    }

    public function test_memorization_range_returns_422_when_end_precedes_start(): void
    {
        $this->seedMemorizationData();
        $this->actingAs($this->user(20, 1, 'teacher'));

        $this->postJson('/memorization-sessions', [
            ...$this->validSession(),
            'surah_start' => 2,
            'ayah_start' => 10,
            'surah_end' => 2,
            'ayah_end' => 9,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('surah_end');

        $this->assertDatabaseCount('memorization_sessions', 3);
    }

    public function test_memorization_range_returns_422_for_incomplete_endpoint(): void
    {
        $this->seedMemorizationData();
        $this->actingAs($this->user(20, 1, 'teacher'));

        $this->postJson('/memorization-sessions', [
            ...$this->validSession(),
            'surah_start' => 2,
            'ayah_start' => null,
            'surah_end' => 2,
            'ayah_end' => 5,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('surah_start');

        $this->assertDatabaseCount('memorization_sessions', 3);
    }

    public function test_session_write_returns_422_for_date_outside_year(): void
    {
        $this->seedMemorizationData();
        $this->actingAs($this->user(20, 1, 'teacher'));

        $this->postJson('/memorization-sessions', [
            ...$this->validSession(),
            'session_date' => '2027-07-01',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('session_date');

        $this->assertDatabaseCount('memorization_sessions', 3);
    }

    public function test_session_write_returns_422_for_invalid_evaluation(): void
    {
        $this->seedMemorizationData();
        $this->actingAs($this->user(20, 1, 'teacher'));

        $this->postJson('/memorization-sessions', [
            ...$this->validSession(),
            'evaluation' => 'unknown',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('evaluation');

        $this->assertDatabaseCount('memorization_sessions', 3);
    }

    public function test_session_write_returns_422_for_closed_year(): void
    {
        $this->seedMemorizationData();

        $this->actingAs($this->user(10, 1, 'admin'));
        $this->postJson('/memorization-sessions', [
            ...$this->validSession(),
            'year_id' => 2,
            'session_date' => '2025-10-10',
            'teacher_id' => 20,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('year_id');

        $this->assertDatabaseCount('memorization_sessions', 3);
    }

    public function test_session_write_succeeds_for_an_open_noncurrent_year(): void
    {
        $this->seedMemorizationData();
        DB::table('academic_years')->where('id', 2)->update(['is_active' => true]);
        DB::table('rooms')->insert([
            'id' => 5,
            'school_id' => 1,
            'year_id' => 2,
            'name' => 'Historical Room',
            'is_active' => true,
        ]);
        DB::table('room_teachers')->insert([
            'room_id' => 5,
            'teacher_id' => 20,
            'school_id' => 1,
            'year_id' => 2,
            'assigned_from' => '2025-09-01',
            'assigned_until' => null,
        ]);
        DB::table('students')->insert([
            'id' => 5,
            'school_id' => 1,
            'year_id' => 2,
            'room_id' => 5,
            'student_number' => 'S-005',
            'full_name' => 'Historical Student',
            'status' => 'active',
        ]);
        $this->actingAs($this->user(10, 1, 'admin'));

        $response = $this->postJson('/memorization-sessions', [
            ...$this->validSession(),
            'year_id' => 2,
            'student_id' => 5,
            'room_id' => 5,
            'session_date' => '2025-10-10',
            'teacher_id' => 20,
        ]);

        $response->assertCreated()->assertJsonStructure(['session_id']);
        $this->assertDatabaseHas('memorization_sessions', [
            'id' => $response->json('session_id'),
            'student_id' => 5,
            'year_id' => 2,
            'session_date' => '2025-10-10',
        ]);
    }

    public function test_session_write_returns_403_for_secretary(): void
    {
        $this->seedMemorizationData();
        $this->actingAs($this->user(12, 1, 'secretary'));
        $this->postJson('/memorization-sessions', $this->validSession())->assertForbidden();

        $this->assertDatabaseCount('memorization_sessions', 3);
    }

    public function test_teacher_cannot_override_teacher_id_in_session_payload(): void
    {
        $this->seedMemorizationData();
        $this->actingAs($this->user(20, 1, 'teacher'));

        $this->postJson('/memorization-sessions', [
            ...$this->validSession(),
            'teacher_id' => 21,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('teacher_id');

        $this->assertDatabaseCount('memorization_sessions', 3);
    }

    public function test_writer_cannot_update_a_session_from_another_school(): void
    {
        $this->seedMemorizationData();
        $this->actingAs($this->user(10, 1, 'admin'));

        $this->putJson('/memorization-sessions/3', [
            ...$this->validSession(),
            'teacher_id' => 21,
        ])->assertNotFound();

        $this->assertDatabaseHas('memorization_sessions', [
            'id' => 3,
            'school_id' => 2,
            'portion_label' => 'Other school session',
        ]);
    }

    public function test_session_write_requires_authentication(): void
    {
        $this->postJson('/memorization-sessions', [])->assertUnauthorized();
    }

    public function test_teacher_progress_contains_only_the_selected_students_ranges(): void
    {
        $this->seedMemorizationData();
        $this->travelTo('2026-10-10');
        DB::table('memorization_sessions')->insert([
            'id' => 4,
            'student_id' => 1,
            'school_id' => 1,
            'room_id' => 1,
            'teacher_id' => 20,
            'year_id' => 1,
            'session_date' => '2026-10-11',
            'surah_start' => 1,
            'ayah_start' => 5,
            'surah_end' => 2,
            'ayah_end' => 2,
            'portion_label' => 'Second session',
            'evaluation' => 'good',
            'notes' => null,
        ]);
        $this->actingAs($this->user(20, 1, 'teacher'));

        $this->getJson('/memorization-sessions/progress?year_id=1&student_id=1')
            ->assertOk()
            ->assertJsonPath('student_id', 1)
            ->assertJsonCount(2, 'sessions')
            ->assertJsonPath('sessions.0.id', 1)
            ->assertJsonPath('sessions.1.id', 4)
            ->assertJsonPath('sessions.1.surah_end', 2)
            ->assertJsonPath('sessions.1.ayah_end', 2);
    }

    public function test_teacher_cannot_read_progress_for_an_unassigned_room(): void
    {
        $this->seedMemorizationData();
        $this->actingAs($this->user(20, 1, 'teacher'));

        $this->getJson('/memorization-sessions/progress?year_id=1&student_id=3')
            ->assertForbidden();
    }

    public function test_progress_requires_authentication(): void
    {
        $this->getJson('/memorization-sessions/progress?year_id=1&student_id=1')
            ->assertUnauthorized();
    }

    public function test_secretary_cannot_read_memorization_progress(): void
    {
        $this->seedMemorizationData();
        $this->actingAs($this->user(12, 1, 'secretary'));

        $this->getJson('/memorization-sessions/progress?year_id=1&student_id=1')
            ->assertForbidden();
    }

    public function test_progress_does_not_disclose_students_from_another_school(): void
    {
        $this->seedMemorizationData();
        $this->actingAs($this->user(10, 1, 'admin'));

        $this->getJson('/memorization-sessions/progress?year_id=1&student_id=4')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('student_id');
    }

    /**
     * @return array<string, int|string|null>
     */
    private function validSession(): array
    {
        return [
            'year_id' => 1,
            'student_id' => 1,
            'room_id' => 1,
            'session_date' => '2026-10-10',
            'surah_start' => 1,
            'ayah_start' => 1,
            'surah_end' => 1,
            'ayah_end' => 5,
            'portion_label' => 'Al-Fatiha, verses 1-5',
            'evaluation' => 'excellent',
            'notes' => null,
        ];
    }

    /**
     * @return array{id: int, name: string, name_ar: string, name_fr: string, ayah_count: int, revelation: string, juz_start: int}
     */
    private function surahMetadata(int $surahId): array
    {
        $metadata = json_decode(
            file_get_contents(resource_path('data/quran-metadata.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return collect($metadata['surahs'])->firstWhere('id', $surahId);
    }

    private function seedMemorizationData(): void
    {
        DB::table('academic_years')->insert([
            ['id' => 1, 'school_id' => 1, 'year_label' => '2026-2027', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'is_current' => true, 'is_active' => true],
            ['id' => 2, 'school_id' => 1, 'year_label' => '2025-2026', 'start_date' => '2025-09-01', 'end_date' => '2026-06-30', 'is_current' => false, 'is_active' => false],
            ['id' => 3, 'school_id' => 2, 'year_label' => '2026-2027', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'is_current' => true, 'is_active' => true],
        ]);
        DB::table('roles')->insert([
            ['id' => 1, 'role_key' => 'admin'],
            ['id' => 2, 'role_key' => 'teacher'],
            ['id' => 3, 'role_key' => 'secretary'],
        ]);
        DB::table('users')->insert([
            ['id' => 10, 'school_id' => 1, 'role_id' => 1, 'full_name' => 'Admin'],
            ['id' => 12, 'school_id' => 1, 'role_id' => 3, 'full_name' => 'Secretary'],
            ['id' => 20, 'school_id' => 1, 'role_id' => 2, 'full_name' => 'Teacher One'],
            ['id' => 21, 'school_id' => 1, 'role_id' => 2, 'full_name' => 'Teacher Two'],
            ['id' => 30, 'school_id' => 2, 'role_id' => 2, 'full_name' => 'Other School Teacher'],
        ]);
        DB::table('rooms')->insert([
            ['id' => 1, 'school_id' => 1, 'year_id' => 1, 'name' => 'Alpha', 'is_active' => true],
            ['id' => 2, 'school_id' => 1, 'year_id' => 1, 'name' => 'Beta', 'is_active' => true],
            ['id' => 3, 'school_id' => 1, 'year_id' => 1, 'name' => 'Archived', 'is_active' => false],
            ['id' => 4, 'school_id' => 2, 'year_id' => 3, 'name' => 'Other school', 'is_active' => true],
        ]);
        DB::table('room_teachers')->insert([
            ['room_id' => 1, 'teacher_id' => 20, 'school_id' => 1, 'year_id' => 1, 'assigned_from' => '2026-09-01', 'assigned_until' => null],
            ['room_id' => 2, 'teacher_id' => 21, 'school_id' => 1, 'year_id' => 1, 'assigned_from' => '2026-09-01', 'assigned_until' => null],
        ]);
        DB::table('students')->insert([
            ['id' => 1, 'school_id' => 1, 'year_id' => 1, 'room_id' => 1, 'student_number' => 'S-001', 'full_name' => 'Zayd Ali', 'status' => 'active'],
            ['id' => 2, 'school_id' => 1, 'year_id' => 1, 'room_id' => 1, 'student_number' => 'S-002', 'full_name' => 'Mariam Ali', 'status' => 'paused'],
            ['id' => 3, 'school_id' => 1, 'year_id' => 1, 'room_id' => 2, 'student_number' => 'S-003', 'full_name' => 'Amina Ali', 'status' => 'active'],
            ['id' => 4, 'school_id' => 2, 'year_id' => 3, 'room_id' => 4, 'student_number' => 'S-004', 'full_name' => 'Other Student', 'status' => 'active'],
        ]);
        DB::table('memorization_sessions')->insert([
            [
                'id' => 1,
                'student_id' => 1,
                'school_id' => 1,
                'room_id' => 1,
                'teacher_id' => 20,
                'year_id' => 1,
                'session_date' => '2026-10-10',
                'surah_start' => 1,
                'ayah_start' => 1,
                'surah_end' => 1,
                'ayah_end' => 5,
                'portion_label' => 'Original own session',
                'evaluation' => 'good',
                'notes' => null,
            ],
            [
                'id' => 2,
                'student_id' => 3,
                'school_id' => 1,
                'room_id' => 2,
                'teacher_id' => 21,
                'year_id' => 1,
                'session_date' => '2026-10-10',
                'surah_start' => 2,
                'ayah_start' => 1,
                'surah_end' => 2,
                'ayah_end' => 5,
                'portion_label' => 'Original other teacher session',
                'evaluation' => 'good',
                'notes' => null,
            ],
            [
                'id' => 3,
                'student_id' => 4,
                'school_id' => 2,
                'room_id' => 4,
                'teacher_id' => 30,
                'year_id' => 3,
                'session_date' => '2026-10-10',
                'surah_start' => null,
                'ayah_start' => null,
                'surah_end' => null,
                'ayah_end' => null,
                'portion_label' => 'Other school session',
                'evaluation' => 'good',
                'notes' => null,
            ],
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
