<?php

namespace App\Services\Tiktok;

use App\Models\TiktokShop;

/** Panggilan API untuk satu toko TikTok: access token & shop_cipher otomatis ikut. */
class TiktokShopApi
{
    public function __construct(
        public readonly TiktokShop $shop,
        private readonly TiktokClient $client,
        private readonly TiktokAuth $auth,
    ) {}

    public static function for(TiktokShop $shop): self
    {
        return new self($shop, TiktokClient::make(), app(TiktokAuth::class));
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->withToken(fn (string $token) => $this->client->request('GET', $path, $token, $this->shop->shop_cipher, $query));
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, scalar|null>  $query
     * @return array<string, mixed>
     */
    public function post(string $path, array $body = [], array $query = []): array
    {
        return $this->withToken(fn (string $token) => $this->client->request('POST', $path, $token, $this->shop->shop_cipher, $query, $body));
    }

    /**
     * Jalankan panggilan dengan access token segar. Bila TikTok menolak tokennya,
     * token diperbarui sekali lalu panggilan diulang.
     *
     * @template T
     *
     * @param  callable(string): T  $call
     * @return T
     */
    private function withToken(callable $call): mixed
    {
        try {
            return $call($this->token());
        } catch (TiktokException $e) {
            if (! $e->invalidAccessToken()) {
                throw $e;
            }

            $this->auth->refresh($this->shop, $this->client);

            return $call($this->shop->access_token);
        }
    }

    private function token(): string
    {
        if (blank($this->shop->shop_cipher)) {
            throw new TiktokException("Cipher toko {$this->shop->displayName()} belum ada. Perbarui koneksi toko.");
        }

        return $this->auth->freshAccessToken($this->shop, $this->client);
    }
}
