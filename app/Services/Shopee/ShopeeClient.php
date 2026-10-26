<?php

namespace App\Services\Shopee;

use App\Models\ShopeeSetting;
use App\Support\ConnectionError;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Klien HTTP Shopee Open Platform v2 dengan tanda tangan HMAC-SHA256.
 *
 * Base string tanda tangan:
 *   - API publik & auth : partner_id + path + timestamp
 *   - API toko          : partner_id + path + timestamp + access_token + shop_id
 * dengan partner key sebagai kunci. Parameter umum (partner_id, timestamp,
 * sign, access_token, shop_id) selalu dikirim lewat query string.
 */
class ShopeeClient
{
    public function __construct(
        private readonly ShopeeSetting $setting,
        public readonly string $environment,
    ) {}

    public static function make(?string $environment = null): self
    {
        $setting = ShopeeSetting::current();

        return new self($setting, $environment ?? $setting->environment);
    }

    public function isConfigured(): bool
    {
        return $this->setting->isConfigured($this->environment);
    }

    public function host(): string
    {
        return rtrim(config("shopee.hosts.{$this->environment}"), '/');
    }

    public function partnerId(): int
    {
        $this->ensureConfigured();

        return (int) $this->setting->partnerId($this->environment);
    }

    public function sign(string $path, int $timestamp, ?string $accessToken = null, ?int $shopId = null): string
    {
        $this->ensureConfigured();

        $base = $this->partnerId().$path.$timestamp.($accessToken ?? '').($shopId ?? '');

        return hash_hmac('sha256', $base, (string) $this->setting->partnerKey($this->environment));
    }

    /** URL halaman otorisasi toko di Shopee; pemilik toko login lalu menyetujui akses. */
    public function authorizationUrl(string $redirectUrl): string
    {
        $path = '/api/v2/shop/auth_partner';
        $timestamp = now()->getTimestamp();

        return $this->host().$path.'?'.http_build_query([
            'partner_id' => $this->partnerId(),
            'timestamp' => $timestamp,
            'sign' => $this->sign($path, $timestamp),
            'redirect' => $redirectUrl,
        ]);
    }

    /**
     * Panggil API publik / auth (tanpa access token).
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function publicRequest(string $method, string $path, array $query = [], array $body = []): array
    {
        $timestamp = now()->getTimestamp();

        return $this->send($method, $path, [
            'partner_id' => $this->partnerId(),
            'timestamp' => $timestamp,
            'sign' => $this->sign($path, $timestamp),
            ...$query,
        ], $body);
    }

    /**
     * Panggil API toko dengan access token yang masih berlaku. Pastikan token
     * segar lebih dulu lewat ShopeeAuth::freshAccessToken().
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function shopRequest(string $method, string $path, int $shopId, string $accessToken, array $query = [], array $body = []): array
    {
        $timestamp = now()->getTimestamp();

        return $this->send($method, $path, [
            'partner_id' => $this->partnerId(),
            'timestamp' => $timestamp,
            'access_token' => $accessToken,
            'shop_id' => $shopId,
            'sign' => $this->sign($path, $timestamp, $accessToken, $shopId),
            ...$query,
        ], $body);
    }

    /**
     * POST API toko yang membalas berkas (cth. PDF label resi), bukan JSON.
     * Bila Shopee membalas JSON berarti ada error.
     *
     * @param  array<string, mixed>  $body
     */
    public function shopDownload(string $path, int $shopId, string $accessToken, array $body = []): string
    {
        $timestamp = now()->getTimestamp();
        $url = $this->host().$path.'?'.http_build_query([
            'partner_id' => $this->partnerId(),
            'timestamp' => $timestamp,
            'access_token' => $accessToken,
            'shop_id' => $shopId,
            'sign' => $this->sign($path, $timestamp, $accessToken, $shopId),
        ]);

        try {
            $response = Http::connectTimeout(config('shopee.connect_timeout', 10))->timeout(config('shopee.timeout'))->asJson()->post($url, $body);
        } catch (ConnectionException $e) {
            throw new ShopeeException(ConnectionError::message('Shopee', $e), connectionFailed: true, notSent: ConnectionError::neverSent($e));
        }

        if (str_contains((string) $response->header('Content-Type'), 'json')) {
            $json = $response->json() ?? [];

            throw new ShopeeException(
                'Shopee: '.($json['message'] ?? $json['error'] ?? 'berkas tidak tersedia').(filled($json['error'] ?? null) ? ' ('.$json['error'].')' : ''),
                $json['error'] ?? null,
                $json['request_id'] ?? null,
            );
        }

        if ($response->failed()) {
            throw new ShopeeException("Shopee membalas HTTP {$response->status()}.");
        }

        return $response->body();
    }

