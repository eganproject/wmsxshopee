<?php

namespace App\Http\Controllers\Mobile;

use App\Models\Warehouse;
use App\Support\MobileApp;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Aplikasi Android bekerja per gudang: gudang aktif dipilih di beranda dan
 * disimpan di session, dibatasi gudang yang boleh diakses user.
 */
trait MobileContext
{
    /** @return Collection<int, Warehouse> */
    protected function mobileWarehouses(): Collection
    {
        return MobileApp::warehouses();
    }

    protected function mobileWarehouse(Request $request): ?Warehouse
    {
        return MobileApp::warehouse($request);
    }

    protected function mobileWarehouseId(Request $request): int
    {
        $warehouse = $this->mobileWarehouse($request);
        abort_unless($warehouse, 403, 'Akun ini belum punya akses ke gudang mana pun.');

        return $warehouse->id;
    }
}
