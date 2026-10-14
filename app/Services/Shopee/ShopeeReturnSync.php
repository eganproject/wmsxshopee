<?php

namespace App\Services\Shopee;

use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Models\ShopeeShop;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Tarik pengajuan retur/refund Shopee (/returns/get_return_list) menjadi draft
 * Retur Penjualan, dicocokkan ke pesanan Optima lewat no. pesanan Shopee.
 * Gudang memeriksa kondisi barang lalu menerima retur seperti biasa; jumlah
 * awal diisi sebagai layak jual dan bisa diubah saat pemeriksaan.
 *
 * Refund tanpa pengembalian barang (needs_logistics = false) tidak dibuat
 * returnya karena tidak ada paket yang kembali ke gudang.
 */
class ShopeeReturnSync
{
    /** Alasan retur Shopee -> alasan retur Optima. */
    private const REASONS = [
        'NOT_RECEIPT' => 'failed_delivery',
        'WRONG_ITEM' => 'wrong_item',
        'ITEM_WRONGDAMAGED' => 'damaged',
        'ITEM_DAMAGED' => 'damaged',
        'DAMAGED_PACKAGE' => 'damaged',
        'DIFFERENT_DESCRIPTION' => 'not_as_described',
        'DESCRIPTION_NOT_MATCH' => 'not_as_described',
        'MUTUAL_AGREE' => 'changed_mind',
        'CHANGE_MIND' => 'changed_mind',
        'ITEM_MISSING' => 'missing',
        'FUNCTIONAL_DMG' => 'damaged',
        'WRONG_SIZE' => 'wrong_size',
    ];

    /** Status retur Shopee yang tidak berubah lagi. */
    private const FINAL_STATUSES = ['CANCELLED', 'CLOSED', 'ACCEPTED', 'REFUND_PAID'];

    private const FIRST_SYNC_DAYS = 15;

    private const WINDOW_DAYS = 15;

    /**
     * @return array{created: int, updated: int, skipped: int}
     */
    public function sync(ShopeeShop $shop, ?ShopeeShopApi $api = null): array
    {
        $api ??= ShopeeShopApi::for($shop);
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        $startedAt = now();

        $from = ($shop->returns_synced_until?->copy()->subHour()) ?? now()->subDays(self::FIRST_SYNC_DAYS);
        $now = now();

        while ($from->lt($now)) {
            $to = $from->copy()->addDays(self::WINDOW_DAYS)->min($now);
            $page = 0;

            do {
                $response = $api->get('/api/v2/returns/get_return_list', [
                    'page_no' => $page,
                    'page_size' => 50,
                    'create_time_from' => $from->getTimestamp(),
                    'create_time_to' => $to->getTimestamp(),
                ])['response'] ?? [];

                foreach ($response['return'] ?? [] as $return) {
                    $stats[$this->apply($shop, $return)]++;
                }

                $page++;
            } while (! empty($response['more']));

            $shop->update(['returns_synced_until' => $to]);
            $from = $to;
        }

        $this->refreshOpen($shop, $api, $startedAt);

        return $stats;
    }

