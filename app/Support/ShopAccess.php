<?php

namespace App\Support;

use App\Models\ShopeeShop;
use App\Models\TiktokShop;
use Illuminate\Database\Eloquent\Builder;

/**
 * Batas toko marketplace untuk user login. Toko ikut gudang pengirimnya
 * (global scope ScopedToWarehouse), jadi user yang hanya punya akses gudang
 * Surabaya hanya melihat toko bergudang Surabaya. Kelas ini dipakai untuk
 * data yang menyimpan referensi toko tanpa gudang, seperti riwayat sinkron
 * dan push marketplace.
 */
class ShopAccess
{
    /**
     * Batasi query (kolom platform + id toko) ke toko yang boleh dilihat.
     * Tanpa batas untuk Super Admin, user "semua gudang" dan console.
     */
    public static function restrict(Builder $query, string $platformColumn = 'platform', string $shopColumn = 'shop_ref'): Builder
    {
        if (WarehouseAccess::ids() === null) {
            return $query;
        }

        $shopee = ShopeeShop::query()->pluck('id')->all();
        $tiktok = TiktokShop::query()->pluck('id')->all();

        return $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $q) => $q->where($platformColumn, 'shopee')->whereIn($shopColumn, $shopee))
            ->orWhere(fn (Builder $q) => $q->where($platformColumn, 'tiktok')->whereIn($shopColumn, $tiktok)));
    }
}
