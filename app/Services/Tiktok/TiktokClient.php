<?php

namespace App\Services\Tiktok;

use App\Models\TiktokSetting;
use App\Support\ConnectionError;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

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
    /** Waktu panggilan terakhir dalam proses ini (ms), untuk jeda antar panggilan. */
    public static ?float $lastCallAt = null;

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

        $url = rtrim(config('tiktok.hosts.auth'), '/').$path.'?'.http_build_query($query);

        return $this->withRetries('GET', function () use ($url) {
            $this->pace();

            try {
                $response = Http::acceptJson()->connectTimeout(config('tiktok.connect_timeout', 10))->timeout(config('tiktok.timeout'))->get($url);
            } catch (ConnectionException $e) {
                throw new TiktokException(ConnectionError::message('TikTok', $e), connectionFailed: true, notSent: ConnectionError::neverSent($e));
            }

            return $this->unwrap($response->json() ?? [], $response->status());
        });
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

        $url = rtrim(config('tiktok.hosts.api'), '/').$path.'?'.http_build_query($query);

        return $this->withRetries($method, fn () => $this->sendOnce($method, $url, $accessToken, $json));
    }

    /**
     * Jalankan panggilan; gangguan sementara diulang dengan jeda yang makin panjang
     * (batas panggilan: 2, 4, 8 detik; lainnya 1, 2, 4 detik).
     *
     * @param  callable(): array<string, mixed>  $send
     * @return array<string, mixed>
     */
    private function withRetries(string $method, callable $send): array
    {
        $attempt = 0;

        while (true) {
            try {
                return $send();
            } catch (TiktokException $e) {
                $wait = $this->retryable($e, $method);

                if ($wait === null || ++$attempt > max(0, (int) config('tiktok.retries', 3))) {
                    throw $e;
                }

                Sleep::for($wait * 2 ** ($attempt - 1))->milliseconds();
            }
        }
    }

    /**
     * Beri jarak minimal antar panggilan dalam satu proses (config tiktok.min_interval_ms),
     * supaya rangkaian panggilan beruntun (kirim stok per produk, halaman pesanan)
     * tidak menembus batas panggilan TikTok.
     */
    private function pace(): void
    {
        $last = self::$lastCallAt;
        $interval = max(0, (int) config('tiktok.min_interval_ms', 0));
        $now = hrtime(true) / 1e6;

        if ($interval > 0 && $last !== null && ($gap = $now - $last) < $interval) {
            Sleep::for((int) ceil($interval - $gap))->milliseconds();
            $now += $interval - $gap;
        }

        self::$lastCallAt = $now;
    }

    /** @return array<string, mixed> */
    private function sendOnce(string $method, string $url, ?string $accessToken, string $json): array
    {
        $this->pace();

        $request = Http::acceptJson()->connectTimeout(config('tiktok.connect_timeout', 10))->timeout(config('tiktok.timeout'))
            ->withHeaders(array_filter(['x-tts-access-token' => $accessToken]));

        try {
            $response = $method === 'GET'
                ? $request->get($url)
                : $request->withBody($json, 'application/json')->send($method, $url);
        } catch (ConnectionException $e) {
            throw new TiktokException(ConnectionError::message('TikTok', $e), connectionFailed: true, notSent: ConnectionError::neverSent($e));
        }

        return $this->unwrap($response->json() ?? [], $response->status());
    }

    /**
     * Jeda (ms) sebelum mengulang, atau null bila tidak boleh diulang. Batas panggilan
     * (HTTP 429) berarti request belum diproses, jadi aman diulang untuk semua metode.
     * Koneksi yang gagal dibuka berarti request belum terkirim, jadi juga aman diulang.
     * Putus koneksi lain / HTTP 5xx hanya diulang untuk GET: POST bisa saja sudah diproses.
     */
    private function retryable(TiktokException $e, string $method): ?int
    {
        if ($e->isRateLimited()) {
            return 2000;
        }

        if ($e->notSent || ($method === 'GET' && ($e->connectionFailed || $e->httpStatus >= 500))) {
            return 1000;
        }

        return null;
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
            throw new TiktokException("TikTok membalas HTTP {$status} tanpa isi yang dikenali.", httpStatus: $status);
        }

        if ((int) $json['code'] !== 0) {
            throw new TiktokException(
                'TikTok: '.($json['message'] ?? 'error').' ('.$json['code'].')',
                (int) $json['code'],
                $json['request_id'] ?? null,
                $status,
            );
        }

        return is_array($json['data'] ?? null) ? $json['data'] : [];
    }

    private function ensureConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new TiktokException('App Key & App Secret TikTok Shop belum diisi di Pengaturan Marketplace › TikTok.');
        }
    }
}
