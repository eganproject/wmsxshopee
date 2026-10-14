<?php

namespace App\Console\Commands;

use App\Models\TiktokShop;
use App\Services\Tiktok\TiktokAuth;
use App\Services\Tiktok\TiktokException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RefreshTiktokTokens extends Command
{
    protected $signature = 'tiktok:refresh-tokens {--force : Perbarui semua toko walau token masih lama}';

    protected $description = 'Perbarui access token toko TikTok Shop yang hampir kedaluwarsa';

    public function handle(TiktokAuth $auth): int
    {
        $within = $this->option('force') ? null : config('tiktok.refresh_before_minutes');
        $shops = TiktokShop::where('status', 'connected')->where('is_active', true)->get();
        $failed = 0;

        foreach ($shops as $shop) {
            try {
                $refreshed = $auth->refresh($shop, onlyIfExpiringWithin: $within);
                $this->line(($refreshed ? 'Diperbarui: ' : 'Masih berlaku: ').$shop->displayName());
            } catch (TiktokException $e) {
                $failed++;
                $this->error("Gagal: {$shop->displayName()} - {$e->getMessage()}");
                Log::warning('TikTok token refresh gagal', ['shop_id' => $shop->shop_id, 'error' => $e->getMessage()]);
            }
        }

        if ($shops->isEmpty()) {
            $this->info('Belum ada toko TikTok yang terhubung.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