    /**
     * get_return_list hanya memfilter waktu pengajuan, jadi perubahan status retur
     * lama (cth. resi retur terbit, dibatalkan pembeli) diambil per retur lewat
     * /returns/get_return_detail untuk retur yang belum diterima gudang.
     */
    private function refreshOpen(ShopeeShop $shop, ShopeeShopApi $api, Carbon $before): void
    {
        SalesReturn::withoutGlobalScope('warehouse_access')
            ->where('shopee_shop_id', $shop->id)
            ->where('status', 'draft')
            ->whereNotNull('external_return_number')
            ->where(fn ($q) => $q->whereNull('shopee_return_status')->orWhereNotIn('shopee_return_status', self::FINAL_STATUSES))
            ->where('created_at', '>=', now()->subDays(60))
            ->where('updated_at', '<', $before) // yang baru diperbarui dari daftar tidak perlu diambil lagi
            ->oldest('updated_at')
            ->limit(50)
            ->get()
            ->each(function (SalesReturn $return) use ($shop, $api) {
                try {
                    $detail = $api->get('/api/v2/returns/get_return_detail', ['return_sn' => $return->external_return_number])['response'] ?? [];
                } catch (ShopeeException) {
                    return;
                }

                if (filled($detail['return_sn'] ?? null)) {
                    $this->apply($shop, $detail);
                }
            });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return string created | updated | skipped
     */
    public function apply(ShopeeShop $shop, array $data): string
    {
        $returnSn = (string) $data['return_sn'];
        $existing = SalesReturn::withoutGlobalScope('warehouse_access')
            ->where('shopee_shop_id', $shop->id)->where('external_return_number', $returnSn)->first();

        if ($existing) {
            $existing->touch();
            $existing->update([
                'shopee_return_status' => $data['status'] ?? $existing->shopee_return_status,
                'tracking_number' => $existing->tracking_number ?: ($data['tracking_number'] ?? null),
            ]);

            return 'updated';
        }

        if (($data['needs_logistics'] ?? true) === false || in_array($data['status'] ?? '', ['CANCELLED', 'CLOSED'], true)) {
            return 'skipped';
        }

        $order = SalesOrder::withoutGlobalScope('warehouse_access')
            ->with(['items.bundleLine', 'bundles'])
            ->where('shopee_shop_id', $shop->id)
            ->where('external_number', (string) $data['order_sn'])
            ->where('status', 'shipped')
            ->latest('id')
            ->first();

        // Pesanan yang tidak dikirim lewat Optima: tidak ada stok yang perlu dikembalikan otomatis.
        if (! $order) {
            return 'skipped';
        }

        $items = $this->items($shop, $order, $data['item'] ?? [], self::REASONS[$data['reason'] ?? ''] ?? 'other');

        if (! $items) {
            return 'skipped';
        }

        DB::transaction(function () use ($shop, $order, $data, $returnSn, $items) {
            $return = SalesReturn::create([
                'number' => SalesReturn::nextNumber(),
                'status' => 'draft',
                'source' => 'order',
                'sales_order_id' => $order->id,
                'warehouse_id' => $order->warehouse_id,
                'return_date' => Carbon::createFromTimestamp($data['create_time'] ?? now()->getTimestamp())->toDateString(),
                'tracking_number' => $data['tracking_number'] ?? null,
                'sender_name' => mb_substr($data['user']['username'] ?? $order->customer_name, 0, 150),
                'sender_phone' => $order->customer_phone,
                'channel_name' => $shop->channelName(),
                'external_number' => $order->external_number,
                'external_return_number' => $returnSn,
                'shopee_shop_id' => $shop->id,
                'shopee_return_status' => $data['status'] ?? null,
                'notes' => mb_substr(trim('Retur Shopee '.$returnSn.(filled($data['text_reason'] ?? null) ? ': '.$data['text_reason'] : '')), 0, 1000),
            ]);

            $return->items()->createMany($items);
        });

        return 'created';
    }

    /**
     * Item retur Shopee -> baris retur per varian, merujuk baris pesanan asal.
     * Retur paket dipecah ke komponennya.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function items(ShopeeShop $shop, SalesOrder $order, array $lines, string $reason): array
    {
        $rows = [];

        foreach ($lines as $line) {
            $mapping = $shop->mappings()->where('item_id', $line['item_id'])->where('model_id', (int) ($line['model_id'] ?? 0))->first();
            $qty = (int) ($line['amount'] ?? 1);

            if ($mapping?->product_variant_id) {
                $orderItem = $order->items->first(fn ($item) => $item->product_variant_id === $mapping->product_variant_id && $item->sales_order_bundle_id === null)
                    ?? $order->items->firstWhere('product_variant_id', $mapping->product_variant_id);

                if ($orderItem) {
                    $rows[] = ['sales_order_item_id' => $orderItem->id, 'product_variant_id' => $orderItem->product_variant_id, 'qty_good' => min($qty, $orderItem->qty), 'reason' => $reason];
                }
            } elseif ($mapping?->bundle_id) {
                $bundleLine = $order->bundles->firstWhere('bundle_id', $mapping->bundle_id);

                foreach ($bundleLine ? $order->items->where('sales_order_bundle_id', $bundleLine->id) : [] as $component) {
                    $rows[] = ['sales_order_item_id' => $component->id, 'product_variant_id' => $component->product_variant_id, 'qty_good' => min($qty * max(1, (int) $component->bundle_component_qty), $component->qty), 'reason' => $reason];
                }
            }
        }

        return $rows;
    }
}
