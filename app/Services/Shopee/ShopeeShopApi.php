<?php

namespace App\Services\Shopee;

use App\Models\ShopeeShop;

/** Panggilan API toko untuk satu toko; access token otomatis diperbarui bila hampir habis. */
class ShopeeShopApi
{
    public function __construct(
        public readonly ShopeeShop $shop,
        private readonly ShopeeClient $client,
        private readonly ShopeeAuth $auth,
    ) {}

    public static function for(ShopeeShop $shop): self
    {
        return new self($shop, ShopeeClient::make($shop->environment), app(ShopeeAuth::class));
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->client->shopRequest('GET', $path, $this->shop->shop_id, $this->auth->freshAccessToken($this->shop, $this->client), $query);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function post(string $path, array $body = []): array
    {
        return $this->client->shopRequest('POST', $path, $this->shop->shop_id, $this->auth->freshAccessToken($this->shop, $this->client), body: $body);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function download(string $path, array $body = []): string
    {
        return $this->client->shopDownload($path, $this->shop->shop_id, $this->auth->freshAccessToken($this->shop, $this->client), $body);
    }
}
