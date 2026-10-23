<?php

namespace App\Http\Controllers\Shopee;

use App\Http\Controllers\Concerns\QueuesWebhookEvents;
use App\Http\Controllers\Controller;
use App\Models\MarketplaceWebhookEvent;
use App\Models\ShopeeSetting;
use App\Models\ShopeeShop;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Push Mechanism Shopee: Shopee mengirim POST ke URL ini saat status pesanan
 * berubah (code 3), resi terbit (code 4), otorisasi toko dicabut (code 2) atau
 * otorisasi akan berakhir (code 12).
 * Tanda tangan di header Authorization = HMAC-SHA256(key, url + "|" + body), dengan key =
 * Live Push Partner Key dari menu Push Mechanism (app lama: partner key app).
 * Push disimpan di marketplace_webhook_events lalu diproses job antrean
 * (ProcessMarketplaceWebhook -> ShopeeWebhookHandler), jadi tidak hilang bila gagal.
 * Daftarkan URL ini di console app Shopee (Push Mechanism) bila Optima bisa
 * diakses dari internet; tanpa itu sinkron terjadwal tetap berjalan.
 */
class ShopeeWebhookController extends Controller
{
    use QueuesWebhookEvents;

    public function __invoke(Request $request): Response
    {
        $payload = $request->json()->all();
        $shopId = (int) ($payload['shop_id'] ?? $payload['data']['shop_id'] ?? 0);
        $shops = ShopeeShop::where('shop_id', $shopId)->get();
        $shop = $shops->first(fn (ShopeeShop $shop) => $this->verified($request, $shop->environment));
        $code = (int) ($payload['code'] ?? 0);
        $data = $payload['data'] ?? [];

        // Otorisasi app akan berakhir: berlaku untuk akun partner, bisa tanpa shop_id.
        if ($code === 12) {
            return $this->authorizationExpiry($request, $shop, $payload);
        }

        // Push untuk toko tak dikenal / tanda tangan salah diabaikan; Shopee tetap diberi 200
        // supaya tidak mengulang terus. Verifikasi URL push dari console tanpa shop_id juga 200.
        if (! $shop) {
            if ($shopId) {
                // Toko tak dikenal: tetap cek tanda tangannya supaya ketahuan key-nya sudah benar atau belum.
                $signed = $shops->isEmpty() && collect(array_keys(ShopeeSetting::ENVIRONMENTS))->contains(fn (string $environment) => $this->verified($request, $environment));
                $reason = match (true) {
                    $signed => "Push ditolak: toko Shopee #{$shopId} belum terhubung di Optima (tanda tangan push sudah cocok).",
                    $shops->isEmpty() => "Push ditolak: toko Shopee #{$shopId} belum terhubung di Optima, dan tanda tangan push juga tidak cocok. Cek Live Push Partner Key.",
                    default => 'Push ditolak: tanda tangan tidak cocok. Isi Live Push Partner Key dari menu Push Mechanism di console Shopee.',
                };
                $this->remember($reason);
                Log::warning('Shopee push ditolak', ['shop_id' => $shopId, 'code' => $code, 'reason' => $reason, 'url' => $request->fullUrl()]);
            }

            return response()->noContent(200);
        }

        $this->remember(match (true) {
            $code === 2 => "Push diterima: otorisasi toko {$shop->displayName()} dicabut.",
            ! $shop->isSyncable() => "Push diterima untuk {$shop->displayName()}, tapi toko belum bisa disinkron (cek status, aktif & gudang pengirim).",
            default => "Push diterima untuk {$shop->displayName()} (code {$code}).",
        });

        $orderSn = (string) ($data['ordersn'] ?? $data['order_sn'] ?? '');

        // Simpan dulu, proses lewat antrean (diulang otomatis bila gagal). Push dobel diabaikan.
        if ($code === 2 || ($orderSn !== '' && in_array($code, [3, 4], true))) {
            $event = MarketplaceWebhookEvent::record('shopee', $shop, $code, $orderSn, $payload, $request->getContent());
            $event && $this->dispatchEvent($event);
        }

        return response()->noContent(200);
    }

    /** Code 12: perbarui batas otorisasi semua toko supaya peringatannya muncul di Notifikasi. */
    private function authorizationExpiry(Request $request, ?ShopeeShop $shop, array $payload): Response
    {
        $verified = $shop || collect(array_keys(ShopeeSetting::ENVIRONMENTS))->contains(fn (string $environment) => $this->verified($request, $environment));

        if (! $verified) {
            $this->remember('Push ditolak: tanda tangan tidak cocok. Isi Live Push Partner Key dari menu Push Mechanism di console Shopee.');

            return response()->noContent(200);
        }

        $this->remember('Push diterima: otorisasi Shopee akan segera berakhir. Hubungkan ulang toko sebelum batasnya.');
        $event = MarketplaceWebhookEvent::record('shopee', $shop, 12, null, $payload, $request->getContent());
        $event && $this->dispatchEvent($event);

        return response()->noContent(200);
    }

    private function verified(Request $request, string $environment): bool
    {
        $setting = ShopeeSetting::current();
        $keys = $setting->pushSigningKeys($environment);
        $signature = strtolower(trim((string) $request->header('Authorization')));

        if (! $keys || $signature === '') {
            return false;
        }

        // Shopee menandatangani URL yang didaftarkan di console. Di belakang proxy / Cloudflare
        // Laravel bisa melihat http:// padahal yang didaftarkan https://, jadi coba keduanya.
        $full = $request->fullUrl();
        $urls = array_unique(array_filter([
            $full,
            preg_replace('#^http://#', 'https://', $full),
            preg_replace('#^https://#', 'http://', $full),
            $setting->webhookUrl(),
        ]));

        foreach ($keys as $key) {
            foreach ($urls as $url) {
                if (hash_equals(hash_hmac('sha256', $url.'|'.$request->getContent(), $key), $signature)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Catat push terakhir supaya terlihat di Pengaturan Shopee. */
    private function remember(string $result): void
    {
        ShopeeSetting::query()->first()?->forceFill(['last_push_at' => now(), 'last_push_result' => mb_substr($result, 0, 255)])->saveQuietly();
    }
}
