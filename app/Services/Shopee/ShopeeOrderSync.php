<?php

namespace App\Services\Shopee;

use App\Models\Courier;
use App\Models\SalesOrder;
use App\Models\ShopeeShop;
use App\Models\ShopeeSkuMapping;
use App\Services\SalesOrderService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Tarik pesanan Shopee ke Pesanan Optima.
 *
 *   /order/get_order_list     -> order_sn yang berubah (update_time, jendela maks 15 hari)
 *   /order/get_order_detail   -> isi pesanan (maks 50 per panggilan)
 *   /logistics/get_tracking_number -> resi setelah pengiriman diatur di Shopee
 *
 * Status Shopee -> Optima:
 *   READY_TO_SHIP / PROCESSED / RETRY_SHIP  pesanan dibuat & dikonfirmasi (stok dicadangkan)
 *   CANCELLED                               pesanan dibatalkan (cadangan lepas) bila belum dikirim
 *   UNPAID                                  diabaikan sampai dibayar
 *   SHIPPED / TO_CONFIRM_RECEIVE / COMPLETED paket di tangan kurir: dicatat sebagai tanda
 *                                           resi berhasil dikirim; pesanan yang belum Dikirim
 *                                           di Optima otomatis di-pick, dikemas & dikirim.
 *                                           Pesanan yang belum pernah masuk Optima tidak
 *                                           dibuat (keluar tanpa lewat gudang)
 */
class ShopeeOrderSync
{
    /** Status Shopee yang berarti pesanan harus disiapkan gudang. */
    public const TO_FULFIL = SalesOrder::SHOPEE_TO_FULFIL;

    /** Status yang biasanya sudah punya resi. */
    public const HAS_TRACKING = ['PROCESSED', 'RETRY_SHIP', 'SHIPPED', 'TO_CONFIRM_RECEIVE', 'COMPLETED'];

    /** Saat toko pertama kali disinkron, pesanan sejauh ini ke belakang ikut ditarik. */
    private const FIRST_SYNC_DAYS = 3;

    private const WINDOW_DAYS = 15;

    /** @var Collection<int, Courier>|null */
    private ?Collection $couriers = null;

    public function __construct(private readonly SalesOrderService $orders) {}

    /**
     * Tarik semua pesanan yang berubah sejak sinkron terakhir.
     *
     * @return array{created: int, updated: int, cancelled: int, pending: int, failed: int}
     */
    public function sync(ShopeeShop $shop, ?ShopeeShopApi $api = null): array
    {
        $api ??= ShopeeShopApi::for($shop);
        $stats = ['created' => 0, 'updated' => 0, 'cancelled' => 0, 'pending' => 0, 'failed' => 0];
        $failed = [];
        $retry = Cache::pull($this->retryKey($shop), []);

        // Daftar coba-ulang selalu disimpan lagi, juga kalau API gagal di tengah jalan,
        // supaya pesanan yang gagal tidak hilang (jendela sinkron sudah lewat).
        try {
            $this->syncOrders($shop, $api, $stats, $failed, $retry);
        } finally {
            if ($pending = array_values(array_unique(array_merge($retry, $failed)))) {
                Cache::forever($this->retryKey($shop), $pending);
            }
        }

        return array_intersect_key($stats, array_flip(['created', 'updated', 'cancelled', 'pending', 'failed']));
    }

    /** @param  array<int, string>  $retry  dikosongkan setelah semua pesanan coba-ulang selesai diproses */
    private function syncOrders(ShopeeShop $shop, ShopeeShopApi $api, array &$stats, array &$failed, array &$retry): void
    {
        // Pesanan yang gagal diproses di sinkron sebelumnya dicoba lagi lebih dulu.
        foreach (array_chunk($retry, 50) as $chunk) {
            foreach ($this->details($api, $chunk) as $detail) {
                $this->count($stats, $this->applyGuarded($shop, $detail, $api, $failed));
            }
        }
        $retry = [];

        // Mundur 10 menit dari tanda terakhir supaya pesanan di batas jendela tidak terlewat.
        $from = ($shop->orders_synced_until?->copy()->subMinutes(10)) ?? now()->subDays(self::FIRST_SYNC_DAYS);
        $now = now();

        while ($from->lt($now)) {
            $to = $from->copy()->addDays(self::WINDOW_DAYS)->min($now);
            $orderSns = $this->changedOrders($api, $from, $to);

            foreach (array_chunk($orderSns, 50) as $chunk) {
                foreach ($this->details($api, $chunk) as $detail) {
                    $this->count($stats, $this->applyGuarded($shop, $detail, $api, $failed));
                }
            }

            $shop->update(['orders_synced_until' => $to]);
            $from = $to;
        }

        // Draft yang SKU-nya baru dipetakan sejak sinkron sebelumnya ikut diproses.
        $stats['updated'] += $this->relinkPending($shop, $api);

        $this->fetchMissingTracking($shop, $api);
    }

