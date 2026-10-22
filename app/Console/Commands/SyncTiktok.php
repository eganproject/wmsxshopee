<?php

namespace App\Console\Commands;

use App\Jobs\SyncMarketplaceShop;
use App\Models\MarketplaceSyncLog;
use App\Models\TiktokShop;
use App\Services\Tiktok\TiktokException;
use App\Services\Tiktok\TiktokSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncTiktok extends Command
{
    protected $signature = 'tiktok:sync
        {--only=* : Bagian yang disinkron: orders, returns, stock (default semua)}
        {--queue : Masukkan ke antrean, satu job per toko (dipakai penjadwal)}
        {--shop= : ID toko TikTok (shop_id) tertentu}';

    protected $description = 'Tarik pesanan & retur TikTok Shop lalu kirim stok tersedia ke TikTok';

    public function handle(TiktokSync $sync): int
    {
        $parts = array_values(array_intersect(TiktokSync::PARTS, $this->option('only') ?: TiktokSync::PARTS));
        $shops = TiktokShop::where('status', 'connected')->where('is_active', true)->whereNotNull('warehouse_id')
            ->when($this->option('shop'), fn ($q, $id) => $q->where('shop_id', $id))
            ->get();
        $failed = 0;

        if ($this->option('queue')) {
            foreach ($shops as $shop) {
                SyncMarketplaceShop::dispatch('tiktok', $shop->id, $parts);
            }
            $this->info($shops->count().' toko dimasukkan ke antrean.');

            return self::SUCCESS;
        }

        foreach ($shops as $shop) {
            try {
                $this->line($shop->displayName().': '.TiktokSync::summary(MarketplaceSyncLog::as('jadwal', fn () => $sync->run($shop, $parts))));
            } catch (TiktokException $e) {
                $failed++;
                $this->error("{$shop->displayName()}: {$e->getMessage()}");
                Log::warning('TikTok sync gagal', ['shop_id' => $shop->shop_id, 'error' => $e->getMessage()]);
            }
        }

        if ($shops->isEmpty()) {
            $this->info('Belum ada toko TikTok yang siap disinkron.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
