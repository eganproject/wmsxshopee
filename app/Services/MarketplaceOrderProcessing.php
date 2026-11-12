<?php

namespace App\Services;

use App\Models\MarketplaceStockHold;
use App\Models\SalesOrder;
use App\Models\ShopeeShop;
use App\Models\TiktokShop;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Saklar "Proses pesanan di Optima" per toko marketplace (hanya Super Admin).
 *
 * Mati = pesanan toko masih diproses di sistem lain (cth. Ginee) sampai alamat
 * gudang di marketplace dipindah. Selama mati Optima tidak menarik pesanan & retur
 * toko itu, tidak mencadangkan / mengurangi stok untuknya, dan kirim stok ikut mati.
 * Pesanan toko itu yang belum dikirim di Optima dihapus (tidak ada stok yang keluar);
 * pesanan yang sudah Dikirim tetap tersimpan sebagai riwayat. Yang dikirim otomatis
 * (paket sebenarnya dikemas lewat sistem lain) ditandai sudah dicek supaya tidak
 * muncul sebagai "Terlewat QC".
 *
 * Nyala lagi = mulai memproses di Optima: sinkron berikutnya menarik pesanan 3 hari
 * terakhir yang di marketplace masih menunggu dikirim. Pesanan yang sudah dikirim
 * dari sistem lain tidak ikut masuk, dan retur lama tidak ditarik.
 */
class MarketplaceOrderProcessing
{
    /** Pesanan toko ini yang belum dikirim di Optima (akan dihapus saat saklar dimatikan). */
    public static function openOrders(string $platform, ShopeeShop|TiktokShop $shop)
    {
        return SalesOrder::withoutGlobalScope('warehouse_access')
            ->where($platform === 'shopee' ? 'shopee_shop_id' : 'tiktok_shop_id', $shop->id)
            ->whereIn('status', ['draft', ...SalesOrder::RESERVING]);
    }

    public const QC_NOTE = 'Diproses di sistem lain, tidak lewat QC Optima.';

    /** Pesanan toko ini yang dikirim otomatis dan belum dicek: ditandai sudah dicek. */
    public static function clearSkippedQc(string $platform, int $shopId, ?int $userId = null): int
    {
        return SalesOrder::withoutGlobalScope('warehouse_access')
            ->where($platform === 'shopee' ? 'shopee_shop_id' : 'tiktok_shop_id', $shopId)
            ->qcSkipped('unchecked')
            ->update(['qc_skip_checked_at' => now(), 'qc_skip_checked_by' => $userId, 'qc_skip_note' => self::QC_NOTE]);
    }

    /** @return int jumlah pesanan yang dihapus */
    public function disable(string $platform, ShopeeShop|TiktokShop $shop, ?int $userId = null): int
    {
        $deleted = DB::transaction(function () use ($platform, $shop, $userId) {
            $shop->update(['process_orders' => false, 'stock_push' => false]);

            $orders = self::openOrders($platform, $shop)->lockForUpdate()->get();
            $orders->each->delete();

            self::clearSkippedQc($platform, $shop->id, $userId);
            MarketplaceStockHold::where('platform', $platform)->where('shop_id', $shop->id)->delete();

            return $orders->count();
        });

        Cache::forget("{$platform}:order-retry:{$shop->id}");
        app(MarketplaceStockPush::class)->queue();

        return $deleted;
    }

    public function enable(ShopeeShop|TiktokShop $shop): void
    {
        $shop->update([
            'process_orders' => true,
            // Mulai seperti toko baru: hanya pesanan yang masih harus dikirim yang dibuat.
            'orders_synced_until' => null,
            'returns_synced_until' => now(),
        ]);
    }
}
