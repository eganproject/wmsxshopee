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
     * Beberapa panggilan sekaligus (paralel), lihat TiktokClient::requestMany().
     * Panggilan yang ditolak karena token tidak berlaku diulang sekali dengan token baru.
     *
     * @param  array<array-key, array{method: string, path: string, query?: array<string, scalar|null>, body?: array<string, mixed>|null}>  $requests
     * @return array<array-key, array<string, mixed>|TiktokException>
     */
    public function many(array $requests): array
    {
        if ($requests === []) {
            return [];
        }

        $results = $this->client->requestMany($requests, $this->token(), $this->shop->shop_cipher);
        $rejected = array_filter($results, fn ($result) => $result instanceof TiktokException && $result->invalidAccessToken());

        if ($rejected !== []) {
            $this->auth->refresh($this->shop, $this->client);
            $results = array_replace($results, $this->client->requestMany(array_intersect_key($requests, $rejected), $this->shop->access_token, $this->shop->shop_cipher));
        }

        return $results;
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
