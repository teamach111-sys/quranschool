<?php

namespace Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchoolSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->string('full_name');
        });
        DB::table('users')->insert(['id' => 10, 'school_id' => 1, 'full_name' => 'Admin User']);

        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('year_id')->nullable();
            $table->unsignedBigInteger('actor_user_id');
            $table->string('actor_name', 120);
            $table->string('action_type', 48);
            $table->string('entity_type', 64);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('summary_key', 100);
            $table->json('changes')->nullable();
            $table->string('reason', 255)->nullable();
            $table->string('request_id', 100)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->string('name_fr', 150);
            $table->string('name_ar', 150);
            $table->string('address', 255)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 190)->nullable();
            $table->char('currency', 3)->default('MAD');
            $table->decimal('monthly_fee', 10, 2)->nullable();
            $table->json('payment_methods')->nullable();
            $table->string('student_number_prefix', 20)->nullable();
            $table->boolean('student_number_use_dash')->default(true);
            $table->timestamps();
        });

        DB::table('schools')->insert([
            [
                'id' => 1,
                'name_fr' => 'École 1',
                'name_ar' => 'مدرسة ١',
                'address' => null,
                'phone' => null,
                'email' => null,
                'currency' => 'MAD',
            ],
            [
                'id' => 2,
                'name_fr' => 'École 2',
                'name_ar' => 'مدرسة ٢',
                'address' => null,
                'phone' => null,
                'email' => null,
                'currency' => 'MAD',
            ],
        ]);
    }

    public function test_admin_can_update_only_their_school_settings(): void
    {
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $this->getJson('/admin/school-settings')
            ->assertOk()
            ->assertJsonPath('school.name_fr', 'École 1');

        $this->putJson('/admin/school-settings', [
            'name_fr' => 'Nouvelle école',
            'name_ar' => 'مدرسة جديدة',
            'address' => '12 rue principale',
            'phone' => '+212 600 000 000',
            'email' => 'contact@example.test',
            'currency' => 'eur',
            'monthly_fee' => '125.50',
        ])
            ->assertOk()
            ->assertJsonPath('school.name_fr', 'Nouvelle école')
            ->assertJsonPath('school.currency', 'EUR')
            ->assertJsonPath('school.monthly_fee', '125.50');

        $this->assertDatabaseHas('schools', [
            'id' => 1,
            'name_fr' => 'Nouvelle école',
            'currency' => 'EUR',
            'monthly_fee' => '125.50',
        ]);
        $this->assertDatabaseHas('schools', [
            'id' => 2,
            'name_fr' => 'École 2',
            'currency' => 'MAD',
        ]);
        $event = DB::table('audit_events')->first();
        $this->assertSame('school.settings_updated', $event->summary_key);
        $changes = json_decode($event->changes, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('École 1', $changes['name_fr']['before']);
        $this->assertSame('Nouvelle école', $changes['name_fr']['after']);
    }

    public function test_non_admin_cannot_read_school_settings(): void
    {
        $this->actingAs(new GenericUser([
            'id' => 20,
            'school_id' => 1,
            'role' => 'teacher',
        ]));

        $this->getJson('/admin/school-settings')->assertForbidden();
    }

    public function test_admin_can_download_a_square_blank_receipt_template_for_their_school(): void
    {
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $response = $this->get('/admin/school-settings/receipt-template?lang=fr');

        $response->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('content-disposition', 'attachment; filename="payment-receipt-template.pdf"');
        $pdfContents = $response->getContent();
        $this->assertStringStartsWith('%PDF-', $pdfContents);
        $this->assertSame(
            1,
            preg_match('/\/MediaBox\s*\[\s*0\s+0\s+([\d.]+)\s+([\d.]+)\s*\]/', $pdfContents, $pageDimensions),
        );
        $this->assertEqualsWithDelta((float) $pageDimensions[1], (float) $pageDimensions[2], 0.01);
        $this->assertLessThan(500, (float) $pageDimensions[1]);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_non_admin_cannot_download_a_blank_receipt_template(): void
    {
        $this->actingAs(new GenericUser([
            'id' => 20,
            'school_id' => 1,
            'role' => 'teacher',
        ]));

        $this->get('/admin/school-settings/receipt-template')->assertForbidden();
    }

    public function test_omitted_optional_settings_are_preserved_and_audited_from_the_saved_state(): void
    {
        DB::table('schools')->where('id', 1)->update([
            'monthly_fee' => '75.00',
            'address' => 'Existing address',
        ]);
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $this->putJson('/admin/school-settings', [
            'name_fr' => 'École mise à jour',
            'name_ar' => 'مدرسة محدثة',
            'currency' => 'MAD',
        ])->assertOk();

        $this->assertDatabaseHas('schools', [
            'id' => 1,
            'address' => 'Existing address',
            'monthly_fee' => '75.00',
        ]);
        $changes = json_decode(
            DB::table('audit_events')->value('changes'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertArrayNotHasKey('monthly_fee', $changes);
        $this->assertArrayNotHasKey('address', $changes);
    }

    public function test_admin_cannot_save_a_negative_monthly_fee(): void
    {
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $this->putJson('/admin/school-settings', [
            'name_fr' => 'École 1',
            'name_ar' => 'مدرسة ١',
            'address' => null,
            'phone' => null,
            'email' => null,
            'currency' => 'MAD',
            'monthly_fee' => '-1.00',
        ])->assertUnprocessable()->assertJsonValidationErrors('monthly_fee');

        $this->assertDatabaseHas('schools', [
            'id' => 1,
            'monthly_fee' => null,
        ]);
    }

    public function test_admin_can_configure_school_payment_methods(): void
    {
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $this->getJson('/admin/school-settings')
            ->assertOk()
            ->assertJsonPath('school.payment_methods.0', 'Espèces')
            ->assertJsonPath('school.payment_methods.1', 'Virement bancaire');

        $this->putJson('/admin/school-settings', [
            'name_fr' => 'École 1',
            'name_ar' => 'مدرسة ١',
            'currency' => 'MAD',
            'payment_methods' => ['Espèces', 'Prélèvement automatique'],
        ])
            ->assertOk()
            ->assertJsonPath('school.payment_methods.1', 'Prélèvement automatique');

        $this->assertSame(
            ['Espèces', 'Prélèvement automatique'],
            json_decode(DB::table('schools')->where('id', 1)->value('payment_methods'), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function test_admin_cannot_save_an_empty_payment_method_list(): void
    {
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $this->putJson('/admin/school-settings', [
            'name_fr' => 'École 1',
            'name_ar' => 'مدرسة ١',
            'currency' => 'MAD',
            'payment_methods' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors('payment_methods');
    }

    public function test_admin_can_save_and_read_student_number_prefix(): void
    {
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $this->putJson('/admin/school-settings', [
            'name_fr' => 'École 1',
            'name_ar' => 'مدرسة ١',
            'currency' => 'MAD',
            'student_number_prefix' => 'N',
        ])
            ->assertOk()
            ->assertJsonPath('school.student_number_prefix', 'N');

        $this->assertDatabaseHas('schools', [
            'id' => 1,
            'student_number_prefix' => 'N',
        ]);
    }

    public function test_empty_student_number_prefix_is_stored_as_null(): void
    {
        DB::table('schools')->where('id', 1)->update(['student_number_prefix' => 'N']);
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $this->putJson('/admin/school-settings', [
            'name_fr' => 'École 1',
            'name_ar' => 'مدرسة ١',
            'currency' => 'MAD',
            'student_number_prefix' => '',
        ])
            ->assertOk()
            ->assertJsonPath('school.student_number_prefix', null);

        $this->assertDatabaseHas('schools', [
            'id' => 1,
            'student_number_prefix' => null,
        ]);
    }

    public function test_admin_can_disable_the_student_number_dash_separator(): void
    {
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $this->getJson('/admin/school-settings')
            ->assertOk()
            ->assertJsonPath('school.student_number_use_dash', true);

        $this->putJson('/admin/school-settings', [
            'name_fr' => 'École 1',
            'name_ar' => 'مدرسة ١',
            'currency' => 'MAD',
            'student_number_prefix' => 'N',
            'student_number_use_dash' => false,
        ])
            ->assertOk()
            ->assertJsonPath('school.student_number_use_dash', false);

        $this->assertDatabaseHas('schools', [
            'id' => 1,
            'student_number_use_dash' => 0,
        ]);
    }

    public function test_non_admin_cannot_export_data(): void
    {
        $this->actingAs(new GenericUser([
            'id' => 20,
            'school_id' => 1,
            'role' => 'teacher',
        ]));

        $this->getJson('/admin/school-settings/export')->assertForbidden();
    }

    public function test_admin_can_export_all_school_data_as_json(): void
    {
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $response = $this->get('/admin/school-settings/export');

        $response->assertOk()
            ->assertHeader('content-type', 'application/json; charset=utf-8');

        $decoded = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $decoded['version']);
        $this->assertSame(1, $decoded['school_id']);
        $this->assertArrayHasKey('schools', $decoded['data']);
        $this->assertArrayHasKey('audit_events', $decoded['data']);
        $this->assertCount(1, $decoded['data']['schools']);
        $this->assertSame('École 1', $decoded['data']['schools'][0]['name_fr']);
    }

    public function test_admin_can_import_data_from_a_valid_backup_file(): void
    {
        DB::table('schools')->where('id', 1)->update(['address' => 'Original address']);
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $backup = json_encode([
            'version' => 1,
            'exported_at' => now()->toIso8601String(),
            'school_id' => 1,
            'data' => [
                'schools' => [
                    ['id' => 1, 'name_fr' => 'Restored School', 'name_ar' => 'مدرسة مستعادة', 'address' => 'Restored address', 'phone' => null, 'email' => null, 'currency' => 'MAD', 'monthly_fee' => null, 'payment_methods' => null, 'student_number_prefix' => 'N', 'created_at' => now(), 'updated_at' => now()],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $response = $this->post('/admin/school-settings/import', [
            'backup_file' => UploadedFile::fake()->createWithContent('backup.json', $backup),
        ]);

        $response->assertOk()
            ->assertJsonPath('imported.schools', 1);

        $this->assertDatabaseHas('schools', [
            'id' => 1,
            'name_fr' => 'Restored School',
            'address' => 'Restored address',
            'student_number_prefix' => 'N',
        ]);
    }

    public function test_import_skips_generated_columns_and_tables_without_school_id(): void
    {
        Schema::create('academic_years', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->string('year_label', 20);
            $table->boolean('is_current')->default(false);
            $table->unsignedBigInteger('current_school_id')->nullable()->storedAs('case when is_current = 1 then school_id else null end');
            $table->timestamps();
        });

        Schema::create('password_recovery_keys', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->primary();
            $table->string('key_hash');
            $table->timestamp('created_at')->nullable();
        });

        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $backup = json_encode([
            'version' => 1,
            'exported_at' => now()->toIso8601String(),
            'school_id' => 1,
            'data' => [
                'schools' => [
                    ['id' => 1, 'name_fr' => 'École 1', 'name_ar' => 'مدرسة ١', 'currency' => 'MAD', 'student_number_prefix' => null, 'student_number_use_dash' => 1, 'created_at' => now(), 'updated_at' => now()],
                ],
                'academic_years' => [
                    ['id' => 5, 'school_id' => 1, 'year_label' => '2026-2027', 'is_current' => 1, 'current_school_id' => 1, 'created_at' => now(), 'updated_at' => now()],
                ],
                'password_recovery_keys' => [
                    ['user_id' => 10, 'key_hash' => 'hash-10', 'created_at' => now()],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->post('/admin/school-settings/import', [
            'backup_file' => UploadedFile::fake()->createWithContent('backup.json', $backup),
        ])
            ->assertOk()
            ->assertJsonPath('imported.academic_years', 1)
            ->assertJsonPath('imported.password_recovery_keys', 1);

        $this->assertDatabaseHas('academic_years', [
            'id' => 5,
            'current_school_id' => 1,
        ]);
        $this->assertDatabaseHas('password_recovery_keys', [
            'user_id' => 10,
            'key_hash' => 'hash-10',
        ]);
    }

    public function test_import_rejects_backup_from_a_different_school(): void
    {
        $this->actingAs(new GenericUser([
            'id' => 10,
            'school_id' => 1,
            'role' => 'admin',
        ]));

        $backup = json_encode([
            'version' => 1,
            'exported_at' => now()->toIso8601String(),
            'school_id' => 999,
            'data' => ['schools' => []],
        ], JSON_THROW_ON_ERROR);

        $this->post('/admin/school-settings/import', [
            'backup_file' => UploadedFile::fake()->createWithContent('backup.json', $backup),
        ])->assertUnprocessable();
    }

    public function test_non_admin_cannot_import_data(): void
    {
        $this->actingAs(new GenericUser([
            'id' => 20,
            'school_id' => 1,
            'role' => 'teacher',
        ]));

        $this->postJson('/admin/school-settings/import', [])->assertForbidden();
    }
}
