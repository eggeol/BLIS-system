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
        User::updateOrCreate([
            'email' => 'student@example.com',
        ], [
            'name' => 'Student User',
            'role' => User::ROLE_STUDENT,
            'password' => Hash::make('Student123!'),
        ]);

        User::updateOrCreate([
            'email' => 'teacher@example.com',
        ], [
            'name' => 'Teacher User',
            'role' => User::ROLE_STAFF_MASTER_EXAMINER,
            'password' => Hash::make('Teacher123!'),
        ]);

        User::updateOrCreate([
            'email' => 'admin@example.com',
        ], [
            'name' => 'Admin User',
            'role' => User::ROLE_ADMIN,
            'password' => Hash::make('Admin123!'),
        ]);
    }
}