    /**
     * Terapkan satu pesanan tanpa menghentikan sinkron toko bila pesanan itu error
     * (selain error API Shopee, yang tetap menghentikan sinkron seperti biasa).
     * Pesanan yang gagal dicatat dan dicoba lagi di sinkron berikutnya.
     *
     * @param  array<string, mixed>  $detail
     * @param  list<string>  $failed
     */
    private function applyGuarded(ShopeeShop $shop, array $detail, ShopeeShopApi $api, array &$failed): string
    {
        try {
            $result = $this->apply($shop, $detail);
            $this->refreshIncome($shop, (string) $detail['order_sn'], $api);

            return $result;
        } catch (ShopeeException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            $failed[] = (string) $detail['order_sn'];

            return 'failed';
        }
    }

    /** @param  array<string, int>  $stats */
    private function count(array &$stats, string $result): void
    {
        $stats[$result] = ($stats[$result] ?? 0) + 1;
    }

    private function retryKey(ShopeeShop $shop): string
    {
        return "shopee:order-retry:{$shop->id}";
    }

    /** Sinkron satu pesanan (dari webhook push Shopee). */
    public function syncOne(ShopeeShop $shop, string $orderSn, ?ShopeeShopApi $api = null): ?SalesOrder
    {
        $api ??= ShopeeShopApi::for($shop);

        foreach ($this->details($api, [$orderSn]) as $detail) {
            $this->apply($shop, $detail);
            $this->refreshIncome($shop, $orderSn, $api);
        }

        $order = $this->find($shop, $orderSn);

        if ($order && blank($order->tracking_number) && in_array($order->shopee_order_status, self::HAS_TRACKING, true)) {
            $this->fetchTracking($order, $api);
        }

        return $order;
    }

    /** Simpan resi dari push Shopee (order_trackingno_push). */
    public function applyTracking(ShopeeShop $shop, string $orderSn, string $trackingNumber, ?string $packageNumber = null): void
    {
        $order = $this->find($shop, $orderSn);

        if ($order && $order->status !== 'cancelled' && filled($trackingNumber)) {
            // package_number dari push tidak disimpan: hanya pesanan split yang memakainya,
            // dan nomor paketnya sudah tercatat dari detail pesanan.
            $order->update(['tracking_number' => $trackingNumber]);
        }
    }

