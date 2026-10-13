<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Sinkronisasi permission dari config/permissions.php ke database.
 * Aman dijalankan berulang kali.
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (config('permissions.modules') as $module => $definition) {
            foreach (array_keys($definition['actions']) as $action) {
                Permission::findOrCreate("{$module}.{$action}", 'web');
            }
        }

        // Super Admin otomatis memiliki semua akses (lihat AppServiceProvider).
        Role::findOrCreate(config('permissions.super_admin_role'), 'web');

        if (! Role::where('name', 'Admin')->exists()) {
            Role::create(['name' => 'Admin', 'guard_name' => 'web'])
                ->syncPermissions([
                    'dashboard.view', 'users.view', 'users.create', 'users.edit', 'roles.view',
                    'warehouses.view', 'warehouses.create', 'warehouses.edit', 'warehouses.delete',
                    'couriers.view', 'couriers.create', 'couriers.edit', 'couriers.delete',
                ]);
        }

        if (! Role::where('name', 'Staff Gudang')->exists()) {
            Role::create(['name' => 'Staff Gudang', 'guard_name' => 'web'])
                ->syncPermissions(['dashboard.view', 'warehouses.view', 'couriers.view']);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
