<?php

namespace App\Console\Commands;

use App\Models\MarketplaceSyncLog;
use App\Services\MarketplaceStockPush;
use Illuminate\Console\Command;

class PushMarketplaceStockCommand extends Command
{
    protected $signature = 'marketplace:push-stock {--all : Kirim ulang semua SKU, bukan hanya yang berubah}';

    protected $description = 'Kirim stok jual ke semua toko Shopee & TikTok (default hanya SKU yang berubah)';

    public function handle(MarketplaceStockPush $push): int
    {
        $results = MarketplaceSyncLog::as('jadwal', fn () => $push->run(onlyChanged: ! $this->option('all')));
        $failed = false;

        foreach ($results as $row) {
            $failed = $failed || $row['failed'] !== [];
            $this->line("{$row['shop']}: {$row['sent']} SKU dikirim".($row['failed'] ? ', '.count($row['failed']).' gagal' : ''));
        }

        if ($results === []) {
            $this->info('Belum ada toko marketplace yang siap disinkron.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
