<?php

namespace App\Http\Controllers\Concerns;

use App\Jobs\ProcessMarketplaceWebhook;
use App\Models\MarketplaceWebhookEvent;
use Illuminate\Support\Facades\Log;
use Throwable;

trait QueuesWebhookEvents
{
    /**
     * Masukkan push ke antrean. Marketplace tetap diberi 200 walau antrean
     * bermasalah: push sudah tersimpan dan bisa diproses ulang.
     */
    private function dispatchEvent(MarketplaceWebhookEvent $event): void
    {
        try {
            ProcessMarketplaceWebhook::dispatch($event->id);
        } catch (Throwable $e) {
            Log::warning('Push marketplace gagal diproses', ['event' => $event->id, 'error' => $e->getMessage()]);
        }
    }
}
