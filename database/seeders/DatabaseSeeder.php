<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([PermissionSeeder::class, CourierSeeder::class]);

        $admin = User::firstOrCreate(
            ['username' => 'superadmin'],
            [
                'name' => 'Super Admin',
                'email' => 'superadmin@wms.local',
                'password' => 'password',
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );

        $admin->assignRole(config('permissions.super_admin_role'));
    }
}
