<?php

namespace App\Http\Controllers\Tiktok;

use App\Http\Controllers\Controller;
use App\Models\TiktokShop;
use App\Services\Tiktok\TiktokClient;
use App\Services\Tiktok\TiktokException;
use App\Services\Tiktok\TiktokOrderSync;
use App\Services\Tiktok\TiktokReturnSync;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Webhook TikTok Shop. Header Authorization = HMAC-SHA256(app_secret, app_key + body).
 * Yang diproses:
 *   type 1  ORDER_STATUS_CHANGE                 -> sinkron pesanan itu
 *   type 3  RECIPIENT_ADDRESS_UPDATE            -> sinkron pesanan (alamat berubah)
 *   type 4  PACKAGE_UPDATE                      -> sinkron pesanan (resi / paket berubah)
 *   type 11 CANCELLATION_STATUS_CHANGE          -> sinkron pesanan (pengajuan batal)
 *   type 2 / 12 REVERSE / RETURN_STATUS_CHANGE  -> sinkron retur pesanan itu
 *   type 6  SELLER_DEAUTHORIZATION              -> toko ditandai perlu otorisasi ulang
 *   type 7  UPCOMING_AUTHORIZATION_EXPIRATION   -> peringatan di daftar toko
 * Daftarkan URL ini di Partner Center › App › Manage Webhooks bila Optima bisa
 * diakses dari internet (HTTPS publik); tanpa itu sinkron terjadwal tetap berjalan.
 */
class TiktokWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        if (! TiktokClient::make()->verifyWebhook((string) $request->header('Authorization'), $request->getContent())) {
            return response()->noContent(401);
        }

        $payload = $request->json()->all();
        $shop = TiktokShop::where('shop_id', (string) ($payload['shop_id'] ?? ''))->first();

        // Toko tak dikenal tetap diberi 200 supaya TikTok tidak mengulang terus.
        if (! $shop) {
            return response()->noContent(200);
        }

        $type = (int) ($payload['type'] ?? 0);
        $data = $payload['data'] ?? [];

        if ($type === 6) {
            $shop->update(['status' => 'expired', 'last_error' => 'Otorisasi dicabut dari TikTok Shop Seller Center. Hubungkan ulang toko.']);

            return response()->noContent(200);
        }

        if ($type === 7) {
            $shop->update(['last_error' => 'Otorisasi toko di TikTok akan segera berakhir. Hubungkan ulang toko dari menu Toko Marketplace.']);

            return response()->noContent(200);
        }

        $orderId = (string) ($data['order_id'] ?? '');

        if ($orderId !== '' && in_array($type, [1, 2, 3, 4, 11, 12], true) && $shop->isSyncable()) {
            $returns = in_array($type, [2, 12], true);

            dispatch(function () use ($shop, $orderId, $returns) {
                try {
                    $returns
                        ? app(TiktokReturnSync::class)->syncOrder($shop, $orderId)
                        : app(TiktokOrderSync::class)->syncOne($shop, $orderId);
                } catch (TiktokException $e) {
                    Log::warning('Webhook TikTok gagal diproses', ['shop_id' => $shop->shop_id, 'order_id' => $orderId, 'error' => $e->getMessage()]);
                }
            })->afterResponse();
        }

        return response()->noContent(200);
    }
}
