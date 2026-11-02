<?php

namespace Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchoolSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->string('name_fr', 150);
            $table->string('name_ar', 150);
            $table->string('address', 255)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 190)->nullable();
            $table->char('currency', 3)->default('MAD');
            $table->decimal('monthly_fee', 10, 2)->nullable();
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
}
