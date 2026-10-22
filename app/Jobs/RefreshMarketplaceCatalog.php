<?php

namespace App\Jobs;

use App\Models\MarketplaceSyncLog;
use App\Models\ShopeeShop;
use App\Models\TiktokShop;
use App\Services\MarketplaceProductSync;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Tarik ulang produk satu toko setelah marketplace mengabarkan produk dibuat /
 * berubah, cocokkan SKU otomatis, lalu kirim stok. Dijeda & unik per toko:
 * banyak kabar produk beruntun cukup diproses sekali.
 */
class RefreshMarketplaceCatalog implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public const DELAY_SECONDS = 120;

    public int $tries = 1;

    public int $uniqueFor = 900;

    public function __construct(public readonly string $platform, public readonly int $shopId) {}

    public static function later(string $platform, int $shopId): void
    {
        static::dispatch($platform, $shopId)->delay(now()->addSeconds(self::DELAY_SECONDS));
    }

    public function uniqueId(): string
    {
        return "{$this->platform}:{$this->shopId}";
    }

    public function handle(MarketplaceProductSync $sync): void
    {
        $shop = ($this->platform === 'shopee' ? ShopeeShop::class : TiktokShop::class)::find($this->shopId);

        if ($shop && $shop->is_active && $shop->isConnected()) {
            MarketplaceSyncLog::as('push', fn () => $sync->syncShop($this->platform, $shop));
        }
    }
}
