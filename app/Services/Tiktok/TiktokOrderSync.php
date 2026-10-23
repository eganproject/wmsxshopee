<?php

namespace App\Services\Tiktok;

use App\Models\Courier;
use App\Models\SalesOrder;
use App\Models\TiktokShop;
use App\Models\TiktokSkuMapping;
use App\Services\SalesOrderService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tarik pesanan TikTok Shop ke Pesanan Optima.
 *
 *   POST /order/202309/orders/search  -> pesanan yang berubah (update_time), isi lengkap
 *   GET  /order/202309/orders?ids=    -> detail pesanan tertentu (maks 50), dipakai webhook
 *
 * Satu line_item TikTok = satu unit barang, jadi baris dijumlahkan per sku_id.
 *
 * Status TikTok -> Optima:
 *   AWAITING_SHIPMENT / PARTIALLY_SHIPPING / AWAITING_COLLECTION
 *                                 pesanan dibuat & dikonfirmasi (stok dicadangkan)
 *   UNPAID / ON_HOLD              diabaikan sampai siap dikirim (ON_HOLD = masa
 *                                 pembeli masih bisa batal tanpa persetujuan)
 *   CANCELLED                     pesanan dibatalkan (cadangan lepas) bila belum dikirim
 *   IN_TRANSIT / DELIVERED / COMPLETED
 *                                 paket di tangan kurir: pesanan yang belum Dikirim di
 *                                 Optima otomatis di-pick, dikemas & dikirim
 * Pesanan Fulfilled by TikTok (gudang TikTok) tidak diproses gudang Optima.
 */
class TiktokOrderSync
{
    private const FIRST_SYNC_DAYS = 3;

    private const WINDOW_DAYS = 15;

    /** @var Collection<int, Courier>|null */
    private ?Collection $couriers = null;

    public function __construct(private readonly SalesOrderService $orders) {}

    /**
     * @return array{created: int, updated: int, cancelled: int, pending: int}
     */
    public function sync(TiktokShop $shop, ?TiktokShopApi $api = null): array
    {
        $api ??= TiktokShopApi::for($shop);
        $stats = ['created' => 0, 'updated' => 0, 'cancelled' => 0, 'pending' => 0];

        $from = ($shop->orders_synced_until?->copy()->subMinutes(10)) ?? now()->subDays(self::FIRST_SYNC_DAYS);
        $now = now();

        while ($from->lt($now)) {
            $to = $from->copy()->addDays(self::WINDOW_DAYS)->min($now);
            $pageToken = null;

            do {
                $page = $api->post('/order/orders/search', [
                    'update_time_ge' => $from->getTimestamp(),
                    'update_time_lt' => $to->getTimestamp(),
                ], array_filter(['page_size' => 100, 'sort_field' => 'update_time', 'sort_order' => 'ASC', 'page_token' => $pageToken]));

                foreach ($page['orders'] ?? [] as $detail) {
                    $result = $this->apply($shop, $detail);
                    $stats[$result] = ($stats[$result] ?? 0) + 1;
                }

                $pageToken = $page['next_page_token'] ?? null;
            } while (filled($pageToken));

            $shop->update(['orders_synced_until' => $to]);
            $from = $to;
        }

        // Draft yang SKU-nya baru dipetakan sejak sinkron sebelumnya ikut diproses.
        $stats['updated'] += $this->relinkPending($shop, $api);

        return array_intersect_key($stats, array_flip(['created', 'updated', 'cancelled', 'pending']));
    }

    /** Sinkron satu pesanan (dari webhook TikTok). */
    public function syncOne(TiktokShop $shop, string $orderId, ?TiktokShopApi $api = null): ?SalesOrder
    {
        $api ??= TiktokShopApi::for($shop);

        foreach ($api->get('/order/orders', ['ids' => $orderId])['orders'] ?? [] as $detail) {
            $this->apply($shop, $detail);
        }

        return $this->find($shop, $orderId);
    }

