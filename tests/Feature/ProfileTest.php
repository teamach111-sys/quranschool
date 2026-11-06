<?php

namespace Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedInteger('role_id');
            $table->string('full_name');
            $table->string('email')->unique();
            $table->string('password_hash');
            $table->string('phone')->nullable();
            $table->string('language');
            $table->boolean('is_active');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

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
    }

    public static function staffRoles(): array
    {
        return [
            'administrator' => ['admin'],
            'teacher' => ['teacher'],
            'secretary' => ['secretary'],
        ];
    }

    #[DataProvider('staffRoles')]
    public function test_staff_can_read_only_their_own_profile_without_password_hash(string $role): void
    {
        $this->createUser(10, 1, $role);
        $this->createUser(20, 2, 'admin');
        $this->actingAs($this->user(10, 1, $role));

        $response = $this->getJson('/profile');

        $response->assertOk()
            ->assertJsonPath('profile.id', 10)
            ->assertJsonPath('profile.full_name', 'Profile User')
            ->assertJsonPath('profile.email', 'profile-10@example.test')
            ->assertJsonPath('profile.phone', '+212600000001')
            ->assertJsonPath('profile.language', 'fr');
        $this->assertArrayNotHasKey('password_hash', $response->json('profile'));
        $this->assertSame(
            ['id', 'full_name', 'email', 'phone', 'language'],
            array_keys($response->json('profile')),
        );
    }

    public function test_profile_update_changes_only_name_phone_and_language(): void
    {
        $this->createUser(10, 1, 'teacher');
        $this->actingAs($this->user(10, 1, 'teacher'));

        $this->putJson('/profile', [
            'full_name' => 'Updated Profile User',
            'phone' => '+212600000099',
            'language' => 'ar',
            'email' => 'changed@example.test',
            'school_id' => 2,
            'role_id' => 1,
            'is_active' => false,
            'password_hash' => 'attacker-controlled-hash',
        ])
            ->assertOk()
            ->assertJsonPath('profile.full_name', 'Updated Profile User')
            ->assertJsonPath('profile.phone', '+212600000099')
            ->assertJsonPath('profile.language', 'ar')
            ->assertJsonPath('profile.email', 'profile-10@example.test');

        $this->assertDatabaseHas('users', [
            'id' => 10,
            'school_id' => 1,
            'role_id' => 2,
            'full_name' => 'Updated Profile User',
            'email' => 'profile-10@example.test',
            'phone' => '+212600000099',
            'language' => 'ar',
            'is_active' => true,
        ]);
        $event = DB::table('audit_events')->where('summary_key', 'user.profile_updated')->first();
        $this->assertNotNull($event);
        $changes = json_decode($event->changes, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Profile User', $changes['full_name']['before']);
        $this->assertSame('Updated Profile User', $changes['full_name']['after']);
        $this->assertTrue(Hash::check(
            'current-password-value',
            DB::table('users')->where('id', 10)->value('password_hash'),
        ));
    }

    public function test_profile_update_rejects_an_unsupported_language(): void
    {
        $this->createUser(10, 1, 'secretary');
        $this->actingAs($this->user(10, 1, 'secretary'));

        $this->putJson('/profile', [
            'full_name' => 'Updated Profile User',
            'phone' => null,
            'language' => 'en',
        ])->assertUnprocessable()->assertJsonValidationErrors('language');

        $this->assertDatabaseHas('users', [
            'id' => 10,
            'full_name' => 'Profile User',
            'language' => 'fr',
        ]);
    }

    public function test_staff_can_change_their_password_with_the_current_password(): void
    {
        $this->createUser(10, 1, 'admin');
        $this->actingAs($this->user(10, 1, 'admin'));

        $response = $this->putJson('/profile/password', [
            'current_password' => 'current-password-value',
            'password' => 'new-secure-password-value',
            'password_confirmation' => 'new-secure-password-value',
        ]);

        $response->assertOk()->assertJsonPath('updated', true);
        $this->assertTrue(Hash::check(
            'new-secure-password-value',
            DB::table('users')->where('id', 10)->value('password_hash'),
        ));
        $event = DB::table('audit_events')->first();
        $this->assertSame('user.password_changed', $event->summary_key);
        $this->assertSame([], json_decode($event->changes, true, 512, JSON_THROW_ON_ERROR));
        $response->assertJsonMissing(['password' => 'new-secure-password-value']);
    }

    public function test_password_change_rejects_an_incorrect_current_password_without_updating_hash(): void
    {
        $this->createUser(10, 1, 'teacher');
        $this->actingAs($this->user(10, 1, 'teacher'));
        $originalHash = DB::table('users')->where('id', 10)->value('password_hash');

        $this->putJson('/profile/password', [
            'current_password' => 'incorrect-current-password',
            'password' => 'new-secure-password-value',
            'password_confirmation' => 'new-secure-password-value',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->assertSame($originalHash, DB::table('users')->where('id', 10)->value('password_hash'));
    }

    public function test_password_change_requires_a_long_confirmed_password(): void
    {
        $this->createUser(10, 1, 'secretary');
        $this->actingAs($this->user(10, 1, 'secretary'));
        $originalHash = DB::table('users')->where('id', 10)->value('password_hash');

        $this->putJson('/profile/password', [
            'current_password' => 'current-password-value',
            'password' => 'short',
            'password_confirmation' => 'different',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->assertSame($originalHash, DB::table('users')->where('id', 10)->value('password_hash'));
    }

    public function test_password_change_rejects_reusing_the_current_password(): void
    {
        $this->createUser(10, 1, 'admin');
        $this->actingAs($this->user(10, 1, 'admin'));
        $originalHash = DB::table('users')->where('id', 10)->value('password_hash');

        $this->putJson('/profile/password', [
            'current_password' => 'current-password-value',
            'password' => 'current-password-value',
            'password_confirmation' => 'current-password-value',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->assertSame($originalHash, DB::table('users')->where('id', 10)->value('password_hash'));
    }

    public function test_profile_read_requires_authentication(): void
    {
        $this->getJson('/profile')->assertUnauthorized();
    }

    public function test_profile_update_requires_authentication(): void
    {
        $this->putJson('/profile', [])->assertUnauthorized();
    }

    public function test_password_update_requires_authentication(): void
    {
        $this->putJson('/profile/password', [])->assertUnauthorized();
    }

    private function createUser(int $id, int $schoolId, string $role): void
    {
        DB::table('users')->insert([
            'id' => $id,
            'school_id' => $schoolId,
            'role_id' => match ($role) {
                'admin' => 1,
                'teacher' => 2,
                'secretary' => 3,
            },
            'full_name' => 'Profile User',
            'email' => "profile-{$id}@example.test",
            'password_hash' => Hash::make('current-password-value'),
            'phone' => '+212600000001',
            'language' => 'fr',
            'is_active' => true,
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
