<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash; // <-- Import Hash facade

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $userData = [
            'name' => 'admin',
            'email' => 'canteen@admin.com',
            'password' => Hash::make('Canteen@admin123'), // Hash here directly
            'role' => 'admin'
        ];

        // If user doesn't already exist, create
        if (!User::where('email', $userData['email'])->exists()) {
            User::create($userData);
        }
    }
}
