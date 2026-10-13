<?php

namespace App\Console\Commands;

use App\Models\ShopeeShop;
use App\Services\Shopee\ShopeeAuth;
use App\Services\Shopee\ShopeeException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RefreshShopeeTokens extends Command
{
    protected $signature = 'shopee:refresh-tokens {--force : Perbarui semua toko walau token masih lama}';

    protected $description = 'Perbarui access token toko Shopee yang hampir kedaluwarsa';

    public function handle(ShopeeAuth $auth): int
    {
        $within = $this->option('force') ? null : config('shopee.refresh_before_minutes');
        $shops = ShopeeShop::where('status', 'connected')->where('is_active', true)->get();
        $failed = 0;

        foreach ($shops as $shop) {
            try {
                $refreshed = $auth->refresh($shop, onlyIfExpiringWithin: $within);
                $this->line(($refreshed ? 'Diperbarui: ' : 'Masih berlaku: ').$shop->displayName());
            } catch (ShopeeException $e) {
                $failed++;
                $this->error("Gagal: {$shop->displayName()} - {$e->getMessage()}");
                Log::warning('Shopee token refresh gagal', ['shop_id' => $shop->shop_id, 'error' => $e->getMessage()]);
            }
        }

        if ($shops->isEmpty()) {
            $this->info('Belum ada toko Shopee yang terhubung.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
