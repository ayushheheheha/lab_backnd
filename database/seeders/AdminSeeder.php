<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@labbygenziitian.com'],
            [
                'name' => 'LAB Admin',
                'password' => bcrypt('Admin@123'),
                'is_admin' => true,
                'email_verified_at' => now(),
                'is_active' => true,
            ]
        );
    }
}
