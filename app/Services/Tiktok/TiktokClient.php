<?php

namespace App\Services\Tiktok;

use App\Models\TiktokSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Klien HTTP TikTok Shop Open API (versi 202309) dengan tanda tangan HMAC-SHA256.
 *
 * Tanda tangan (parameter "sign"):
 *   1. Ambil semua query kecuali sign & access_token, urutkan menurut nama.
 *   2. Rangkai {nama}{nilai} lalu taruh path di depannya.
 *   3. Untuk request selain GET, tambahkan body JSON apa adanya di belakang.
 *   4. Bungkus dengan app_secret di depan & belakang, lalu HMAC-SHA256 (kunci
 *      app_secret), hasil hex.
 * Access token dikirim lewat header x-tts-access-token; shop_cipher lewat query.
 */
class TiktokClient
{
    public function __construct(private readonly TiktokSetting $setting) {}

    public static function make(): self
    {
        return new self(TiktokSetting::current());
    }

    public function isConfigured(): bool
    {
        return $this->setting->isConfigured();
    }

    public function appKey(): string
    {
        $this->ensureConfigured();

        return (string) $this->setting->app_key;
    }

    /**
     * @param  array<string, scalar|null>  $query
     */
    public function sign(string $path, array $query, string $body = ''): string
    {
        $this->ensureConfigured();
        $secret = (string) $this->setting->app_secret;

        unset($query['sign'], $query['access_token']);
        ksort($query);

        $base = $path;
        foreach ($query as $key => $value) {
            $base .= $key.$value;
        }

        return hash_hmac('sha256', $secret.$base.$body.$secret, $secret);
    }

    /** Verifikasi header Authorization webhook: HMAC-SHA256(app_secret, app_key + body). */
    public function verifyWebhook(string $signature, string $body): bool
    {
        if (! $this->isConfigured() || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $this->setting->app_key.$body, (string) $this->setting->app_secret), $signature);
    }

    /**
     * Halaman otorisasi toko di TikTok Shop. Service ID ada di Partner Center
     * (App & Service › app › Authorization link). Tanpa Service ID dipakai
     * tautan lama berbasis app_key.
     */
    public function authorizationUrl(string $state): string
    {
        $this->ensureConfigured();

        if (filled($this->setting->service_id)) {
            return config('tiktok.hosts.authorize').'?'.http_build_query(['service_id' => $this->setting->service_id, 'state' => $state]);
        }

        return rtrim(config('tiktok.hosts.auth'), '/').'/oauth/authorize?'.http_build_query(['app_key' => $this->appKey(), 'state' => $state]);
    }

    /**
     * Tukar code / refresh token di host auth. Tidak ditandatangani; app_secret
     * ikut di query sesuai dokumentasi TikTok.
     *
     * @return array<string, mixed>
     */
    public function token(string $grantType, string $value): array
    {
        $this->ensureConfigured();
        $path = $grantType === 'refresh_token' ? '/api/v2/token/refresh' : '/api/v2/token/get';

        $query = [
            'app_key' => $this->appKey(),
            'app_secret' => (string) $this->setting->app_secret,
            'grant_type' => $grantType,
            ($grantType === 'refresh_token' ? 'refresh_token' : 'auth_code') => $value,
        ];

        try {
            $response = Http::acceptJson()->timeout(config('tiktok.timeout'))
                ->get(rtrim(config('tiktok.hosts.auth'), '/').$path.'?'.http_build_query($query));
        } catch (ConnectionException $e) {
            throw new TiktokException('Tidak bisa terhubung ke TikTok: '.$e->getMessage());
        }

        return $this->unwrap($response->json() ?? [], $response->status());
    }

    /**
     * Panggil Open API. $path tanpa versi, cth. "/order/orders/search": versi
     * dari config disisipkan setelah segmen pertama (/order/202309/orders/search).
     *
     * @param  array<string, scalar|null>  $query
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed> isi field "data"
     */
    public function request(string $method, string $path, ?string $accessToken = null, ?string $shopCipher = null, array $query = [], ?array $body = null): array
    {
        $path = $this->versioned($path);
        $method = strtoupper($method);
        $json = $method === 'GET' ? '' : json_encode($body ?? (object) [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $query = array_filter([
            ...$query,
            'app_key' => $this->appKey(),
            'timestamp' => now()->getTimestamp(),
            'shop_cipher' => $shopCipher,
        ], fn ($value) => $value !== null && $value !== '');
        $query['sign'] = $this->sign($path, $query, $json);

        $request = Http::acceptJson()->timeout(config('tiktok.timeout'))
            ->withHeaders(array_filter(['x-tts-access-token' => $accessToken]));
        $url = rtrim(config('tiktok.hosts.api'), '/').$path.'?'.http_build_query($query);

        try {
            $response = $method === 'GET'
                ? $request->get($url)
                : $request->withBody($json, 'application/json')->send($method, $url);
        } catch (ConnectionException $e) {
            throw new TiktokException('Tidak bisa terhubung ke TikTok: '.$e->getMessage());
        }

        return $this->unwrap($response->json() ?? [], $response->status());
    }

    public function versioned(string $path): string
    {
        $segments = explode('/', ltrim($path, '/'), 2);

        if (preg_match('/^\d{6}$/', explode('/', $segments[1] ?? '')[0])) {
            return '/'.ltrim($path, '/');
        }

        return '/'.$segments[0].'/'.config('tiktok.version').(isset($segments[1]) ? '/'.$segments[1] : '');
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>
     */
    private function unwrap(array $json, int $status): array
    {
        if (! array_key_exists('code', $json)) {
            throw new TiktokException("TikTok membalas HTTP {$status} tanpa isi yang dikenali.");
        }

        if ((int) $json['code'] !== 0) {
            throw new TiktokException(
                'TikTok: '.($json['message'] ?? 'error').' ('.$json['code'].')',
                (int) $json['code'],
                $json['request_id'] ?? null,
            );
        }

        return is_array($json['data'] ?? null) ? $json['data'] : [];
    }

    private function ensureConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new TiktokException('App Key & App Secret TikTok Shop belum diisi di Pengaturan TikTok.');
        }
    }
}
