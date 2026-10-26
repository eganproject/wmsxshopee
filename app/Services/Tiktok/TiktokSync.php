<?php

namespace App\Services\Tiktok;

use App\Models\MarketplaceSyncLog;
use App\Models\TiktokShop;

/** Sinkron lengkap satu toko TikTok: pesanan, retur, lalu stok. */
class TiktokSync
{
    public const PARTS = ['orders', 'returns', 'stock'];

    public function __construct(
        private readonly TiktokOrderSync $orders,
        private readonly TiktokReturnSync $returns,
        private readonly TiktokStockSync $stock,
    ) {}

    /**
     * @param  list<string>  $parts
     * @return array<string, array<string, mixed>>
     */
    public function run(TiktokShop $shop, array $parts = self::PARTS): array
    {
        if (! $shop->isSyncable()) {
            throw new TiktokException("Toko {$shop->displayName()} belum bisa disinkron: pastikan terhubung, aktif dan punya gudang pengirim.");
        }

        return MarketplaceSyncLog::track('tiktok', $shop, implode(',', $parts), fn () => $this->runParts($shop, $parts), function (array $result) {
            $failed = count($result['stock']['failed'] ?? []);

            return [$failed ? 'failed' : 'success', static::summary($result)];
        });
    }

    /**
     * @param  list<string>  $parts
     * @return array<string, array<string, mixed>>
     */
    private function runParts(TiktokShop $shop, array $parts): array
    {
        $api = TiktokShopApi::for($shop);
        $result = [];

        try {
            if (in_array('orders', $parts, true)) {
                $result['orders'] = $this->orders->sync($shop, $api);
            }
            if (in_array('returns', $parts, true)) {
                $result['returns'] = $this->returns->sync($shop, $api);
            }
            if (in_array('stock', $parts, true)) {
                $result['stock'] = $this->stock->push($shop, $api);
            }
        } catch (TiktokException $e) {
            $shop->update(['last_error' => mb_substr($e->getMessage(), 0, 500), 'status' => $e->needsReauthorization() ? 'expired' : $shop->status]);

            throw $e;
        }

        $shop->update(['last_synced_at' => now()]);

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
            $parts[] = ($s['disabled'] ?? false) ? 'stok: tidak dikirim (kirim stok toko ini dimatikan)' : "stok: {$s['skus']} SKU dikirim".($s['failed'] ? ', '.count($s['failed']).' gagal' : '');
        }

        return ucfirst(implode('; ', $parts)).'.';
    }
}
