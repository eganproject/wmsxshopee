<?php

namespace App\Services\Tiktok;

use App\Models\MarketplaceWebhookEvent;
use App\Models\TiktokShop;

/**
 * Proses webhook TikTok yang sudah tersimpan (dipanggil job antrean):
 *   type 1 / 3 / 4 / 11  pesanan berubah -> sinkron pesanan itu
 *   type 2 / 12          retur berubah   -> sinkron retur pesanan itu
 * Type 6 & 7 (otorisasi) langsung ditangani controller karena tidak memanggil API.
 * Error dilempar supaya job mengulang.
 */
class TiktokWebhookHandler
{
    public const ORDER_EVENTS = [1, 3, 4, 11];

    public const RETURN_EVENTS = [2, 12];

    public function __construct(private readonly TiktokOrderSync $orders, private readonly TiktokReturnSync $returns) {}

    /** @return bool false bila webhook tidak perlu diproses */
    public function handle(MarketplaceWebhookEvent $event): bool
    {
        $shop = TiktokShop::find($event->shop_ref);

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
