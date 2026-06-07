<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $adminExists = User::where('email', 'admin@interviewai.com')->exists();

        if (!$adminExists) {
            User::create([
                'name' => 'Super Admin',
                'email' => 'admin@interviewai.com',
                'password' => Hash::make('Admin@123456'),
                'role' => 'admin',
                'is_active' => true,
                'is_verified' => true,
                'email_verified_at' => now(),
                'verified_at' => now(),
            ]);

            $this->command->info('✅ Admin user created successfully!');
            $this->command->info('📧 Email: admin@interviewai.com');
            $this->command->info('🔑 Password: Admin@123456');
        } else {
            $this->command->warn('⚠️ Admin user already exists!');
        }
    }
}
