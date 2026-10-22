<?php

namespace App\Services\Tiktok;

use App\Jobs\RefreshMarketplaceCatalog;
use App\Models\MarketplaceWebhookEvent;
use App\Models\TiktokShop;

/**
 * Proses webhook TikTok yang sudah tersimpan (dipanggil job antrean):
 *   type 1 / 3 / 4 / 11  pesanan berubah -> sinkron pesanan itu
 *   type 2 / 12          retur berubah   -> sinkron retur pesanan itu
 *   type 5 / 15 / 16     produk berubah / dibuat -> tarik ulang produk toko (dijeda, sekali per toko)
 * Type 6 & 7 (otorisasi) langsung ditangani controller karena tidak memanggil API.
 * Error dilempar supaya job mengulang.
 */
class TiktokWebhookHandler
{
    public const ORDER_EVENTS = [1, 3, 4, 11];

    public const RETURN_EVENTS = [2, 12];

    /** PRODUCT_STATUS_CHANGE, PRODUCT_INFORMATION_CHANGE, PRODUCT_CREATION. */
    public const PRODUCT_EVENTS = [5, 15, 16];

    /** Webhook produk: jenis di atas, atau isi yang membawa product_id tanpa order_id. */
    public static function isProductEvent(int $type, array $data): bool
    {
        return in_array($type, self::PRODUCT_EVENTS, true) || (filled($data['product_id'] ?? null) && blank($data['order_id'] ?? null));
    }

    public function __construct(private readonly TiktokOrderSync $orders, private readonly TiktokReturnSync $returns) {}

    /** @return bool false bila webhook tidak perlu diproses */
    public function handle(MarketplaceWebhookEvent $event): bool
    {
        $shop = TiktokShop::find($event->shop_ref);

        if ($shop && self::isProductEvent($event->event_type, $event->payload['data'] ?? [])) {
            RefreshMarketplaceCatalog::later('tiktok', $shop->id);

            return true;
        }

        if (! $shop?->isSyncable() || blank($event->order_id)) {
            return false;
        }

        if (in_array($event->event_type, self::RETURN_EVENTS, true)) {
            $this->returns->syncOrder($shop, $event->order_id);

            return true;
        }

        if (in_array($event->event_type, self::ORDER_EVENTS, true)) {
            $this->orders->syncOne($shop, $event->order_id);

            return true;
        }

        return false;
    }
}
