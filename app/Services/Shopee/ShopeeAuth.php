<?php

namespace App\Services\Shopee;

use App\Models\ShopeeShop;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Otorisasi toko & siklus token Shopee:
 *   code (callback) -> access_token (± 4 jam) + refresh_token (± 30 hari).
 * Refresh token hanya bisa dipakai sekali dan tiap refresh memberi refresh
 * token baru, jadi refresh dijaga lock supaya tidak dobel.
 */
class ShopeeAuth
{
    /**
     * Tukar code dari callback menjadi token, lalu simpan / perbarui tokonya.
     * Otorisasi lewat akun utama (main account) bisa mencakup beberapa toko.
     *
     * @return list<ShopeeShop>
     */
    public function connect(ShopeeClient $client, string $code, ?int $shopId, ?int $mainAccountId, ?int $warehouseId): array
    {
        $body = ['code' => $code, 'partner_id' => $client->partnerId()];
        $body += $shopId ? ['shop_id' => $shopId] : ['main_account_id' => $mainAccountId];

        $token = $client->publicRequest('POST', '/api/v2/auth/token/get', body: $body);

        $shopIds = $shopId ? [$shopId] : array_map('intval', $token['shop_id_list'] ?? []);

        if (! $shopIds) {
            throw new ShopeeException('Shopee tidak mengembalikan toko apa pun untuk otorisasi ini.');
        }

        $shops = DB::transaction(fn () => array_map(function (int $id) use ($client, $token, $warehouseId) {
            $shop = ShopeeShop::firstOrNew(['environment' => $client->environment, 'shop_id' => $id]);
            $shop->fill([
                'status' => 'connected',
                'is_active' => true,
                'connected_at' => now(),
                'last_error' => null,
                ...$this->tokenAttributes($token),
            ]);

            if ($warehouseId) {
                $shop->warehouse_id = $warehouseId;
            }

            $shop->save();

            return $shop;
        }, $shopIds));

        // Nama toko, region & batas otorisasi; gagal di sini tidak membatalkan koneksi.
        foreach ($shops as $shop) {
            try {
                $this->syncShopInfo($client, $shop);
            } catch (ShopeeException $e) {
                $shop->update(['last_error' => $e->getMessage()]);
            }
        }

        return $shops;
    }

    /** Access token yang masih berlaku; diperbarui dulu bila hampir kedaluwarsa. */
    public function freshAccessToken(ShopeeShop $shop, ?ShopeeClient $client = null): string
    {
        if ($shop->accessTokenExpiresSoon(5)) {
            $this->refresh($shop, $client);
        }

        return $shop->access_token;
    }

    /**
     * Perbarui access token toko. $onlyIfExpiring dipakai penjadwal supaya
     * toko yang tokennya masih lama tidak ikut diperbarui.
     */
    public function refresh(ShopeeShop $shop, ?ShopeeClient $client = null, ?int $onlyIfExpiringWithin = null): bool
    {
        $client ??= ShopeeClient::make($shop->environment);

        return Cache::lock("shopee:refresh:{$shop->id}", 30)->block(15, function () use ($shop, $client, $onlyIfExpiringWithin) {
            // Baca ulang: proses lain mungkin sudah memperbarui saat menunggu lock.
            $shop->refresh();

            if (! $shop->isConnected()) {
                throw new ShopeeException("Toko {$shop->displayName()} belum terhubung atau perlu diotorisasi ulang.");
            }

            if ($onlyIfExpiringWithin !== null && ! $shop->accessTokenExpiresSoon($onlyIfExpiringWithin)) {
                return false;
            }

            try {
                $token = $client->publicRequest('POST', '/api/v2/auth/access_token/get', body: [
                    'refresh_token' => $shop->refresh_token,
                    'shop_id' => $shop->shop_id,
                    'partner_id' => $client->partnerId(),
                ]);
            } catch (ShopeeException $e) {
                $shop->update([
                    'last_error' => $e->getMessage(),
                    'status' => $e->needsReauthorization() ? 'expired' : $shop->status,
                ]);

                throw $e;
            }

            $shop->update([...$this->tokenAttributes($token), 'last_error' => null]);

            return true;
        });
    }

    /** Ambil nama toko, region & batas otorisasi dari /shop/get_shop_info. */
    public function syncShopInfo(ShopeeClient $client, ShopeeShop $shop): void
    {
        $info = $client->shopRequest('GET', '/api/v2/shop/get_shop_info', $shop->shop_id, $this->freshAccessToken($shop, $client));

        $shop->update([
            'shop_name' => $info['shop_name'] ?? $shop->shop_name,
            'region' => $info['region'] ?? $shop->region,
            'authorization_expires_at' => isset($info['expire_time']) ? Carbon::createFromTimestamp($info['expire_time']) : $shop->authorization_expires_at,
        ]);
    }

    /**
     * @param  array<string, mixed>  $token
     * @return array<string, mixed>
     */
    private function tokenAttributes(array $token): array
    {
        if (blank($token['access_token'] ?? null) || blank($token['refresh_token'] ?? null)) {
            throw new ShopeeException('Balasan token dari Shopee tidak lengkap.');
        }

        return [
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'],
            'access_token_expires_at' => now()->addSeconds((int) ($token['expire_in'] ?? 14400)),
            // Shopee tidak mengirim umur refresh token; dokumentasinya 30 hari.
            'refresh_token_expires_at' => now()->addDays(30),
            'last_refreshed_at' => now(),
            'status' => 'connected',
        ];
    }
}
