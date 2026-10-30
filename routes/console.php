<?php

use App\Models\MarketplaceStockHold;
use App\Models\MarketplaceSyncLog;
use App\Models\MarketplaceWebhookEvent;
use App\Support\BackgroundHealth;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Detak penjadwal: Riwayat Sinkron memperingatkan bila penjadwal berhenti.
Schedule::call(fn () => BackgroundHealth::beat())->everyMinute()->name('scheduler-heartbeat');

// Jadwal harian. Di Laragon/Windows jalankan `php artisan schedule:work` (atau
// Task Scheduler yang memanggil `php artisan schedule:run` tiap menit).
Schedule::command('db:backup --prune')->dailyAt(config('backup.time'))->withoutOverlapping();
Schedule::command('stock:check')->dailyAt('01:30');
// Cadangan pesanan belum bayar yang sudah kedaluwarsa.
Schedule::command('model:prune', ['--model' => [MarketplaceStockHold::class]])->dailyAt('01:45');
// Access token Shopee berumur ± 4 jam; diperbarui sebelum kedaluwarsa.
Schedule::command('shopee:refresh-tokens')->everyThirtyMinutes()->withoutOverlapping();
// Sinkron Shopee: pesanan & resi tiap 5 menit, retur tiap jam (stok: lihat marketplace:push-stock di bawah).
// --queue: satu job per toko di antrean, jadi toko yang lambat / gagal tidak menahan yang lain.
Schedule::command('shopee:sync --only=orders --queue')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('shopee:sync --only=returns --queue')->hourly()->withoutOverlapping();

// TikTok Shop: access token ± 7 hari (dicek tiap jam), pesanan tiap 5 menit, retur tiap jam.
Schedule::command('tiktok:refresh-tokens')->hourly()->withoutOverlapping();
Schedule::command('tiktok:sync --only=orders --queue')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('tiktok:sync --only=returns --queue')->hourly()->withoutOverlapping();

// Pesanan marketplace yang tertahan karena stok kurang dicoba dikonfirmasi lagi (juga langsung saat stok bertambah).
Schedule::command('orders:retry-held')->everyMinute()->withoutOverlapping(5)->runInBackground();

// Produk semua toko (Shopee & TikTok): tarik produk baru, cocokkan SKU otomatis, lalu kirim stok. Tiap jam.
Schedule::command('marketplace:sync-products')->hourlyAt(20)->withoutOverlapping();

// Stok ke Shopee & TikTok: perubahan dikirim lewat antrean begitu terjadi; tiap menit
// penjadwal mengirim SKU yang stoknya belum sama (cadangan), tiap 30 menit semua SKU dikirim ulang.
Schedule::command('marketplace:push-stock')->everyMinute()->withoutOverlapping(10)->runInBackground();
Schedule::command('marketplace:push-stock --all')->everyThirtyMinutes()->withoutOverlapping(30)->runInBackground();

// Antrean (push Shopee/TikTok, kirim stok, sinkron per toko) diproses dari penjadwal, jadi cukup `schedule:work`
// di Laragon. Di VPS dengan Supervisor set QUEUE_IN_SCHEDULER=false.
Schedule::command('queue:work --stop-when-empty --max-time=55 --sleep=1')
    ->everyMinute()->withoutOverlapping(15)->runInBackground()
    ->when(fn () => config('marketplace.queue_in_scheduler'));

// Jejak push marketplace & riwayat sinkron lebih dari 30 hari dihapus.
Schedule::command('model:prune', ['--model' => [MarketplaceWebhookEvent::class, MarketplaceSyncLog::class]])->dailyAt('02:30');
