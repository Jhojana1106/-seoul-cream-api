<?php

namespace Database\Seeders;

use App\Enums\SystemRole;
use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Super Admin',
                'phone' => null,
                'document_number' => '00000000',
                'password' => 'password',
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        $admin->syncRoles([SystemRole::SuperAdmin->value]);
    }
}
