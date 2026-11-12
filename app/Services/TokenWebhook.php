<?php

namespace App\Services;

use App\Jobs\SendTokenWebhook;
use App\Models\ShopeeShop;
use App\Models\TiktokShop;

/**
 * Kirim token marketplace terbaru ke webhook Optisync setiap kali token
 * diambil (otorisasi toko) atau diperbarui (refresh). Lewat antrean supaya
 * webhook yang lambat / gagal tidak pernah menggagalkan penyimpanan token.
 * URL kosong (TOKEN_WEBHOOK_URL=) mematikan pengiriman.
 */
class TokenWebhook
{
    /** @param  array<string, mixed>  $response  balasan mentah Shopee (token/get atau access_token/get) */
    public static function shopee(ShopeeShop $shop, int $partnerId, array $response): void
    {
        self::send('shopee', $shop->shop_id, [
            'partner_id' => $partnerId,
            'refresh_token' => (string) $shop->refresh_token,
            'access_token' => (string) $shop->access_token,
            'expire_in' => (int) ($response['expire_in'] ?? 14400),
            'request_id' => (string) ($response['request_id'] ?? ''),
            'error' => (string) ($response['error'] ?? ''),
            'message' => (string) ($response['message'] ?? ''),
            'shop_id' => $shop->shop_id,
        ]);
    }

    /**
     * TikTok dipetakan ke bentuk yang sama: partner_id = app key, expire_in =
     * sisa umur access token (detik), karena TikTok mengirim timestamp.
     *
     * @param  array<string, mixed>  $response  isi "data" token TikTok (+ request_id)
     */
    public static function tiktok(TiktokShop $shop, string $appKey, array $response): void
    {
        self::send('tiktok', $shop->shop_id, [
            'partner_id' => $appKey,
            'refresh_token' => (string) $shop->refresh_token,
            'access_token' => (string) $shop->access_token,
            'expire_in' => self::tiktokExpireIn($response['access_token_expire_in'] ?? null, $shop),
            'request_id' => (string) ($response['request_id'] ?? ''),
            'error' => '',
            'message' => '',
            'shop_id' => $shop->shop_id,
        ]);
    }

    /** TikTok mengirim unix timestamp kedaluwarsa; diubah ke sisa detik. */
    private static function tiktokExpireIn(mixed $value, TiktokShop $shop): int
    {
        if (is_numeric($value) && (int) $value > 0) {
            $value = (int) $value;

            return max(0, $value < 1_000_000_000 ? $value : $value - now()->getTimestamp());
        }

        return max(0, (int) now()->diffInSeconds($shop->access_token_expires_at, false));
    }

    /** @param  array<string, mixed>  $token */
    private static function send(string $platform, int|string $shopId, array $token): void
    {
        if (blank(config('services.token_webhook.url'))) {
            return;
        }

        SendTokenWebhook::dispatch([
            'app' => (string) config('services.token_webhook.app', 'optisynch'),
            'platform' => $platform,
            'shop_id' => $shopId,
            'token' => $token,
        ])->afterCommit();
    }
}
