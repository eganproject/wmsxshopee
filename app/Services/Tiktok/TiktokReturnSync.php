<?php

namespace App\Services\Tiktok;

use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Models\TiktokShop;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Tarik pengajuan retur TikTok (POST /return_refund/202309/returns/search, filter
 * update_time) menjadi draft Retur Penjualan, dicocokkan ke pesanan Optima lewat
 * order_id. Karena filternya waktu perubahan, status retur lama ikut diperbarui.
 *
 * Refund tanpa pengembalian barang (return_type REFUND) tidak dibuat returnya
 * karena tidak ada paket yang kembali ke gudang. Satu return_line_item = satu
 * unit, jadi jumlah dihitung per sku_id.
 */
class TiktokReturnSync
{
    /** Status yang berarti retur tidak jadi dikirim balik. */
    public const DROPPED = ['REFUND_OR_RETURN_REQUEST_REJECT', 'RETURN_OR_REFUND_REQUEST_CANCEL'];

    private const FIRST_SYNC_DAYS = 15;

    private const WINDOW_DAYS = 15;

    /**
     * @return array{created: int, updated: int, skipped: int}
     */
    public function sync(TiktokShop $shop, ?TiktokShopApi $api = null): array
    {
        $api ??= TiktokShopApi::for($shop);
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        $from = ($shop->returns_synced_until?->copy()->subMinutes(30)) ?? now()->subDays(self::FIRST_SYNC_DAYS);
        $now = now();

        while ($from->lt($now)) {
            $to = $from->copy()->addDays(self::WINDOW_DAYS)->min($now);
            $pageToken = null;

            do {
                $page = $api->post('/return_refund/returns/search', [
                    'update_time_ge' => $from->getTimestamp(),
                    'update_time_lt' => $to->getTimestamp(),
                ], array_filter(['page_size' => 50, 'sort_field' => 'update_time', 'sort_order' => 'ASC', 'page_token' => $pageToken]));

                foreach ($page['return_orders'] ?? $page['returns'] ?? [] as $return) {
                    $stats[$this->apply($shop, $return)]++;
                }

                $pageToken = $page['next_page_token'] ?? null;
            } while (filled($pageToken));

            $shop->update(['returns_synced_until' => $to]);
            $from = $to;
        }

        return $stats;
    }

    /** Sinkron retur satu pesanan (dari webhook RETURN_STATUS_CHANGE). */
    public function syncOrder(TiktokShop $shop, string $orderId, ?TiktokShopApi $api = null): void
    {
        $api ??= TiktokShopApi::for($shop);
        $page = $api->post('/return_refund/returns/search', ['order_ids' => [$orderId]], ['page_size' => 50]);

        foreach ($page['return_orders'] ?? $page['returns'] ?? [] as $return) {
            $this->apply($shop, $return);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return string created | updated | skipped
     */
    public function apply(TiktokShop $shop, array $data): string
    {
        $returnId = (string) ($data['return_id'] ?? '');
        $status = (string) ($data['return_status'] ?? '');

        if ($returnId === '') {
            return 'skipped';
        }

        $existing = SalesReturn::withoutGlobalScope('warehouse_access')
            ->where('tiktok_shop_id', $shop->id)->where('external_return_number', $returnId)->first();

        if ($existing) {
            $existing->update([
                'tiktok_return_status' => $status ?: $existing->tiktok_return_status,
                'tracking_number' => $existing->tracking_number ?: ($data['return_tracking_number'] ?? null),
            ]);

            return 'updated';
        }

        if (($data['return_type'] ?? '') === 'REFUND' || in_array($status, self::DROPPED, true)) {
            return 'skipped';
        }

        $order = SalesOrder::withoutGlobalScope('warehouse_access')
            ->with(['items.bundleLine', 'bundles'])
            ->where('tiktok_shop_id', $shop->id)
            ->where('external_number', (string) ($data['order_id'] ?? ''))
            ->where('status', 'shipped')
            ->latest('id')
            ->first();

        // Pesanan yang tidak dikirim lewat Optima: tidak ada stok yang perlu dikembalikan otomatis.
        if (! $order) {
            return 'skipped';
        }

        $items = $this->items($shop, $order, $data['return_line_items'] ?? [], $this->reason($data));
        // Barang yang sudah pernah diretur tidak dihitung lagi (cegah stok bertambah dua kali).
        $items = SalesReturn::capToRemaining($items, $order->items->pluck('qty', 'id')->all());

        if (! $items) {
            return 'skipped';
        }

        DB::transaction(function () use ($shop, $order, $data, $returnId, $status, $items) {
            $return = SalesReturn::create([
                'number' => SalesReturn::nextNumber(),
                'status' => 'draft',
                'source' => 'order',
                'sales_order_id' => $order->id,
                'warehouse_id' => $order->warehouse_id,
                'return_date' => Carbon::createFromTimestamp((int) ($data['create_time'] ?? now()->getTimestamp()), config('app.timezone'))->toDateString(),
                'tracking_number' => $data['return_tracking_number'] ?? null,
                'sender_name' => mb_substr($order->customer_name, 0, 150),
                'sender_phone' => $order->customer_phone,
                'channel_name' => $shop->channelName(),
                'external_number' => $order->external_number,
                'external_return_number' => $returnId,
                'tiktok_shop_id' => $shop->id,
                'tiktok_return_status' => $status ?: null,
                'notes' => mb_substr(trim('Retur TikTok '.$returnId.(filled($data['return_reason_text'] ?? null) ? ': '.$data['return_reason_text'] : '')), 0, 1000),
            ]);

            $return->items()->createMany($items);
        });

        return 'created';
    }

    /**
     * Alasan TikTok dikirim sebagai teks bebas sesuai bahasa toko; dipetakan kasar.
     *
     * @param  array<string, mixed>  $data
     */
    private function reason(array $data): string
    {
        $text = mb_strtolower(($data['return_reason'] ?? '').' '.($data['return_reason_text'] ?? ''));

        return match (true) {
            str_contains($text, 'damage') || str_contains($text, 'rusak') || str_contains($text, 'cacat') || str_contains($text, 'defect') => 'damaged',
            str_contains($text, 'wrong') || str_contains($text, 'salah') => 'wrong_item',
            str_contains($text, 'missing') || str_contains($text, 'kurang') || str_contains($text, 'hilang') => 'missing',
            str_contains($text, 'size') || str_contains($text, 'ukuran') => 'wrong_size',
            str_contains($text, 'describ') || str_contains($text, 'sesuai') => 'not_as_described',
            str_contains($text, 'mind') || str_contains($text, 'tidak jadi') || str_contains($text, 'no longer') => 'changed_mind',
            str_contains($text, 'deliver') || str_contains($text, 'tidak diterima') => 'failed_delivery',
            default => 'other',
        };
    }

    /**
     * return_line_items (satu per unit) -> baris retur per varian, merujuk baris
     * pesanan asal. Retur paket dipecah ke komponennya.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function items(TiktokShop $shop, SalesOrder $order, array $lines, string $reason): array
    {
        $rows = [];

        foreach (collect($lines)->filter(fn ($line) => filled($line['sku_id'] ?? null))->groupBy(fn ($line) => (string) $line['sku_id']) as $skuId => $units) {
            $mapping = $shop->mappings()->where('sku_id', (string) $skuId)->first();
            $qty = $units->count();

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
