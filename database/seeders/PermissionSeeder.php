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
                    'products.view', 'products.create', 'products.edit', 'products.delete',
                    'couriers.view', 'couriers.create', 'couriers.edit', 'couriers.delete',
                    'inventory.view', 'inventory.create', 'inventory.adjust',
                    'suppliers.view', 'suppliers.create', 'suppliers.edit', 'suppliers.delete',
                    'receipts.view', 'receipts.create', 'receipts.receive', 'receipts.delete',
                    'sales.view', 'sales.create', 'sales.confirm', 'sales.fulfill', 'sales.cancel', 'sales.delete',
                    'returns.view', 'returns.create', 'returns.receive', 'returns.delete',
                    'opname.view', 'opname.create', 'opname.post', 'opname.delete', 'reports.view',
                    'activity.view',
                ]);
        }

        if (! Role::where('name', 'Staff Gudang')->exists()) {
            Role::create(['name' => 'Staff Gudang', 'guard_name' => 'web'])
                ->syncPermissions(['dashboard.view', 'warehouses.view', 'products.view', 'couriers.view', 'inventory.view', 'inventory.create',
                    'suppliers.view', 'receipts.view', 'receipts.create', 'receipts.receive',
                    'sales.view', 'sales.fulfill', 'returns.view', 'returns.create', 'returns.receive', 'opname.view', 'opname.create']);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
