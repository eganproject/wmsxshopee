<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Throwable;

/** Satu kali sinkron satu toko: bagian apa, dipicu apa, berhasil / gagal dan ringkasannya. */
class MarketplaceSyncLog extends Model
{
    use Prunable;

    public const KEEP_DAYS = 30;

    public const PARTS = [
        'orders' => 'Pesanan',
        'returns' => 'Retur',
        'stock' => 'Stok (semua SKU)',
        'stock-changed' => 'Stok berubah',
        'products' => 'Produk & SKU',
    ];

    public const TRIGGERS = [
        'jadwal' => 'Terjadwal',
        'manual' => 'Manual',
        'otomatis' => 'Otomatis',
        'push' => 'Push marketplace',
    ];

    /** Pemicu sinkron yang sedang berjalan; perintah terjadwal & job mengisinya. */
    public static string $trigger = 'manual';

    protected $fillable = [
        'platform',
        'shop_ref',
        'shop_name',
        'part',
        'trigger',
        'status',
        'summary',
        'error',
        'started_at',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'duration_ms' => 'integer',
        ];
    }

    /** Jalankan $callback dengan pemicu tertentu, lalu kembalikan pemicu sebelumnya. */
    public static function as(string $trigger, callable $callback): mixed
    {
        $previous = static::$trigger;
        static::$trigger = $trigger;

        try {
            return $callback();
        } finally {
            static::$trigger = $previous;
        }
    }

    /**
     * Jalankan sinkron satu toko dan catat hasilnya. Error tetap dilempar ulang.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @param  callable(T): (string|array{0: string, 1: string}|null)  $summary  ringkasan hasil, atau [status, ringkasan]
     *                                                                           (cth. sebagian SKU gagal); null = tidak perlu dicatat
     * @return T
     */
    public static function track(string $platform, Model $shop, string $part, callable $callback, callable $summary): mixed
    {
        $started = now();
        $clock = hrtime(true);

        try {
            $result = $callback();
        } catch (Throwable $e) {
            static::write($platform, $shop, $part, 'failed', null, $e->getMessage(), $started, $clock);

            throw $e;
        }

        $text = $summary($result);
        [$status, $text] = is_array($text) ? $text : ['success', $text];

        if ($text !== null) {
            static::write($platform, $shop, $part, $status, $status === 'success' ? $text : null, $status === 'success' ? null : $text, $started, $clock);
        }

        return $result;
    }

    private static function write(string $platform, Model $shop, string $part, string $status, ?string $summary, ?string $error, $started, int $clock): void
    {
        try {
            static::create([
                'platform' => $platform,
                'shop_ref' => $shop->getKey(),
                'shop_name' => mb_substr((string) $shop->displayName(), 0, 150),
                'part' => $part,
                'trigger' => static::$trigger,
                'status' => $status,
                'summary' => $summary !== null ? mb_substr($summary, 0, 500) : null,
                'error' => $error !== null ? mb_substr($error, 0, 1000) : null,
                'started_at' => $started,
                'duration_ms' => (int) ((hrtime(true) - $clock) / 1_000_000),
            ]);
        } catch (Throwable $e) {
            // Gagal mencatat riwayat tidak boleh menggagalkan sinkron.
            report($e);
        }
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', 'failed');
    }

    public function partLabel(): string
    {
        return collect(explode(',', $this->part))->map(fn ($part) => self::PARTS[$part] ?? $part)->implode(', ');
    }

    public function prunable(): Builder
    {
        return static::where('started_at', '<', now()->subDays(self::KEEP_DAYS));
    }
}
