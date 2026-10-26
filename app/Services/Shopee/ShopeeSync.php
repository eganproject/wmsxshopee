<?php

namespace App\Services\Shopee;

use App\Models\MarketplaceSyncLog;
use App\Models\ShopeeShop;

/** Sinkron lengkap satu toko: pesanan & resi, retur, lalu stok. */
class ShopeeSync
{
    public const PARTS = ['orders', 'returns', 'stock'];

    public function __construct(
        private readonly ShopeeOrderSync $orders,
        private readonly ShopeeReturnSync $returns,
        private readonly ShopeeStockSync $stock,
    ) {}

    /**
     * @param  list<string>  $parts
     * @return array<string, array<string, mixed>>
     */
    public function run(ShopeeShop $shop, array $parts = self::PARTS): array
    {
        if (! $shop->isSyncable()) {
            throw new ShopeeException("Toko {$shop->displayName()} belum bisa disinkron: pastikan terhubung, aktif dan punya gudang pengirim.");
        }

        return MarketplaceSyncLog::track('shopee', $shop, implode(',', $parts), fn () => $this->runParts($shop, $parts), function (array $result) {
            $failed = count($result['stock']['failed'] ?? []);

            return [$failed ? 'failed' : 'success', static::summary($result)];
        });
    }

    /**
     * @param  list<string>  $parts
     * @return array<string, array<string, mixed>>
     */
    private function runParts(ShopeeShop $shop, array $parts): array
    {
        $api = ShopeeShopApi::for($shop);
        $result = [];

        try {
            // Pesanan dulu supaya cadangan stok terbaru ikut terhitung saat stok dikirim.
            if (in_array('orders', $parts, true)) {
                $result['orders'] = $this->orders->sync($shop, $api);
            }
            if (in_array('returns', $parts, true)) {
                $result['returns'] = $this->returns->sync($shop, $api);
            }
            if (in_array('stock', $parts, true)) {
                $result['stock'] = $this->stock->push($shop, $api);
            }
        } catch (ShopeeException $e) {
            $shop->update(['last_error' => mb_substr($e->getMessage(), 0, 500), 'status' => $e->needsReauthorization() ? 'expired' : $shop->status]);

            throw $e;
        }

        // Sinkron berhasil: error lama (cth. putus koneksi sesaat) tidak relevan lagi.
        // Kegagalan kirim stok di putaran ini tetap ditampilkan.
        $stockFailed = ! empty($result['stock']['failed'] ?? []);
        $keepStockError = ! isset($result['stock']) && str_starts_with((string) $shop->last_error, 'Kirim stok:');

        $shop->update([
            'last_synced_at' => now(),
            ...($stockFailed || $keepStockError ? [] : ['last_error' => null]),
        ]);

        return $result;
    }

    /** Ringkasan hasil untuk pesan di layar / console. */
    public static function summary(array $result): string
    {
        $parts = [];

        if (isset($result['orders'])) {
            $o = $result['orders'];
            $parts[] = "pesanan: {$o['created']} baru, {$o['updated']} diperbarui, {$o['cancelled']} batal".($o['pending'] ? ", {$o['pending']} perlu dicek" : '').(($o['failed'] ?? 0) ? ", {$o['failed']} gagal (dicoba lagi di sinkron berikutnya)" : '');
        }
        if (isset($result['returns'])) {
            $parts[] = "retur: {$result['returns']['created']} baru";
        }
        if (isset($result['stock'])) {
            $s = $result['stock'];
            $parts[] = ($s['disabled'] ?? false) ? 'stok: tidak dikirim (kirim stok toko ini dimatikan)' : "stok: {$s['models']} SKU dikirim".($s['failed'] ? ', '.count($s['failed']).' gagal' : '');
        }

        return ucfirst(implode('; ', $parts)).'.';
    }
}
