<?php

namespace App\Http\Controllers\Shopee;

use App\Http\Controllers\Controller;
use App\Models\ShopeeSetting;
use App\Models\ShopeeShop;
use App\Services\Shopee\ShopeeException;
use App\Services\Shopee\ShopeeOrderSync;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Push Mechanism Shopee: Shopee mengirim POST ke URL ini saat status pesanan
 * berubah (code 3) atau resi terbit (code 4). Tanda tangan di header
 * Authorization = HMAC-SHA256(partner_key, url + "|" + body).
 * Daftarkan URL ini di console app Shopee (Push Mechanism) bila Optima bisa
 * diakses dari internet; tanpa itu sinkron terjadwal tetap berjalan.
 */
class ShopeeWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $payload = $request->json()->all();
        $shops = ShopeeShop::where('shop_id', (int) ($payload['shop_id'] ?? 0))->get();
        $shop = $shops->first(fn (ShopeeShop $shop) => $this->verified($request, $shop->environment));

        // Push untuk toko tak dikenal / tanda tangan salah diabaikan; Shopee tetap diberi 200
        // supaya tidak mengulang terus. Verifikasi URL push dari console tanpa shop_id juga 200.
        if (! $shop) {
            return response()->noContent(200);
        }

        $code = (int) ($payload['code'] ?? 0);
        $data = $payload['data'] ?? [];
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
        $key = ShopeeSetting::current()->partnerKey($environment);
        $signature = (string) $request->header('Authorization');

        if (blank($key) || $signature === '') {
            return false;
        }

        $setting = ShopeeSetting::current();
        $urls = array_unique(array_filter([
            $request->fullUrl(),
            $setting->redirect_base_url ? rtrim($setting->redirect_base_url, '/').route('shopee.webhook', absolute: false) : null,
        ]));

        foreach ($urls as $url) {
            if (hash_equals(hash_hmac('sha256', $url.'|'.$request->getContent(), $key), $signature)) {
                return true;
            }
        }

        return false;
    }
}