    /**
     * Terapkan satu pesanan TikTok ke Optima.
     *
     * @param  array<string, mixed>  $detail
     * @return string created | updated | cancelled | pending | skipped
     */
    public function apply(TiktokShop $shop, array $detail): string
    {
        // Satu pesanan diproses satu per satu: webhook & sinkron terjadwal bisa datang bersamaan.
        return Cache::lock('tiktok:order:'.$shop->id.':'.(string) $detail['id'], 120)
            ->block(60, fn () => $this->applyLocked($shop, $detail));
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function applyLocked(TiktokShop $shop, array $detail): string
    {
        $orderId = (string) $detail['id'];
        $status = (string) ($detail['status'] ?? '');
        $order = $this->find($shop, $orderId);

        if (($detail['fulfillment_type'] ?? null) === 'FULFILLMENT_BY_TIKTOK') {
            return 'skipped';
        }

        if (! $order) {
            if (! in_array($status, SalesOrder::TIKTOK_TO_FULFIL, true)) {
                return 'skipped';
            }

            $order = $this->create($shop, $detail);

            return $order->status === 'draft' ? 'pending' : 'created';
        }

        $order->fill([
            'tiktok_order_status' => $status,
            'tiktok_synced_at' => now(),
            'ordered_at' => $order->ordered_at ?? (isset($detail['create_time']) ? Carbon::createFromTimestamp((int) $detail['create_time'], config('app.timezone')) : null),
            'tiktok_package_id' => $this->packageId($detail) ?? $order->tiktok_package_id,
            'tiktok_ship_by' => $this->shipBy($detail) ?? $order->tiktok_ship_by,
            'tiktok_payment' => $detail['payment'] ?? $order->tiktok_payment,
            'tiktok_cancel_requested' => (bool) ($detail['is_buyer_request_cancel'] ?? false),
        ]);

        if (filled($tracking = $this->trackingNumber($detail)) && $order->status !== 'cancelled') {
            $order->tracking_number = $tracking;
        }

        $leftWarehouse = in_array($status, SalesOrder::TIKTOK_LEFT_WAREHOUSE, true);
        if ($leftWarehouse && ! $order->tiktok_shipped_at) {
            $order->tiktok_shipped_at = now();
        }

        if ($status === 'CANCELLED') {
            return $this->cancel($order, $detail);
        }

        if ($order->tiktok_cancel_requested && $order->status !== 'shipped') {
            $order->tiktok_sync_error = 'Pembeli mengajukan pembatalan di TikTok. Tahan pengiriman sampai ada keputusan di Seller Center.';
        } elseif ($order->status !== 'draft') {
            // Pengajuan batal ditolak / sudah lanjut: peringatan lama tidak berlaku lagi.
            $order->tiktok_sync_error = null;
        }

        $order->save();

        $retried = $order->status === 'draft' && (in_array($status, SalesOrder::TIKTOK_TO_FULFIL, true) || $leftWarehouse);
        if ($retried) {
            $this->fillAndConfirm($order, $shop, $detail);
        }

        if ($leftWarehouse && $order->status !== 'shipped') {
            $this->shipFromTiktok($order);
        }

        return $retried && $order->status === 'draft' ? 'pending' : 'updated';
    }

    public function find(TiktokShop $shop, string $orderId): ?SalesOrder
    {
        return SalesOrder::withoutGlobalScope('warehouse_access')
            ->where('tiktok_shop_id', $shop->id)
            ->where('external_number', $orderId)
            ->latest('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function create(TiktokShop $shop, array $detail): SalesOrder
    {
        $address = $detail['recipient_address'] ?? [];

        $order = DB::transaction(fn () => SalesOrder::create([
            'number' => SalesOrder::nextNumber(),
            'status' => 'draft',
            'warehouse_id' => $shop->warehouse_id,
            'order_date' => Carbon::createFromTimestamp((int) ($detail['create_time'] ?? now()->getTimestamp()), config('app.timezone'))->toDateString(),
            'ordered_at' => isset($detail['create_time']) ? Carbon::createFromTimestamp((int) $detail['create_time'], config('app.timezone')) : now(),
            'channel_name' => $shop->channelName(),
            'external_number' => (string) $detail['id'],
            'tiktok_shop_id' => $shop->id,
            'tiktok_order_status' => $detail['status'],
            'tiktok_package_id' => $this->packageId($detail),
            'tiktok_synced_at' => now(),
            'tiktok_ship_by' => $this->shipBy($detail),
            'tiktok_payment' => $detail['payment'] ?? null,
            'tiktok_cancel_requested' => (bool) ($detail['is_buyer_request_cancel'] ?? false),
            'tracking_number' => $this->trackingNumber($detail),
            'customer_name' => mb_substr($address['name'] ?? 'Pembeli TikTok', 0, 150),
            'customer_phone' => isset($address['phone_number']) ? mb_substr($address['phone_number'], 0, 30) : null,
            'shipping_address' => $address['full_address'] ?? $address['address_detail'] ?? null,
            'courier_id' => $this->courierFor($detail),
            'courier_service' => isset($detail['delivery_option_name']) ? mb_substr($detail['delivery_option_name'], 0, 50) : null,
            'shipping_fee' => (float) ($detail['payment']['shipping_fee'] ?? 0),
            'notes' => filled($detail['buyer_message'] ?? null) ? 'Pesan pembeli: '.$detail['buyer_message'] : null,
        ]));

        $this->fillAndConfirm($order, $shop, $detail);

        if ($order->tiktok_cancel_requested) {
            $order->update(['tiktok_sync_error' => 'Pembeli mengajukan pembatalan di TikTok. Tahan pengiriman sampai ada keputusan di Seller Center.']);
        }

        return $order;
    }

    /**
     * Isi baris dari line_items TikTok lewat pemetaan SKU, lalu konfirmasi.
     * line_items disimpan di pesanan supaya bisa dihubungkan ulang tanpa menarik dari API.
     *
     * @param  array<string, mixed>  $detail
     */
    private function fillAndConfirm(SalesOrder $order, TiktokShop $shop, array $detail): void
    {
        $lineItems = $detail['line_items'] ?? [];
        [$items, $bundles, $unmapped] = $this->lines($shop, $lineItems);

        DB::transaction(fn () => $this->orders->fillLines($order, $items, $bundles));

        if ($unmapped) {
            $order->update([
                'marketplace_lines' => $lineItems,
                'unmapped_skus' => $unmapped,
                'tiktok_sync_error' => mb_substr('SKU TikTok belum dipetakan: '.implode(', ', $unmapped).'. Petakan di Toko Marketplace › Pemetaan SKU (atau tambahkan produknya dengan SKU yang sama); pesanan terhubung otomatis setelah itu.', 0, 500),
            ]);

            return;
        }

        $order->update(['marketplace_lines' => $lineItems, 'unmapped_skus' => null]);

        try {
            $this->orders->confirm($order);
            $order->refresh()->update(['tiktok_sync_error' => null]);
        } catch (ValidationException $e) {
            $order->update(['tiktok_sync_error' => mb_substr('Belum bisa dikonfirmasi: '.implode(' ', $e->validator->errors()->all()), 0, 500)]);
        }
    }

    /**
     * Draft toko ini yang tertahan karena SKU belum dipetakan.
     *
     * @return Builder<SalesOrder>
     */
    public function pendingRelink(TiktokShop $shop): Builder
    {
        return SalesOrder::withoutGlobalScope('warehouse_access')
            ->where('tiktok_shop_id', $shop->id)
            ->where('status', 'draft')
            ->whereNotNull('unmapped_skus')
            ->whereIn('tiktok_order_status', [...SalesOrder::TIKTOK_TO_FULFIL, ...SalesOrder::TIKTOK_LEFT_WAREHOUSE]);
    }

    /**
     * Hubungkan ulang draft yang tertahan SKU belum dipetakan. Memakai line_items
     * tersimpan; pesanan lama tanpa salinan ditarik ulang dari API bila $api ada.
     *
     * @return int pesanan yang kini semua SKU-nya terhubung
     */
    public function relinkPending(TiktokShop $shop, ?TiktokShopApi $api = null): int
    {
        $orders = $this->pendingRelink($shop)->orderBy('id')->limit(200)->get();

        if ($orders->isEmpty()) {
            return 0;
        }

        app(TiktokCatalog::class)->autoMatch($shop);
        $linked = 0;

        foreach ($orders as $order) {
            try {
                if ($order->marketplace_lines !== null) {
                    $this->relink($order, $shop);
                } elseif ($api) {
                    $this->syncOne($shop, $order->external_number, $api);
                } else {
                    continue;
                }
            } catch (TiktokException $e) {
                report($e);

                continue;
            }

            $linked += $order->refresh()->unmapped_skus === null ? 1 : 0;
        }

        return $linked;
    }

    /**
     * Isi ulang baris draft dari line_items tersimpan lalu coba konfirmasi.
     * Tanpa $force, dilewati bila SKU yang belum dipetakan masih sama.
     */
    public function relink(SalesOrder $order, TiktokShop $shop, bool $force = false): void
    {
        if ($order->status !== 'draft' || $order->marketplace_lines === null) {
            return;
        }

        if (! $force) {
            [, , $unmapped] = $this->lines($shop, $order->marketplace_lines);
            if ($unmapped && $unmapped === ($order->unmapped_skus ?? [])) {
                return;
            }
        }

        $this->fillAndConfirm($order, $shop, ['line_items' => $order->marketplace_lines]);

        if (in_array($order->tiktok_order_status, SalesOrder::TIKTOK_LEFT_WAREHOUSE, true) && $order->refresh()->status !== 'shipped') {
            $this->shipFromTiktok($order);
        }
    }

    /**
     * line_items TikTok (satu per unit) -> baris varian & paket Optima, dijumlah per sku_id.
     * SKU yang belum ada di tabel pemetaan ikut didaftarkan dan dicocokkan otomatis.
     *
     * @param  list<array<string, mixed>>  $lineItems
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: list<string>}
     */
    private function lines(TiktokShop $shop, array $lineItems): array
    {
        $groups = collect($lineItems)
            ->reject(fn ($line) => ($line['display_status'] ?? null) === 'CANCELLED' || blank($line['sku_id'] ?? null))
            ->groupBy(fn ($line) => (string) $line['sku_id']);

        $newRows = false;
        $rows = [];

        foreach ($groups as $skuId => $lines) {
            $line = $lines->first();
            $mapping = $shop->mappings()->firstOrCreate(
                ['sku_id' => (string) $skuId],
                [
                    'product_id' => (string) ($line['product_id'] ?? ''),
                    'seller_sku' => filled($line['seller_sku'] ?? null) ? trim($line['seller_sku']) : null,
                    'product_name' => isset($line['product_name']) ? mb_substr($line['product_name'], 0, 255) : null,
                    'sku_name' => $line['sku_name'] ?? null,
                ],
            );
            $newRows = $newRows || $mapping->wasRecentlyCreated;
            $rows[] = [$mapping, $line, $lines->count()];
        }

        if ($newRows) {
            app(TiktokCatalog::class)->autoMatch($shop);
        }

        $items = [];
        $bundles = [];
        $unmapped = [];

        foreach ($rows as [$mapping, $line, $qty]) {
            /** @var TiktokSkuMapping $mapping */
            $mapping->refresh();
            $price = (float) ($line['sale_price'] ?? $line['original_price'] ?? 0);
            $note = mb_substr(trim(($line['product_name'] ?? '').' '.($line['sku_name'] ?? '')), 0, 255) ?: null;

            if ($mapping->product_variant_id) {
                $items[] = ['product_variant_id' => $mapping->product_variant_id, 'qty' => $qty, 'price' => $price, 'notes' => $note];
            } elseif ($mapping->bundle_id) {
                $bundles[] = ['bundle_id' => $mapping->bundle_id, 'qty' => $qty, 'price' => $price, 'notes' => $note];
            } else {
                $unmapped[] = $mapping->seller_sku ?: ($line['product_name'] ?? 'SKU '.$mapping->sku_id);
            }
        }

        return [$items, $bundles, array_values(array_unique($unmapped))];
    }

    private function shipFromTiktok(SalesOrder $order): void
    {
        $prefix = 'Sudah dikirim di TikTok, tapi belum bisa dikirim otomatis di Optima: ';

        if ($order->status === 'draft') {
            $order->update(['tiktok_sync_error' => mb_substr($prefix.($order->tiktok_sync_error ?? 'pesanan masih draft.'), 0, 500)]);

            return;
        }

        try {
            $this->orders->shipFromMarketplace($order);
            $order->refresh()->update(['tiktok_sync_error' => null]);
        } catch (ValidationException $e) {
            $order->refresh()->update(['tiktok_sync_error' => mb_substr($prefix.implode(' ', $e->validator->errors()->all()), 0, 500)]);
        }
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function cancel(SalesOrder $order, array $detail): string
    {
        $reason = 'Dibatalkan di TikTok'.(filled($detail['cancel_reason'] ?? null) ? ': '.$detail['cancel_reason'] : '');

        if ($order->status === 'cancelled') {
            $order->save();

            return 'updated';
        }

        if ($order->isCancellable()) {
            $order->tiktok_sync_error = null;
            $order->save();
            $this->orders->cancel($order, mb_substr($reason, 0, 255));

            return 'cancelled';
        }

        $order->tiktok_sync_error = $reason.' setelah pesanan dikirim dari gudang. Tunggu paket kembali lalu catat di Retur Penjualan.';
        $order->save();

        return 'updated';
    }

    /**
     * Batas siap kirim dari TikTok (rts_sla_time); lewat dari ini toko kena penalti.
     *
     * @param  array<string, mixed>  $detail
     */
    private function shipBy(array $detail): ?Carbon
    {
        $time = $detail['rts_sla_time'] ?? $detail['shipping_due_time'] ?? null;

        return filled($time) ? Carbon::createFromTimestamp((int) $time, config('app.timezone')) : null;
    }

    /** @param  array<string, mixed>  $detail */
    private function packageId(array $detail): ?string
    {
        $id = $detail['packages'][0]['id'] ?? $detail['line_items'][0]['package_id'] ?? null;

        return filled($id) ? (string) $id : null;
    }

    /** @param  array<string, mixed>  $detail */
    private function trackingNumber(array $detail): ?string
    {
        $tracking = $detail['tracking_number'] ?? collect($detail['line_items'] ?? [])->pluck('tracking_number')->filter()->first();

        return filled($tracking) ? mb_substr((string) $tracking, 0, 100) : null;
    }

    /**
     * Kurir Optima dari nama jasa kirim TikTok (shipping_provider), cth. "J&T Express".
     *
     * @param  array<string, mixed>  $detail
     */
    private function courierFor(array $detail): ?int
    {
        $couriers = $this->couriers ??= Courier::where('is_active', true)->get(['id', 'code', 'name']);
        $carrier = mb_strtolower((string) ($detail['shipping_provider'] ?? $detail['line_items'][0]['shipping_provider_name'] ?? ''));

        if ($carrier === '') {
            return null;
        }

        return $couriers->first(fn (Courier $c) => str_contains($carrier, mb_strtolower($c->code)) || str_contains($carrier, mb_strtolower(explode(' ', $c->name)[0])))?->id;
    }
}