    /**
     * Terapkan satu detail pesanan Shopee ke Optima.
     *
     * @param  array<string, mixed>  $detail
     * @return string created | updated | cancelled | pending | skipped
     */
    public function apply(ShopeeShop $shop, array $detail): string
    {
        // Satu pesanan diproses satu per satu: webhook & sinkron terjadwal bisa datang bersamaan.
        return Cache::lock('shopee:order:'.$shop->id.':'.(string) $detail['order_sn'], 120)
            ->block(60, fn () => $this->applyLocked($shop, $detail));
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function applyLocked(ShopeeShop $shop, array $detail): string
    {
        $orderSn = (string) $detail['order_sn'];
        $status = (string) ($detail['order_status'] ?? '');
        $order = $this->find($shop, $orderSn);

        if (! $order) {
            if (! in_array($status, self::TO_FULFIL, true)) {
                return 'skipped';
            }

            $order = $this->create($shop, $detail);

            return $order->status === 'draft' ? 'pending' : 'created';
        }

        $order->fill([
            'shopee_order_status' => $status,
            'shopee_synced_at' => now(),
            'ordered_at' => $order->ordered_at ?? (isset($detail['create_time']) ? Carbon::createFromTimestamp($detail['create_time'], config('app.timezone')) : null),
            'shopee_package_number' => ShopeePackage::fromDetail($detail),
            'shopee_ship_by' => $this->shipBy($detail) ?? $order->shopee_ship_by,
        ]);

        $leftWarehouse = in_array($status, SalesOrder::SHOPEE_LEFT_WAREHOUSE, true);
        if ($leftWarehouse && ! $order->shopee_shipped_at) {
            $order->shopee_shipped_at = now();
        }

        if ($status === 'CANCELLED') {
            return $this->cancel($order, $detail);
        }

        if ($status === 'IN_CANCEL' && $order->status !== 'shipped') {
            $order->shopee_sync_error = 'Pembeli mengajukan pembatalan di Shopee. Tahan pengiriman sampai ada keputusan.';
        } elseif ($order->status !== 'draft') {
            // Pengajuan batal ditolak / sudah lanjut: peringatan lama tidak berlaku lagi.
            $order->shopee_sync_error = null;
        }

        $order->save();

        // Draft karena SKU belum dipetakan / stok kurang: coba lagi tiap sinkron.
        $retried = $order->status === 'draft' && (in_array($status, self::TO_FULFIL, true) || $leftWarehouse);
        if ($retried) {
            $this->fillAndConfirm($order, $shop, $detail);
        }

        if ($leftWarehouse && $order->status !== 'shipped') {
            $this->shipFromShopee($order);
        }

        return $retried && $order->status === 'draft' ? 'pending' : 'updated';
    }

    /**
     * Rincian pendapatan pesanan dari /payment/get_escrow_detail: harga, diskon,
     * voucher, biaya admin/layanan/transaksi, ongkir dan dana yang diterima penjual.
     * Diambil sekali saat pesanan masuk (perkiraan) dan sekali lagi saat Selesai (final).
     */
    public function refreshIncome(ShopeeShop $shop, string $orderSn, ShopeeShopApi $api): void
    {
        $order = $this->find($shop, $orderSn);

        if (! $order || $order->shopee_income_final || in_array($order->shopee_order_status, ['UNPAID', 'CANCELLED', 'IN_CANCEL'], true)) {
            return;
        }

        $final = $order->shopee_order_status === 'COMPLETED';

        if ($order->shopee_income !== null && ! $final) {
            return;
        }

        try {
            $income = $api->get('/api/v2/payment/get_escrow_detail', ['order_sn' => $orderSn])['response']['order_income'] ?? null;
        } catch (ShopeeException) {
            return; // Belum tersedia; dicoba lagi saat pesanan berubah.
        }

        if (! is_array($income)) {
            return;
        }

        $order->update([
            'shopee_income' => $income,
            'shopee_escrow_amount' => $income['escrow_amount'] ?? null,
            'shopee_income_final' => $final,
            'shopee_income_synced_at' => now(),
        ]);
    }

    public function find(ShopeeShop $shop, string $orderSn): ?SalesOrder
    {
        return SalesOrder::withoutGlobalScope('warehouse_access')
            ->where('shopee_shop_id', $shop->id)
            ->where('external_number', $orderSn)
            ->latest('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function create(ShopeeShop $shop, array $detail): SalesOrder
    {
        $address = $detail['recipient_address'] ?? [];
        $package = $detail['package_list'][0] ?? [];

        $order = DB::transaction(fn () => SalesOrder::create([
            'number' => SalesOrder::nextNumber(),
            'status' => 'draft',
            'warehouse_id' => $shop->warehouse_id,
            'order_date' => Carbon::createFromTimestamp($detail['create_time'] ?? now()->getTimestamp(), config('app.timezone'))->toDateString(),
            'ordered_at' => isset($detail['create_time']) ? Carbon::createFromTimestamp($detail['create_time'], config('app.timezone')) : now(),
            'channel_name' => $shop->channelName(),
            'external_number' => (string) $detail['order_sn'],
            'shopee_shop_id' => $shop->id,
            'shopee_order_status' => $detail['order_status'],
            'shopee_package_number' => ShopeePackage::fromDetail($detail),
            'shopee_synced_at' => now(),
            'shopee_ship_by' => $this->shipBy($detail),
            'customer_name' => mb_substr($address['name'] ?? $detail['buyer_username'] ?? 'Pembeli Shopee', 0, 150),
            'customer_phone' => isset($address['phone']) ? mb_substr($address['phone'], 0, 30) : null,
            'shipping_address' => $address['full_address'] ?? null,
            'courier_id' => $this->courierFor($detail, $package),
            'courier_service' => isset($detail['shipping_carrier']) ? mb_substr($detail['shipping_carrier'], 0, 50) : null,
            'shipping_fee' => $detail['actual_shipping_fee'] ?? $detail['estimated_shipping_fee'] ?? 0,
            'notes' => filled($detail['message_to_seller'] ?? null) ? 'Pesan pembeli: '.$detail['message_to_seller'] : null,
        ]));

        $this->fillAndConfirm($order, $shop, $detail);

        return $order;
    }

    /**
     * Isi baris dari item Shopee lewat pemetaan SKU, lalu konfirmasi (cadangkan stok).
     * Bila ada SKU belum dipetakan atau stok kurang, pesanan tetap draft dengan catatan.
     * Item Shopee disimpan di pesanan supaya bisa dihubungkan ulang tanpa menarik dari API.
     *
     * @param  array<string, mixed>  $detail
     */
    private function fillAndConfirm(SalesOrder $order, ShopeeShop $shop, array $detail): void
    {
        $itemList = $detail['item_list'] ?? [];
        [$items, $bundles, $unmapped] = $this->lines($shop, $itemList);

        DB::transaction(fn () => $this->orders->fillLines($order, $items, $bundles));

        if ($unmapped) {
            $order->update([
                'marketplace_lines' => $itemList,
                'unmapped_skus' => $unmapped,
                'shopee_sync_error' => mb_substr('SKU Shopee belum dipetakan: '.implode(', ', $unmapped).'. Petakan di Toko Marketplace › Pemetaan SKU (atau tambahkan produknya dengan SKU yang sama); pesanan terhubung otomatis setelah itu.', 0, 500),
            ]);

            return;
        }

        $order->update(['marketplace_lines' => $itemList, 'unmapped_skus' => null]);

        try {
            $this->orders->confirm($order);
            $order->refresh()->update(['shopee_sync_error' => null]);
        } catch (ValidationException $e) {
            $order->update(['shopee_sync_error' => mb_substr(SalesOrder::STOCK_HELD_PREFIX.implode(' ', $e->validator->errors()->all()), 0, 500)]);
        }
    }

    /**
     * Draft toko ini yang tertahan karena SKU belum dipetakan.
     *
     * @return Builder<SalesOrder>
     */
    public function pendingRelink(ShopeeShop $shop): Builder
    {
        return SalesOrder::withoutGlobalScope('warehouse_access')
            ->where('shopee_shop_id', $shop->id)
            ->where('status', 'draft')
            ->whereNotNull('unmapped_skus')
            ->whereIn('shopee_order_status', [...self::TO_FULFIL, ...SalesOrder::SHOPEE_LEFT_WAREHOUSE]);
    }

    /**
     * Hubungkan ulang draft yang tertahan SKU belum dipetakan (setelah pemetaan
     * atau produk dengan SKU yang sama ditambahkan). Memakai item Shopee yang
     * tersimpan; pesanan lama tanpa salinan item ditarik ulang dari API bila $api ada.
     *
     * @return int pesanan yang kini semua SKU-nya terhubung
     */
    public function relinkPending(ShopeeShop $shop, ?ShopeeShopApi $api = null): int
    {
        $orders = $this->pendingRelink($shop)->orderBy('id')->limit(200)->get();

        if ($orders->isEmpty()) {
            return 0;
        }

        app(ShopeeCatalog::class)->autoMatch($shop);
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
            } catch (ShopeeException $e) {
                report($e);

                continue;
            }

            $linked += $order->refresh()->unmapped_skus === null ? 1 : 0;
        }

        return $linked;
    }

    /**
     * Isi ulang baris draft dari item Shopee yang tersimpan lalu coba konfirmasi.
     * Tanpa $force, dilewati bila SKU yang belum dipetakan masih sama (tidak ada yang berubah).
     */
    public function relink(SalesOrder $order, ShopeeShop $shop, bool $force = false): void
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

        $this->fillAndConfirm($order, $shop, ['item_list' => $order->marketplace_lines]);

        if (in_array($order->shopee_order_status, SalesOrder::SHOPEE_LEFT_WAREHOUSE, true) && $order->refresh()->status !== 'shipped') {
            $this->shipFromShopee($order);
        }
    }

