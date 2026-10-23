<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Apakah proses latar (penjadwal & queue worker) berjalan.
 * Pesanan Shopee/TikTok masuk otomatis hanya bila keduanya hidup:
 * penjadwal memasukkan sinkron per toko ke antrean tiap 5 menit, worker yang menjalankannya.
 * Tombol sinkron di topbar berjalan langsung, jadi tetap bisa jalan walau keduanya mati.
 */
class BackgroundHealth
{
    public const HEARTBEAT_KEY = 'scheduler:heartbeat';

    /** Penjadwal dianggap mati bila detak terakhir lebih lama dari ini. */
    public const SCHEDULER_STALE_MINUTES = 3;

    /** Antrean dianggap macet bila ada job yang menunggu lebih lama dari ini. */
    public const QUEUE_STALE_MINUTES = 3;

    /** Dipanggil penjadwal tiap menit. */
    public static function beat(): void
    {
        Cache::forever(self::HEARTBEAT_KEY, now()->getTimestamp());
    }

    public static function schedulerLastRun(): ?Carbon
    {
        $ts = Cache::get(self::HEARTBEAT_KEY);

        return $ts ? Carbon::createFromTimestamp((int) $ts, config('app.timezone')) : null;
    }

    /**
     * Job tertua yang belum diambil worker (hanya untuk antrean database).
     *
     * @return array{count: int, oldest: ?Carbon}|null null bila antrean bukan database
     */
    public static function queueBacklog(): ?array
    {
        $connection = config('queue.default');
        if (config("queue.connections.{$connection}.driver") !== 'database') {
            return null;
        }

        try {
            $table = DB::connection(config("queue.connections.{$connection}.connection"))
                ->table(config("queue.connections.{$connection}.table", 'jobs'))
                ->whereNull('reserved_at')
                ->where('available_at', '<=', now()->getTimestamp());
            $count = (clone $table)->count();
            $oldest = $count ? (clone $table)->min('available_at') : null;
        } catch (Throwable) {
            return null;
        }

        return ['count' => $count, 'oldest' => $oldest ? Carbon::createFromTimestamp((int) $oldest, config('app.timezone')) : null];
    }

    /**
     * Peringatan yang perlu ditampilkan; kosong bila semua berjalan.
     *
     * @return list<array{title: string, body: string}>
     */
    public static function problems(): array
    {
        $problems = [];
        $last = self::schedulerLastRun();

        if (! $last || $last->lt(now()->subMinutes(self::SCHEDULER_STALE_MINUTES))) {
            $problems[] = [
                'title' => 'Penjadwal tidak berjalan'.($last ? ' sejak '.$last->diffForHumans() : ''),
                'body' => 'Sinkron pesanan otomatis tiap 5 menit tidak jalan, jadi pesanan baru hanya masuk lewat push marketplace atau tombol Sinkron. '
                    .'Di VPS pastikan cron berisi `* * * * * cd /path/optima && php artisan schedule:run`; di Laragon biarkan `php artisan schedule:work` terbuka.',
            ];
        }

        $backlog = self::queueBacklog();
        if ($backlog && $backlog['oldest'] && $backlog['oldest']->lt(now()->subMinutes(self::QUEUE_STALE_MINUTES))) {
            $problems[] = [
                'title' => "Antrean tidak diproses: {$backlog['count']} job menunggu sejak ".$backlog['oldest']->diffForHumans(),
                'body' => 'Sinkron terjadwal per toko dan push Shopee/TikTok menunggu di antrean tapi tidak ada worker yang menjalankannya. '
                    .(config('marketplace.queue_in_scheduler')
                        ? 'Antrean dijalankan dari penjadwal (QUEUE_IN_SCHEDULER=true), jadi pastikan penjadwal berjalan.'
                        : 'Di VPS cek `sudo supervisorctl status` (harus RUNNING), lalu `sudo supervisorctl restart all` bila perlu.'),
            ];
        }

        return $problems;
    }
}
