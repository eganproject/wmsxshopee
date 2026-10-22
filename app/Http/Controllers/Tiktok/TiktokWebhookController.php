<?php

namespace App\Http\Controllers\Tiktok;

use App\Http\Controllers\Concerns\QueuesWebhookEvents;
use App\Http\Controllers\Controller;
use App\Models\MarketplaceWebhookEvent;
use App\Models\TiktokShop;
use App\Services\Tiktok\TiktokClient;
use App\Services\Tiktok\TiktokWebhookHandler;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Webhook TikTok Shop. Header Authorization = HMAC-SHA256(app_secret, app_key + body).
 * Yang diproses:
 *   type 1  ORDER_STATUS_CHANGE                 -> sinkron pesanan itu
 *   type 3  RECIPIENT_ADDRESS_UPDATE            -> sinkron pesanan (alamat berubah)
 *   type 4  PACKAGE_UPDATE                      -> sinkron pesanan (resi / paket berubah)
 *   type 11 CANCELLATION_STATUS_CHANGE          -> sinkron pesanan (pengajuan batal)
 *   type 2 / 12 REVERSE / RETURN_STATUS_CHANGE  -> sinkron retur pesanan itu
 *   type 5 / 15 / 16 PRODUCT_STATUS / INFORMATION_CHANGE / CREATION -> tarik ulang produk toko
 *   type 6  SELLER_DEAUTHORIZATION              -> toko ditandai perlu otorisasi ulang
 *   type 7  UPCOMING_AUTHORIZATION_EXPIRATION   -> peringatan di daftar toko
 * Webhook pesanan & retur disimpan di marketplace_webhook_events lalu diproses
 * job antrean (ProcessMarketplaceWebhook -> TiktokWebhookHandler).
 * Daftarkan URL ini di Partner Center › App › Manage Webhooks bila Optima bisa
 * diakses dari internet (HTTPS publik); tanpa itu sinkron terjadwal tetap berjalan.
 */
class TiktokWebhookController extends Controller
{
    use QueuesWebhookEvents;

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

        // Simpan dulu, proses lewat antrean (diulang otomatis bila gagal). Webhook dobel diabaikan.
        $wanted = ($orderId !== '' && in_array($type, [...TiktokWebhookHandler::ORDER_EVENTS, ...TiktokWebhookHandler::RETURN_EVENTS], true))
            || TiktokWebhookHandler::isProductEvent($type, $data);

        if ($wanted) {
            $event = MarketplaceWebhookEvent::record('tiktok', $shop, $type, $orderId, $payload, $request->getContent());
            $event && $this->dispatchEvent($event);
        }

        return response()->noContent(200);
    }
}
