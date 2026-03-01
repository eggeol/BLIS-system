<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $defaultPassword = Hash::make('pass');

        for ($index = 0; $index < 12; $index++) {
            $email = $index === 0
                ? 'student@example.com'
                : "student{$index}@example.com";

            $name = $index === 0
                ? 'Student User'
                : "Student User {$index}";

            User::updateOrCreate([
                'email' => $email,
            ], [
                'name' => $name,
                'student_id' => (string) (2301290 + $index),
                'role' => User::ROLE_STUDENT,
                'is_active' => true,
                'password' => $defaultPassword,
            ]);
        }

        for ($index = 0; $index < 3; $index++) {
            $email = $index === 0
                ? 'teacher@example.com'
                : "teacher{$index}@example.com";

            $name = $index === 0
                ? 'Teacher User'
                : "Teacher User {$index}";

            User::updateOrCreate([
                'email' => $email,
            ], [
                'name' => $name,
                'student_id' => null,
                'role' => User::ROLE_STAFF_MASTER_EXAMINER,
                'is_active' => true,
                'password' => $defaultPassword,
            ]);
        }

        User::updateOrCreate([
            'email' => 'admin@example.com',
        ], [
            'name' => 'Admin User',
            'student_id' => null,
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
            'password' => $defaultPassword,
        ]);
    }
}
