<?php

namespace App\Console\Commands;

use App\Services\MarketplaceProductSync;
use Illuminate\Console\Command;

class SyncMarketplaceProducts extends Command
{
    protected $signature = 'marketplace:sync-products
        {--platform=* : Platform yang disinkron: shopee, tiktok (default semua)}';

    protected $description = 'Tarik produk dari semua toko Shopee & TikTok, cocokkan SKU otomatis, lalu kirim stok';

    public function handle(MarketplaceProductSync $sync): int
    {
        $platforms = array_values(array_intersect(MarketplaceProductSync::PLATFORMS, $this->option('platform') ?: MarketplaceProductSync::PLATFORMS));
        $results = $sync->run($platforms);

        if ($results === []) {
            $this->info('Belum ada toko marketplace yang terhubung dan aktif.');
        }

        foreach ($results as $row) {
            $row['ok'] ? $this->line("{$row['shop']}: {$row['message']}") : $this->error("{$row['shop']}: {$row['message']}");
        }

        return collect($results)->contains('ok', false) ? self::FAILURE : self::SUCCESS;
    }
}
