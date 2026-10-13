<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Batas gudang untuk user yang sedang login. Dipakai global scope
 * ScopedToWarehouse sehingga daftar, dropdown, laporan & dashboard otomatis
 * hanya berisi data gudang yang boleh diakses.
 */
class WarehouseAccess
{
    /**
     * ID gudang yang boleh diakses user login, atau null kalau tanpa batas
     * (Super Admin, user "semua gudang", atau tanpa login seperti console).
     *
     * @return list<int>|null
     */
    public static function ids(): ?array
    {
        $user = Auth::user();

        return $user instanceof User ? $user->accessibleWarehouseIds() : null;
    }
}
