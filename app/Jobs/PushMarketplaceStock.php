<?php

namespace App\Jobs;

use App\Models\MarketplaceSyncLog;
use App\Services\MarketplaceStockPush;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Kirim stok yang berubah ke semua toko. Unik sampai mulai diproses: perubahan
 * beruntun hanya menghasilkan satu job, dan perubahan saat job berjalan
 * menjadwalkan job berikutnya.
 */
class PushMarketplaceStock implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 300;

    public function handle(MarketplaceStockPush $push): void
    {
        MarketplaceSyncLog::as('otomatis', fn () => $push->run(onlyChanged: true));
    }
}
