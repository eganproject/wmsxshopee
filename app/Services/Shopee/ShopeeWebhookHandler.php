<?php

namespace App\Services\Shopee;

use App\Models\MarketplaceWebhookEvent;
use App\Models\ShopeeShop;

/**
 * Proses push Shopee yang sudah tersimpan (dipanggil job antrean):
 *   code 2  otorisasi toko dicabut -> pastikan lewat get_shop_info, lalu tandai perlu otorisasi ulang
 *   code 3  status pesanan berubah -> sinkron pesanan itu
 *   code 4  resi terbit            -> simpan resi
 *   code 12 otorisasi akan berakhir -> perbarui batas otorisasi semua toko (muncul di Notifikasi)
 * Error dilempar supaya job mengulang.
 */
class ShopeeWebhookHandler
{
    public function __construct(private readonly ShopeeOrderSync $orders, private readonly ShopeeAuth $auth) {}

    /** @return bool false bila push tidak perlu diproses */
    public function handle(MarketplaceWebhookEvent $event): bool
    {
        if ($event->event_type === 12) {
            $this->refreshAuthorizationExpiry();

            return true;
        }

        $shop = ShopeeShop::find($event->shop_ref);
        $data = $event->payload['data'] ?? [];

        if (! $shop) {
            return false;
        }

        if ($event->event_type === 2) {
            try {
                $this->auth->syncShopInfo(ShopeeClient::make($shop->environment), $shop);
            } catch (ShopeeException $e) {
                $shop->update([
                    'last_error' => 'Otorisasi toko dicabut dari Shopee. Hubungkan ulang toko. ('.$e->getMessage().')',
                    'status' => $e->needsReauthorization() ? 'expired' : $shop->status,
                ]);
            }

            return true;
        }

        if (! $shop->isSyncable() || blank($event->order_id) || ! in_array($event->event_type, [3, 4], true)) {
            return false;
        }

        $event->event_type === 4 && filled($data['tracking_no'] ?? null)
            ? $this->orders->applyTracking($shop, $event->order_id, $data['tracking_no'], $data['package_number'] ?? null)
            : $this->orders->syncOne($shop, $event->order_id);

        return true;
    }

    /** Ambil ulang batas otorisasi (expire_time) semua toko yang terhubung. */
    private function refreshAuthorizationExpiry(): void
    {
        foreach (ShopeeShop::where('status', 'connected')->where('is_active', true)->get() as $shop) {
            try {
                $this->auth->syncShopInfo(ShopeeClient::make($shop->environment), $shop);
            } catch (ShopeeException $e) {
                $shop->update(['last_error' => mb_substr($e->getMessage(), 0, 500)]);
            }
        }
    }
}
