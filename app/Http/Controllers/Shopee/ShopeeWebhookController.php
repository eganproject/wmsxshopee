<?php

namespace App\Http\Controllers\Shopee;

use App\Http\Controllers\Controller;
use App\Models\ShopeeSetting;
use App\Models\ShopeeShop;
use App\Services\Shopee\ShopeeAuth;
use App\Services\Shopee\ShopeeClient;
use App\Services\Shopee\ShopeeException;
use App\Services\Shopee\ShopeeOrderSync;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Push Mechanism Shopee: Shopee mengirim POST ke URL ini saat status pesanan
 * berubah (code 3), resi terbit (code 4) atau otorisasi toko dicabut (code 2).
 * Tanda tangan di header Authorization = HMAC-SHA256(key, url + "|" + body), dengan key =
 * Live Push Partner Key dari menu Push Mechanism (app lama: partner key app).
 * Daftarkan URL ini di console app Shopee (Push Mechanism) bila Optima bisa
 * diakses dari internet; tanpa itu sinkron terjadwal tetap berjalan.
 */
class ShopeeWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $payload = $request->json()->all();
        $shopId = (int) ($payload['shop_id'] ?? $payload['data']['shop_id'] ?? 0);
        $shops = ShopeeShop::where('shop_id', $shopId)->get();
        $shop = $shops->first(fn (ShopeeShop $shop) => $this->verified($request, $shop->environment));
        $code = (int) ($payload['code'] ?? 0);
        $data = $payload['data'] ?? [];

        // Push untuk toko tak dikenal / tanda tangan salah diabaikan; Shopee tetap diberi 200
        // supaya tidak mengulang terus. Verifikasi URL push dari console tanpa shop_id juga 200.
        if (! $shop) {
            if ($shopId) {
                $reason = $shops->isEmpty()
                    ? "Push ditolak: toko Shopee #{$shopId} belum terhubung di Optima."
                    : 'Push ditolak: tanda tangan tidak cocok. Isi Live Push Partner Key dari menu Push Mechanism di console Shopee.';
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

        // Otorisasi dicabut: pastikan lewat get_shop_info dulu, baru tandai perlu otorisasi ulang.
        if ($code === 2) {
            dispatch(function () use ($shop) {
                try {
                    app(ShopeeAuth::class)->syncShopInfo(ShopeeClient::make($shop->environment), $shop);
                } catch (ShopeeException $e) {
                    $shop->update([
                        'last_error' => 'Otorisasi toko dicabut dari Shopee. Hubungkan ulang toko. ('.$e->getMessage().')',
                        'status' => $e->needsReauthorization() ? 'expired' : $shop->status,
                    ]);
                }
            })->afterResponse();

            return response()->noContent(200);
        }
        $orderSn = (string) ($data['ordersn'] ?? $data['order_sn'] ?? '');

        if ($orderSn !== '' && $shop->isSyncable() && in_array($code, [3, 4], true)) {
            dispatch(function () use ($shop, $code, $data, $orderSn) {
                try {
                    $sync = app(ShopeeOrderSync::class);
                    $code === 4 && filled($data['tracking_no'] ?? null)
                        ? $sync->applyTracking($shop, $orderSn, $data['tracking_no'], $data['package_number'] ?? null)
                        : $sync->syncOne($shop, $orderSn);
                } catch (ShopeeException $e) {
                    Log::warning('Shopee push gagal diproses', ['shop_id' => $shop->shop_id, 'order_sn' => $orderSn, 'error' => $e->getMessage()]);
                }
            })->afterResponse();
        }

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
