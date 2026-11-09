<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * POST token ke webhook Optisync. Isi job dienkripsi di tabel antrean karena
 * memuat token. Log hanya mencatat platform, toko & status, tanpa token.
 */
class SendTokenWebhook implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    /** @param  array<string, mixed>  $payload */
    public function __construct(public array $payload) {}

    public function handle(): void
    {
        $url = config('services.token_webhook.url');

        if (blank($url)) {
            return;
        }

        try {
            $response = Http::acceptJson()
                ->connectTimeout(5)
                ->timeout((int) config('services.token_webhook.timeout', 10))
                ->post($url, $this->payload);
        } catch (ConnectionException) {
            throw new RuntimeException("Webhook token gagal tersambung ({$this->label()}).");
        }

        if ($response->failed()) {
            throw new RuntimeException("Webhook token membalas HTTP {$response->status()} ({$this->label()}).");
        }
    }

    public function failed(?Throwable $e): void
    {
        Log::warning('Webhook token tidak terkirim', [
            'platform' => $this->payload['platform'] ?? null,
            'shop_id' => $this->payload['shop_id'] ?? null,
            'error' => $e?->getMessage(),
        ]);
    }

    private function label(): string
    {
        return ($this->payload['platform'] ?? '?').' toko '.($this->payload['shop_id'] ?? '?');
    }
}