    /**
     * Kirim request; gangguan sementara diulang otomatis (lihat retryable()).
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $query, array $body): array
    {
        $attempt = 0;

        while (true) {
            try {
                return $this->sendOnce($method, $path, $query, $body);
            } catch (ShopeeException $e) {
                $wait = $this->retryable($e, $method);

                if ($wait === null || ++$attempt > max(0, (int) config('shopee.retries', 2))) {
                    throw $e;
                }

                Sleep::for($wait * $attempt)->milliseconds();
            }
        }
    }

    /**
     * Jeda (ms) sebelum mengulang, atau null bila tidak boleh diulang. Batas panggilan &
     * server sibuk berarti request belum diproses, jadi aman diulang untuk GET maupun POST.
     * Putus koneksi / HTTP 5xx hanya diulang untuk GET: POST (cth. atur pengiriman) bisa
     * saja sudah diproses Shopee.
     */
    private function retryable(ShopeeException $e, string $method): ?int
    {
        $error = (string) $e->shopeeError;

        if ($e->httpStatus === 429 || str_contains($error, 'too_many') || str_contains($error, 'busy') || $error === 'error_rate_limit') {
            return 2000;
        }

        // Koneksi yang gagal dibuka berarti request belum terkirim: aman diulang untuk POST juga.
        if ($e->notSent) {
            return 1000;
        }

        if (strtoupper($method) === 'GET' && ($e->connectionFailed || $e->httpStatus >= 500 || in_array($error, ['error_server', 'error_inner', 'error_network', 'error_system'], true))) {
            return 1000;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function sendOnce(string $method, string $path, array $query, array $body): array
    {
        $url = $this->host().$path.'?'.http_build_query($query);
        $request = Http::acceptJson()->connectTimeout(config('shopee.connect_timeout', 10))->timeout(config('shopee.timeout'));

        try {
            $response = strtoupper($method) === 'GET'
                ? $request->get($url)
                : $request->asJson()->post($url, $body);
        } catch (ConnectionException $e) {
            throw new ShopeeException(ConnectionError::message('Shopee', $e), connectionFailed: true, notSent: ConnectionError::neverSent($e));
        }

        $json = $response->json() ?? [];

        if (filled($json['error'] ?? null)) {
            [$message, $error] = $this->errorDetail($json);

            throw new ShopeeException($message, $error, $json['request_id'] ?? null, $response->status());
        }

        if ($response->failed()) {
            throw new ShopeeException("Shopee membalas HTTP {$response->status()}.", httpStatus: $response->status());
        }

        return $json;
    }

    /**
     * Pesan error yang bisa dibaca. Untuk API batch yang gagal semua
     * (common.batch_api_all_failed) alasan sebenarnya ada di result_list per pesanan.
     *
     * @param  array<string, mixed>  $json
     * @return array{0: string, 1: string}
     */
    private function errorDetail(array $json): array
    {
        $error = (string) $json['error'];
        $failures = collect($json['response']['result_list'] ?? [])
            ->filter(fn ($row) => is_array($row) && filled($row['fail_error'] ?? $row['fail_message'] ?? null))
            ->values();

        if ($failures->isEmpty()) {
            return ['Shopee: '.($json['message'] ?? $error).' ('.$error.')', $error];
        }

        $first = $failures->first();
        $details = $failures->map(fn (array $row) => trim(($row['order_sn'] ?? '').': '.($row['fail_message'] ?? $row['fail_error']).(filled($row['fail_error'] ?? null) ? ' ('.$row['fail_error'].')' : ''), ': '))
            ->unique()->take(3)->implode('; ');

        return ['Shopee: '.$details, (string) ($first['fail_error'] ?? $error)];
    }

    private function ensureConfigured(): void
    {
        if (! $this->isConfigured()) {
            $label = ShopeeSetting::ENVIRONMENTS[$this->environment] ?? $this->environment;

            throw new ShopeeException("Partner ID & Partner Key Shopee untuk {$label} belum diisi di Pengaturan Marketplace › Shopee.");
        }
    }
}
