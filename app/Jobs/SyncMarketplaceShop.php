<?php

namespace App\Jobs;

use App\Models\MarketplaceSyncLog;
use App\Models\ShopeeShop;
use App\Models\TiktokShop;
use App\Services\Shopee\ShopeeException;
use App\Services\Shopee\ShopeeSync;
use App\Services\Tiktok\TiktokException;
use App\Services\Tiktok\TiktokSync;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Sinkron terjadwal satu toko (pesanan / retur) lewat antrean.
 * Tiap toko punya job sendiri: toko yang lambat atau gagal tidak menahan toko
 * lain, dan job yang sama tidak menumpuk selama yang sebelumnya belum selesai.
 */
class SyncMarketplaceShop implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Gagal tidak diulang di sini: jadwal berikutnya menarik ulang dari titik terakhir. */
    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 900;

    /** @param  list<string>  $parts */
    public function __construct(public readonly string $platform, public readonly int $shopId, public readonly array $parts) {}

    public function uniqueId(): string
    {
        return "{$this->platform}:{$this->shopId}:".implode(',', $this->parts);
    }

    public function handle(ShopeeSync $shopee, TiktokSync $tiktok): void
    {
        $shop = ($this->platform === 'shopee' ? ShopeeShop::class : TiktokShop::class)::find($this->shopId);

        if (! $shop?->isSyncable()) {
            return;
        }

        try {
            MarketplaceSyncLog::as('jadwal', fn () => $this->platform === 'shopee' ? $shopee->run($shop, $this->parts) : $tiktok->run($shop, $this->parts));
        } catch (ShopeeException|TiktokException $e) {
            // Sudah tercatat di riwayat sinkron & last_error toko.
            Log::warning('Sinkron toko marketplace gagal', ['platform' => $this->platform, 'shop_id' => $shop->shop_id, 'error' => $e->getMessage()]);
        }
    }
}
