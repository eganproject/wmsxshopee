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
    /** Permission bawaan role standar saat role dibuat. */
    private const ROLE_DEFAULTS = [
        'Admin' => [
            'dashboard.view', 'users.view', 'users.create', 'users.edit', 'roles.view',
            'warehouses.view', 'warehouses.create', 'warehouses.edit', 'warehouses.delete',
            'products.view', 'products.create', 'products.edit', 'products.delete',
            'couriers.view', 'couriers.create', 'couriers.edit', 'couriers.delete',
            'inventory.view', 'inventory.create',
            'adjustments.view', 'adjustments.create', 'adjustments.approve',
            'suppliers.view', 'suppliers.create', 'suppliers.edit', 'suppliers.delete',
            'receipts.view', 'receipts.create', 'receipts.receive', 'receipts.delete',
            'sales.view', 'sales.create', 'sales.confirm', 'sales.fulfill', 'sales.skip_scan', 'sales.cancel', 'sales.delete',
            'returns.view', 'returns.create', 'returns.receive', 'returns.delete',
            'damaged.view', 'damaged.move', 'damaged.return', 'damaged.dispose', 'damaged.repair', 'damaged.assemble', 'damaged.approve',
            'opname.view', 'opname.create', 'opname.post', 'opname.delete', 'reports.view',
            'activity.view', 'shopee.view', 'shopee.mapping', 'shopee.sync',
            'tiktok.view', 'tiktok.mapping', 'tiktok.sync',
        ],
        'Staff Gudang' => [
            'dashboard.view', 'warehouses.view', 'products.view', 'couriers.view', 'inventory.view', 'inventory.create',
            'adjustments.view', 'adjustments.create',
            'suppliers.view', 'receipts.view', 'receipts.create', 'receipts.receive',
            'sales.view', 'sales.fulfill', 'returns.view', 'returns.create', 'returns.receive',
            'damaged.view', 'damaged.move', 'opname.view', 'opname.create',
        ],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $new = [];

        foreach (config('permissions.modules') as $module => $definition) {
            foreach (array_keys($definition['actions']) as $action) {
                $permission = Permission::findOrCreate("{$module}.{$action}", 'web');

                if ($permission->wasRecentlyCreated) {
                    $new[] = $permission->name;
                }
            }
        }

        // Super Admin otomatis memiliki semua akses (lihat AppServiceProvider).
        Role::findOrCreate(config('permissions.super_admin_role'), 'web');

        foreach (self::ROLE_DEFAULTS as $name => $permissions) {
            $role = Role::where('name', $name)->first();

            if (! $role) {
                Role::create(['name' => $name, 'guard_name' => 'web'])->syncPermissions($permissions);
            } elseif ($added = array_values(array_intersect($permissions, $new))) {
                // Role yang sudah ada hanya diberi permission baru dari fitur baru;
                // permission lama yang sengaja dicabut admin tidak dikembalikan.
                $role->givePermissionTo($added);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
