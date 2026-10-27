<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Hak "inventory.adjust" (catat penyesuaian langsung) diganti modul
 * Penyesuaian Stok. Pemegang hak lama mendapat hak lihat, buat & setujui
 * sehingga tetap bisa menyesuaikan stok; Staff Gudang boleh mengajukan.
 */
return new class extends Migration
{
    private const NEW = ['adjustments.view', 'adjustments.create', 'adjustments.approve'];

    public function up(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        foreach (self::NEW as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $old = Permission::where('name', 'inventory.adjust')->where('guard_name', 'web')->first();

        if ($old) {
            Role::whereHas('permissions', fn ($q) => $q->whereKey($old->id))->get()
                ->each(fn (Role $role) => $role->givePermissionTo(self::NEW));

            $userModel = config('auth.providers.users.model');
            $userModel::whereHas('permissions', fn ($q) => $q->whereKey($old->id))->get()
                ->each(fn ($user) => $user->givePermissionTo(self::NEW));

            $old->delete();
        }

        Role::where('name', 'Staff Gudang')->where('guard_name', 'web')->first()
            ?->givePermissionTo(['adjustments.view', 'adjustments.create']);

        $registrar->forgetCachedPermissions();
    }

    public function down(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        $old = Permission::findOrCreate('inventory.adjust', 'web');

        Role::whereHas('permissions', fn ($q) => $q->where('name', 'adjustments.approve'))->get()
            ->each(fn (Role $role) => $role->givePermissionTo($old));

        Permission::whereIn('name', self::NEW)->where('guard_name', 'web')->delete();

        $registrar->forgetCachedPermissions();
    }
};
