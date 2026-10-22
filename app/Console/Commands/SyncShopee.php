<?php

namespace App\Console\Commands;

use App\Jobs\SyncMarketplaceShop;
use App\Models\MarketplaceSyncLog;
use App\Models\ShopeeShop;
use App\Services\Shopee\ShopeeException;
use App\Services\Shopee\ShopeeSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncShopee extends Command
{
    protected $signature = 'shopee:sync
        {--only=* : Bagian yang disinkron: orders, returns, stock (default semua)}
        {--queue : Masukkan ke antrean, satu job per toko (dipakai penjadwal)}
        {--shop= : ID toko Shopee (shop_id) tertentu}';

    protected $description = 'Tarik pesanan & retur Shopee lalu kirim stok tersedia ke Shopee';

    public function handle(ShopeeSync $sync): int
    {
        $parts = array_values(array_intersect(ShopeeSync::PARTS, $this->option('only') ?: ShopeeSync::PARTS));
        $shops = ShopeeShop::where('status', 'connected')->where('is_active', true)->whereNotNull('warehouse_id')
            ->when($this->option('shop'), fn ($q, $id) => $q->where('shop_id', $id))
            ->get();
        $failed = 0;

        if ($this->option('queue')) {
            foreach ($shops as $shop) {
                SyncMarketplaceShop::dispatch('shopee', $shop->id, $parts);
            }
            $this->info($shops->count().' toko dimasukkan ke antrean.');

            return self::SUCCESS;
        }

        foreach ($shops as $shop) {
            try {
                $this->line($shop->displayName().': '.ShopeeSync::summary(MarketplaceSyncLog::as('jadwal', fn () => $sync->run($shop, $parts))));
            } catch (ShopeeException $e) {
                $failed++;
                $this->error("{$shop->displayName()}: {$e->getMessage()}");
                Log::warning('Shopee sync gagal', ['shop_id' => $shop->shop_id, 'error' => $e->getMessage()]);
            }
        }

        if ($shops->isEmpty()) {
            $this->info('Belum ada toko Shopee yang siap disinkron.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