    /**
     * Item Shopee -> baris varian & baris paket Optima. Item yang belum ada di
     * tabel pemetaan ikut didaftarkan (dan dicocokkan otomatis lewat SKU).
     *
     * @param  list<array<string, mixed>>  $itemList
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: list<string>}
     */
    private function lines(ShopeeShop $shop, array $itemList): array
    {
        $items = [];
        $bundles = [];
        $unmapped = [];
        $rows = [];
        $newRows = false;

        foreach ($itemList as $line) {
            $mapping = $shop->mappings()->firstOrCreate(
                ['item_id' => $line['item_id'], 'model_id' => (int) ($line['model_id'] ?? 0)],
                [
                    'shopee_sku' => filled($sku = $line['model_sku'] ?? null) || filled($sku = $line['item_sku'] ?? null) ? trim($sku) : null,
                    'item_name' => $line['item_name'] ?? null,
                    'model_name' => $line['model_name'] ?? null,
                ],
            );
            $newRows = $newRows || $mapping->wasRecentlyCreated;

            $rows[] = [$mapping, $line];
        }

        if ($newRows) {
            app(ShopeeCatalog::class)->autoMatch($shop);
        }

        foreach ($rows as [$mapping, $line]) {
            /** @var ShopeeSkuMapping $mapping */
            $mapping->refresh();
            $qty = (int) ($line['model_quantity_purchased'] ?? 1);
            $price = $line['model_discounted_price'] ?? $line['model_original_price'] ?? 0;
            $note = mb_substr(trim(($line['item_name'] ?? '').' '.($line['model_name'] ?? '')), 0, 255) ?: null;

            if ($mapping->product_variant_id) {
                $items[] = ['product_variant_id' => $mapping->product_variant_id, 'qty' => $qty, 'price' => $price, 'notes' => $note];
            } elseif ($mapping->bundle_id) {
                $bundles[] = ['bundle_id' => $mapping->bundle_id, 'qty' => $qty, 'price' => $price, 'notes' => $note];
            } else {
                $unmapped[] = $mapping->shopee_sku ?: ($line['item_name'] ?? 'item '.$line['item_id']);
            }
        }

        return [$items, $bundles, array_values(array_unique($unmapped))];
    }

