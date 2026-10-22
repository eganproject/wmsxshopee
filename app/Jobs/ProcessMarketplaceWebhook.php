<?php

namespace App\Jobs;

use App\Models\MarketplaceWebhookEvent;
use App\Services\Shopee\ShopeeWebhookHandler;
use App\Services\Tiktok\TiktokWebhookHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Proses satu push marketplace yang sudah tersimpan. Gagal = diulang dengan
 * jeda bertahap; setelah percobaan habis statusnya "failed" dengan alasannya,
 * dan sinkron terjadwal tetap menutup pesanan yang terlewat.
 */
class ProcessMarketplaceWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly int $eventId) {}

    /** @return list<int> detik jeda sebelum percobaan berikutnya */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(): void
    {
        $event = MarketplaceWebhookEvent::find($this->eventId);

        if (! $event || in_array($event->status, ['processed', 'ignored'], true)) {
            return;
        }

        $event->increment('attempts');

        try {
            $handled = match ($event->platform) {
                'shopee' => app(ShopeeWebhookHandler::class)->handle($event),
                'tiktok' => app(TiktokWebhookHandler::class)->handle($event),
                default => false,
            };
        } catch (Throwable $e) {
            $event->update(['status' => 'retrying', 'error' => mb_substr($e->getMessage(), 0, 500)]);

            throw $e;
        }

        $event->update(['status' => $handled ? 'processed' : 'ignored', 'error' => null, 'processed_at' => now()]);
    }

    public function failed(?Throwable $e): void
    {
        MarketplaceWebhookEvent::whereKey($this->eventId)->update([
            'status' => 'failed',
            'error' => mb_substr((string) $e?->getMessage(), 0, 500),
        ]);
    }
}
