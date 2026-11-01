<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Test staff accounts, one per dashboard role.
     */
    public function run(): void
    {
        $accounts = [
            ['name' => 'Admin', 'email' => 'admin@example.com', 'role' => 'admin'],
            ['name' => 'Enseignant Test', 'email' => 'teacher@example.com', 'role' => 'teacher'],
            ['name' => 'Secrétariat Test', 'email' => 'secretary@example.com', 'role' => 'secretary'],
        ];

        $now = now();

        foreach ($accounts as $account) {
            $exists = DB::table('users')->where('email', $account['email'])->first();

            if ($exists) {
                DB::table('users')->where('email', $account['email'])->update([
                    'name' => $account['name'],
                    'role' => $account['role'],
                    'password' => Hash::make('password'),
                    'updated_at' => $now,
                ]);
            } else {
                DB::table('users')->insert([
                    'name' => $account['name'],
                    'email' => $account['email'],
                    'role' => $account['role'],
                    'password' => Hash::make('password'),
                    'email_verified_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }
}