    /** Shopee sudah mengirim paket: ikutkan status gudang & keluarkan stok. */
    private function shipFromShopee(SalesOrder $order): void
    {
        $prefix = 'Sudah dikirim di Shopee, tapi belum bisa dikirim otomatis di Optima: ';

        if ($order->status === 'draft') {
            $order->update(['shopee_sync_error' => mb_substr($prefix.($order->shopee_sync_error ?? 'pesanan masih draft.'), 0, 500)]);

            return;
        }

        try {
            $this->orders->shipFromMarketplace($order);
            $order->refresh()->update(['shopee_sync_error' => null]);
        } catch (ValidationException $e) {
            $order->refresh()->update(['shopee_sync_error' => mb_substr($prefix.implode(' ', $e->validator->errors()->all()), 0, 500)]);
        }
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function cancel(SalesOrder $order, array $detail): string
    {
        $reason = 'Dibatalkan di Shopee'.(filled($detail['cancel_reason'] ?? null) ? ': '.$detail['cancel_reason'] : '');

        if ($order->status === 'cancelled') {
            $order->save();

            return 'updated';
        }

        if ($order->isCancellable()) {
            $order->shopee_sync_error = null;
            $order->save();
            $this->orders->cancel($order, mb_substr($reason, 0, 255));

            return 'cancelled';
        }

        $order->shopee_sync_error = $reason.' setelah pesanan dikirim dari gudang. Tunggu paket kembali lalu catat di Retur Penjualan.';
        $order->save();

        return 'updated';
    }

    /**
     * Batas kirim dari Shopee (ship_by_date); lewat dari ini pesanan bisa dibatalkan otomatis.
     *
     * @param  array<string, mixed>  $detail
     */
    private function shipBy(array $detail): ?Carbon
    {
        return filled($detail['ship_by_date'] ?? null) ? Carbon::createFromTimestamp((int) $detail['ship_by_date'], config('app.timezone')) : null;
    }

    /**
     * @return list<string>
     */
    private function changedOrders(ShopeeShopApi $api, Carbon $from, Carbon $to): array
    {
        $orderSns = [];
        $cursor = '';

        do {
            $page = $api->get('/api/v2/order/get_order_list', [
                'time_range_field' => 'update_time',
                'time_from' => $from->getTimestamp(),
                'time_to' => $to->getTimestamp(),
                'page_size' => 100,
                'cursor' => $cursor,
                'response_optional_fields' => 'order_status',
            ])['response'] ?? [];

            array_push($orderSns, ...array_column($page['order_list'] ?? [], 'order_sn'));
            $cursor = (string) ($page['next_cursor'] ?? '');
        } while (! empty($page['more']) && $cursor !== '');

        return array_values(array_unique($orderSns));
    }

    /**
     * @param  list<string>  $orderSns
     * @return list<array<string, mixed>>
     */
    private function details(ShopeeShopApi $api, array $orderSns): array
    {
        return $api->get('/api/v2/order/get_order_detail', [
            'order_sn_list' => implode(',', $orderSns),
            'response_optional_fields' => 'buyer_username,recipient_address,item_list,shipping_carrier,package_list,split_up,cancel_reason,estimated_shipping_fee,actual_shipping_fee,message_to_seller',
        ])['response']['order_list'] ?? [];
    }

    /** Ambil resi pesanan Shopee yang sudah diatur pengirimannya tapi resinya belum ada. */
    private function fetchMissingTracking(ShopeeShop $shop, ShopeeShopApi $api): void
    {
        SalesOrder::withoutGlobalScope('warehouse_access')
            ->where('shopee_shop_id', $shop->id)
            ->whereIn('status', SalesOrder::RESERVING)
            ->whereNull('tracking_number')
            ->whereIn('shopee_order_status', self::HAS_TRACKING)
            ->limit(100)
            ->get()
            ->each(fn (SalesOrder $order) => $this->fetchTracking($order, $api));
    }

    private function fetchTracking(SalesOrder $order, ShopeeShopApi $api): void
    {
        try {
            $response = ShopeePackage::call($order, fn (array $package) => $api->get('/api/v2/logistics/get_tracking_number', [
                'order_sn' => $order->external_number,
                ...$package,
            ]))['response'] ?? [];
        } catch (ShopeeException) {
            return; // Resi belum terbit; dicoba lagi di sinkron berikutnya.
        }

        if (filled($response['tracking_number'] ?? null)) {
            $order->update(['tracking_number' => $response['tracking_number']]);
        }
    }

    /**
     * Kurir Optima untuk pesanan: cocokkan ID channel logistik Shopee, lalu nama kurir.
     *
     * @param  array<string, mixed>  $detail
     * @param  array<string, mixed>  $package
     */
    private function courierFor(array $detail, array $package): ?int
    {
        $couriers = $this->couriers ??= Courier::where('is_active', true)->get(['id', 'code', 'name', 'shopee_logistics_channel_id']);

        if (filled($channelId = $package['logistics_channel_id'] ?? null)) {
            $match = $couriers->firstWhere('shopee_logistics_channel_id', (string) $channelId);
            if ($match) {
                return $match->id;
            }
        }

        $carrier = mb_strtolower((string) ($detail['shipping_carrier'] ?? $package['shipping_carrier'] ?? ''));

        if ($carrier === '') {
            return null;
        }

        return $couriers->first(fn (Courier $c) => str_contains($carrier, mb_strtolower($c->code)) || str_contains($carrier, mb_strtolower(explode(' ', $c->name)[0])))?->id;
    }
}
