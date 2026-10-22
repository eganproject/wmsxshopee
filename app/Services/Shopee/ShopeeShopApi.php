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
        return $this->withToken(fn (string $token) => $this->client->shopRequest('GET', $path, $this->shop->shop_id, $token, $query));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function post(string $path, array $body = []): array
    {
        return $this->withToken(fn (string $token) => $this->client->shopRequest('POST', $path, $this->shop->shop_id, $token, body: $body));
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function download(string $path, array $body = []): string
    {
        return $this->withToken(fn (string $token) => $this->client->shopDownload($path, $this->shop->shop_id, $token, $body));
    }

    /**
     * Jalankan panggilan dengan access token segar. Bila Shopee menolak tokennya
     * (cth. sudah diganti proses lain), token diperbarui sekali lalu panggilan diulang.
     *
     * @template T
     *
     * @param  callable(string): T  $call
     * @return T
     */
    private function withToken(callable $call): mixed
    {
        try {
            return $call($this->auth->freshAccessToken($this->shop, $this->client));
        } catch (ShopeeException $e) {
            if (! $e->invalidAccessToken()) {
                throw $e;
            }

            $this->auth->refresh($this->shop, $this->client);

            return $call($this->shop->access_token);
        }
    }
}
