<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Status sinkron marketplace yang dijalankan di antrean dari tombol (bukan di
 * request web). Request web dibatasi waktu oleh nginx (504 Gateway Timeout),
 * sedangkan tarik produk / pesanan toko besar bisa butuh beberapa menit.
 *
 * Per user disimpan daftar tugas di cache: yang masih berjalan ditampilkan
 * di pojok layar dan dipantau halaman, yang selesai muncul sekali sebagai
 * toast lalu dihapus. Kunci per tugas (cth. "pull:shopee:3") mencegah tugas
 * yang sama dijalankan dobel selama yang sebelumnya belum selesai.
 */
class BackgroundTasks
{
    /** Setelah selama ini tanpa kabar, tugas dianggap macet (worker antrean mati?). */
    public const STALE_MINUTES = 20;

    private const TTL_SECONDS = 86400;

    /**
     * Catat tugas baru. False bila tugas yang sama masih berjalan.
     */
    public static function start(int $userId, string $key, string $label): bool
    {
        if (! Cache::add(self::lockKey($key), $userId, self::STALE_MINUTES * 60)) {
            return false;
        }

        self::mutate($userId, function (array $tasks) use ($key, $label) {
            $tasks[$key] = ['label' => $label, 'status' => 'running', 'message' => null, 'started_at' => now()->toIso8601String()];

            return $tasks;
        });

        return true;
    }

    public static function finish(int $userId, string $key, bool $ok, string $message): void
    {
        Cache::forget(self::lockKey($key));

        self::mutate($userId, function (array $tasks) use ($key, $ok, $message) {
            $tasks[$key] = [
                'label' => $tasks[$key]['label'] ?? $key,
                'status' => $ok ? 'success' : 'error',
                'message' => $message,
                'started_at' => $tasks[$key]['started_at'] ?? now()->toIso8601String(),
            ];

            return $tasks;
        });
    }

    /** Tugas yang sama sedang berjalan (oleh user mana pun). */
    public static function running(string $key): bool
    {
        return Cache::has(self::lockKey($key));
    }

    /**
     * Ambil status untuk user: label tugas yang masih berjalan, dan hasil tugas
     * yang sudah selesai (sekaligus dihapus supaya toast hanya muncul sekali).
     *
     * @return array{running: list<string>, finished: list<array{type: string, message: string}>}
     */
    public static function poll(int $userId): array
    {
        $running = [];
        $finished = [];

        self::mutate($userId, function (array $tasks) use (&$running, &$finished) {
            foreach ($tasks as $key => $task) {
                if ($task['status'] === 'running') {
                    if (Carbon::parse($task['started_at'])->lt(now()->subMinutes(self::STALE_MINUTES))) {
                        $finished[] = ['type' => 'warning', 'message' => "{$task['label']}: belum ada kabar setelah ".self::STALE_MINUTES.' menit. Pastikan worker antrean (Supervisor) berjalan, lalu cek Riwayat Sinkron.'];
                        Cache::forget(self::lockKey($key));
                        unset($tasks[$key]);
                    } else {
                        $running[] = $task['label'];
                    }

                    continue;
                }

                $finished[] = ['type' => $task['status'], 'message' => "{$task['label']}: {$task['message']}"];
                unset($tasks[$key]);
            }

            return $tasks;
        });

        return ['running' => $running, 'finished' => $finished];
    }

    /** Ada tugas user ini yang belum dilaporkan (berjalan atau sudah selesai). */
    public static function has(int $userId): bool
    {
        return Cache::get(self::key($userId), []) !== [];
    }

    private static function mutate(int $userId, callable $callback): void
    {
        Cache::lock(self::key($userId).'.lock', 10)->block(5, function () use ($userId, $callback) {
            $tasks = $callback(Cache::get(self::key($userId), []));

            $tasks === []
                ? Cache::forget(self::key($userId))
                : Cache::put(self::key($userId), $tasks, self::TTL_SECONDS);
        });
    }

    private static function key(int $userId): string
    {
        return "background-tasks.{$userId}";
    }

    private static function lockKey(string $key): string
    {
        return "background-task.{$key}";
    }
}
