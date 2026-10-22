<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;

/** Baris pemetaan SKU mana yang perlu dikirim stoknya saat kirim stok otomatis. */
class StockPushFilter
{
    /** SKU yang baru gagal ditunggu dulu selama ini sebelum dicoba lagi (kirim penuh tetap mencoba). */
    public const RETRY_FAILED_AFTER_MINUTES = 10;

    public static function changed(Model $row, int $stock): bool
    {
        if ($row->stock_pushed === null) {
            return true;
        }

        if ((int) $row->stock_pushed === $stock) {
            return false;
        }

        // Stok berubah tapi kiriman terakhir gagal: jangan ulang tiap menit.
        return blank($row->stock_error) || $row->updated_at === null || $row->updated_at->lt(now()->subMinutes(self::RETRY_FAILED_AFTER_MINUTES));
    }
}
