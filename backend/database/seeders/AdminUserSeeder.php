<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        AdminUser::updateOrCreate(
            ['email' => 'admin@equinova.test'],
            [
                'name' => 'EquiNova Owner',
                'password' => Hash::make('password'),
                'role' => 'owner',
                'active' => true,
                'email_verified_at' => now(),
            ],
        );

        AdminUser::updateOrCreate(
            ['email' => 'editor@equinova.test'],
            [
                'name' => 'Content Editor',
                'password' => Hash::make('password'),
                'role' => 'content_editor',
                'active' => true,
                'email_verified_at' => now(),
            ],
        );
    }
}
