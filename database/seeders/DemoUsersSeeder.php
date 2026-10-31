<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class DemoUsersSeeder extends Seeder
{
    public function run(): void
    {
        // Prevent accidental creation of privileged demo accounts in production.
        if (
            app()->environment('production')
            && ! config('demo.allow_user_seeding')
        ) {
            throw new RuntimeException(
                'Demo user seeding is disabled in production.'
            );
        }

        $accounts = [
            [
                'key' => 'admin',
                'name' => 'VinCheck Demo Admin',
                'role' => 'admin',
            ],
            [
                'key' => 'super_admin',
                'name' => 'VinCheck Demo Super Admin',
                'role' => 'super_admin',
            ],
        ];

        foreach ($accounts as $account) {
            $email = config("demo.{$account['key']}.email");
            $password = config("demo.{$account['key']}.password");

            if (
                ! is_string($email)
                || trim($email) === ''
                || ! is_string($password)
                || $password === ''
            ) {
                throw new RuntimeException(
                    "Missing demo credentials for {$account['key']}. "
                    .'Set the required DEMO_* variables in .env.'
                );
            }

            $attributes = [
                'name' => $account['name'],
                'password' => Hash::make($password),
                'role' => $account['role'],
                'email_verified_at' => now(),
            ];

            // Keep admin accounts enabled when this column exists.
            if (Schema::hasColumn('users', 'is_active')) {
                $attributes['is_active'] = true;
            }

            User::updateOrCreate(
                ['email' => strtolower(trim($email))],
                $attributes,
            );
        }
    }
}
