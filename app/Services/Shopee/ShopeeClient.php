<?php

namespace App\Services\Shopee;

use App\Models\ShopeeSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

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
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $query, array $body): array
    {
        $url = $this->host().$path.'?'.http_build_query($query);
        $request = Http::acceptJson()->timeout(config('shopee.timeout'));

        try {
            $response = strtoupper($method) === 'GET'
                ? $request->get($url)
                : $request->asJson()->post($url, $body);
        } catch (ConnectionException $e) {
            throw new ShopeeException('Tidak bisa terhubung ke Shopee: '.$e->getMessage());
        }

        $json = $response->json() ?? [];

        if (filled($json['error'] ?? null)) {
            throw new ShopeeException(
                'Shopee: '.($json['message'] ?? $json['error']).' ('.$json['error'].')',
                $json['error'],
                $json['request_id'] ?? null,
            );
        }

        if ($response->failed()) {
            throw new ShopeeException("Shopee membalas HTTP {$response->status()}.");
        }

        return $json;
    }

    private function ensureConfigured(): void
    {
        if (! $this->isConfigured()) {
            $label = ShopeeSetting::ENVIRONMENTS[$this->environment] ?? $this->environment;

            throw new ShopeeException("Partner ID & Partner Key Shopee untuk {$label} belum diisi di Pengaturan Shopee.");
        }
    }
}
