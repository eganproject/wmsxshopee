<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Jadwal harian. Di Laragon/Windows jalankan `php artisan schedule:work` (atau
// Task Scheduler yang memanggil `php artisan schedule:run` tiap menit).
Schedule::command('db:backup --prune')->dailyAt(config('backup.time'))->withoutOverlapping();
Schedule::command('stock:check')->dailyAt('01:30');
// Access token Shopee berumur ± 4 jam; diperbarui sebelum kedaluwarsa.
Schedule::command('shopee:refresh-tokens')->everyThirtyMinutes()->withoutOverlapping();
// Sinkron Shopee: pesanan & resi tiap 5 menit, stok tiap 15 menit, retur tiap jam.
Schedule::command('shopee:sync --only=orders')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('shopee:sync --only=stock')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('shopee:sync --only=returns')->hourly()->withoutOverlapping();
