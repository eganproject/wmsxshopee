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
        return $this->client->request('GET', $path, $this->token(), $this->shop->shop_cipher, $query);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, scalar|null>  $query
     * @return array<string, mixed>
     */
    public function post(string $path, array $body = [], array $query = []): array
    {
        return $this->client->request('POST', $path, $this->token(), $this->shop->shop_cipher, $query, $body);
    }

    private function token(): string
    {
        if (blank($this->shop->shop_cipher)) {
            throw new TiktokException("Cipher toko {$this->shop->displayName()} belum ada. Perbarui koneksi toko.");
        }

        return $this->auth->freshAccessToken($this->shop, $this->client);
    }
}
