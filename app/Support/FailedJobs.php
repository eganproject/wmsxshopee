<?php

namespace App\Support;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Job antrean yang gagal (tabel failed_jobs): kirim stok, sinkron toko, proses push
 * dan tarik produk. Ditampilkan di Riwayat Sinkron dan lonceng notifikasi supaya
 * kegagalan di latar tidak diam-diam.
 */
class FailedJobs
{
    /** Nama job yang mudah dibaca. */
    public const LABELS = [
        'App\\Jobs\\ProcessMarketplaceWebhook' => 'Proses push marketplace',
        'App\\Jobs\\PushMarketplaceStock' => 'Kirim stok ke marketplace',
        'App\\Jobs\\RefreshMarketplaceCatalog' => 'Tarik produk marketplace',
        'App\\Jobs\\SyncMarketplaceShop' => 'Sinkron toko',
    ];

    public static function count(?Carbon $since = null): int
    {
        try {
            return self::table()->when($since, fn ($q) => $q->where('failed_at', '>=', $since))->count();
        } catch (Throwable) {
            return 0;
        }
    }

    public static function lastFailedAt(): ?Carbon
    {
        try {
            $last = self::table()->max('failed_at');
        } catch (Throwable) {
            return null;
        }

        return $last ? Carbon::parse($last) : null;
    }

    /**
     * @return LengthAwarePaginator<int, object{id: string, job: string, label: string, error: string, detail: string, failed_at: Carbon}>
     */
    public static function paginate(int $perPage = 50): LengthAwarePaginator
    {
        return self::table()->latest('failed_at')->latest('id')->paginate($perPage)->withQueryString()
            ->through(fn ($row) => self::present($row));
    }

    /** Ulangi job gagal; tanpa id berarti semua. Mengembalikan jumlah yang dimasukkan ke antrean. */
    public static function retry(?string $uuid = null): int
    {
        $ids = $uuid ? self::table()->where('uuid', $uuid)->pluck('uuid')->all() : self::table()->pluck('uuid')->all();

        if ($ids) {
            Artisan::call('queue:retry', ['id' => $ids]);
        }

        return count($ids);
    }

    public static function forget(?string $uuid = null): int
    {
        return $uuid ? self::table()->where('uuid', $uuid)->delete() : self::table()->delete();
    }

    private static function present(object $row): object
    {
        $payload = json_decode((string) $row->payload, true) ?: [];
        $class = $payload['displayName'] ?? ($payload['data']['commandName'] ?? 'Job');
        $lines = preg_split('/\R/', (string) $row->exception) ?: [''];

        return (object) [
            'id' => $row->uuid,
            'job' => $class,
            'label' => self::LABELS[$class] ?? class_basename($class),
            // Baris pertama exception tanpa nama kelasnya, cukup untuk dibaca operator.
            'error' => Str::limit(trim(preg_replace('/^[\\\\\w]+(Exception|Error)\s*:\s*/', '', $lines[0])), 300),
            'detail' => Str::limit(implode("\n", array_slice($lines, 0, 12)), 3000),
            'failed_at' => Carbon::parse($row->failed_at),
        ];
    }

    private static function table()
    {
        return DB::connection(config('queue.failed.database'))->table(config('queue.failed.table', 'failed_jobs'));
    }
}
