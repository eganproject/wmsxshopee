<?php

namespace App\Services;

use App\Models\Bundle;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesOrderPick;
use App\Models\Stock;
use App\Models\WarehouseLocation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Alur pesanan penjualan: konfirmasi (cadangkan stok), picking (tentukan bin),
 * kemas, kirim (stok keluar lewat StockService) dan batal (lepas cadangan).
 *
 * Cadangan stok tidak disimpan di tabel terpisah: dihitung dari baris pesanan
 * berstatus dikonfirmasi/diambil/dikemas, jadi selalu sinkron dengan status.
 */
class SalesOrderService
{
    public function __construct(private StockService $stock) {}

    /**
     * Stok tersedia (fisik dikurangi cadangan pesanan lain) per varian di satu gudang.
     *
     * @param  array<int, int>  $variantIds
     * @return Collection<int, array{on_hand: int, reserved: int, available: int}>
     */
    public function availability(int $warehouseId, array $variantIds, ?int $exceptOrderId = null): Collection
    {
        $onHand = Stock::query()
            ->where('warehouse_id', $warehouseId)
            ->whereIn('product_variant_id', $variantIds)
            ->groupBy('product_variant_id')
            ->selectRaw('product_variant_id, SUM(qty) as qty')
            ->pluck('qty', 'product_variant_id');

        $reserved = DB::table('sales_order_items')
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_items.sales_order_id')
            ->where('sales_orders.warehouse_id', $warehouseId)
            ->whereIn('sales_orders.status', SalesOrder::RESERVING)
            ->when($exceptOrderId, fn ($q) => $q->where('sales_orders.id', '!=', $exceptOrderId))
            ->whereIn('sales_order_items.product_variant_id', $variantIds)
            ->groupBy('sales_order_items.product_variant_id')
            ->selectRaw('sales_order_items.product_variant_id, SUM(sales_order_items.qty) as qty')
            ->pluck('qty', 'product_variant_id');

        return collect($variantIds)->unique()->mapWithKeys(fn ($id) => [$id => [
            'on_hand' => $hand = (int) ($onHand[$id] ?? 0),
            'reserved' => $held = (int) ($reserved[$id] ?? 0),
            'available' => $hand - $held,
        ]]);
    }

    /**
     * Berapa paket yang bisa dipenuhi per paket di satu gudang: komponen dengan
     * stok tersedia paling sedikit (dibanding jumlahnya per paket) yang menentukan.
     *
     * @param  iterable<int, Bundle>  $bundles  dengan relasi items
     * @return Collection<int, int> per bundle id
     */
    public function bundleAvailability(int $warehouseId, iterable $bundles, ?int $exceptOrderId = null): Collection
    {
        $bundles = collect($bundles);
        $availability = $this->availability($warehouseId, $bundles->flatMap(fn (Bundle $bundle) => $bundle->items->pluck('product_variant_id'))->unique()->values()->all(), $exceptOrderId);

        return $bundles->mapWithKeys(fn (Bundle $bundle) => [$bundle->id => $bundle->items->isEmpty() ? 0 : (int) max(0, $bundle->items->min(
            fn ($component) => intdiv(max(0, $availability[$component->product_variant_id]['available'] ?? 0), max(1, $component->qty))
        ))]);
    }

    /**
     * Ganti semua baris pesanan (draft). Paket dipecah jadi baris barang per komponen
     * dengan isi paket saat ini, jadi cadangan, picking & pengiriman tetap per varian.
     *
     * @param  array<int, array{product_variant_id: int, qty: int, price: mixed, notes: ?string}>  $items
     * @param  array<int, array{bundle_id: int, qty: int, price: mixed, notes: ?string}>  $bundles
     */
    public function fillLines(SalesOrder $order, array $items, array $bundles): void
    {
        $order->items()->delete();
        $order->bundles()->delete();
        $order->items()->createMany($items);

        $masters = Bundle::with('items')->whereIn('id', collect($bundles)->pluck('bundle_id'))->get()->keyBy('id');

        foreach ($bundles as $row) {
            $bundle = $masters[$row['bundle_id']];
            $line = $order->bundles()->create($row);

            $order->items()->createMany($bundle->items->map(fn ($component) => [
                'sales_order_bundle_id' => $line->id,
                'product_variant_id' => $component->product_variant_id,
                'qty' => $row['qty'] * $component->qty,
                'bundle_component_qty' => $component->qty,
                'price' => 0,
            ])->all());
        }

        $order->unsetRelation('items')->unsetRelation('bundles');
    }

    /**
     * Draft -> Dikonfirmasi. Gagal bila stok tersedia di gudang tidak cukup.
     *
     * @throws ValidationException
     */
    public function confirm(SalesOrder $order): void
    {
        DB::transaction(function () use ($order) {
            $order = $this->lock($order, ['draft']);
            $order->load(['items.variant', 'items.bundleLine.bundle']);

            $this->lockVariants($order);
            $this->ensureAvailable($order);

            $order->update(['status' => 'confirmed', 'confirmed_at' => now()]);
        });
    }

    /**
     * Usulan pengambilan: tiap baris diambil dari bin dengan stok bebas terbanyak
     * dulu (supaya sesedikit mungkin bin), lalu stok "Tanpa bin".
     *
     * @return array<int, array<int, array{warehouse_location_id: ?int, qty: int}>> per item id
     */
    public function suggestPicks(SalesOrder $order): array
    {
        $order->loadMissing('items');
        $free = $this->freeByLocation($order->warehouse_id, $order->items->pluck('product_variant_id')->all(), $order->id)
            ->map(fn ($slots) => $slots->sortByDesc('qty')->all())
            ->all();
        $suggestion = [];

        foreach ($order->items as $item) {
            $needed = $item->qty;
            $rows = [];

            foreach ($free[$item->product_variant_id] ?? [] as $key => $slot) {
                $take = min($needed, $slot['qty']);
                if ($take <= 0) {
                    continue;
                }
                $rows[] = ['warehouse_location_id' => $slot['location_id'], 'qty' => $take];
                $needed -= $take;
                // Baris lain dengan varian yang sama tidak memakai stok yang sudah diusulkan.
                $free[$item->product_variant_id][$key]['qty'] -= $take;
            }

            if ($needed > 0) {
                $rows[] = ['warehouse_location_id' => $rows[0]['warehouse_location_id'] ?? null, 'qty' => $needed];
            }

            $suggestion[$item->id] = $rows;
        }

        return $suggestion;
    }

    /**
     * Dikonfirmasi -> Sudah Diambil. Simpan dari bin mana tiap baris diambil.
     *
     * @param  array<int, array<int, array{warehouse_location_id: ?int, qty: int}>>  $picks  per item id
     *
     * @throws ValidationException
     */
    public function pick(SalesOrder $order, array $picks): void
    {
        DB::transaction(function () use ($order, $picks) {
            $order = $this->lock($order, ['confirmed']);
            $order->load('items.variant');
            $this->lockVariants($order);

            $free = $this->freeByLocation($order->warehouse_id, $order->items->pluck('product_variant_id')->all(), $order->id);
            $used = [];

            foreach ($order->items as $item) {
                $rows = collect($picks[$item->id] ?? [])->filter(fn ($row) => (int) $row['qty'] > 0);

                if ($rows->sum('qty') !== $item->qty) {
                    throw ValidationException::withMessages([
                        "picks.{$item->id}" => "Jumlah diambil untuk {$item->variant->sku} harus {$item->qty} (sekarang {$rows->sum('qty')}).",
                    ]);
                }

                foreach ($rows as $row) {
                    $locationId = $row['warehouse_location_id'] ? (int) $row['warehouse_location_id'] : null;
                    $key = $item->product_variant_id.':'.($locationId ?? 'none');
                    $used[$key] = ($used[$key] ?? 0) + (int) $row['qty'];
                    $available = $free->get($item->product_variant_id, collect())->firstWhere('location_id', $locationId)['qty'] ?? 0;

                    if ($used[$key] > $available) {
                        $place = $locationId ? 'di bin '.WarehouseLocation::whereKey($locationId)->value('code') : 'tanpa bin';
                        throw ValidationException::withMessages([
                            "picks.{$item->id}" => "Stok {$item->variant->sku} {$place} tidak cukup (bebas {$available}).",
                        ]);
                    }
                }
            }

            foreach ($order->items as $item) {
                $item->picks()->delete();
                $item->picks()->createMany(collect($picks[$item->id])->filter(fn ($row) => (int) $row['qty'] > 0)->map(fn ($row) => [
                    'warehouse_location_id' => $row['warehouse_location_id'] ? (int) $row['warehouse_location_id'] : null,
                    'qty' => (int) $row['qty'],
                ])->values()->all());
            }

            $order->update(['status' => 'picked', 'picked_at' => now()]);
        });
    }

    /** Sudah Diambil -> Sudah Dikemas. */
    public function pack(SalesOrder $order): void
    {
        DB::transaction(function () use ($order) {
            $this->lock($order, ['picked'])->update(['status' => 'packed', 'packed_at' => now()]);
        });
    }

    /**
     * Sudah Dikemas -> Dikirim. Stok keluar dari bin hasil picking lewat mutasi Barang Keluar.
     *
     * @param  array{courier_id: int, courier_service?: ?string, tracking_number: string}  $shipping
     *
     * @throws ValidationException
     */
    public function ship(SalesOrder $order, array $shipping, ?int $userId): void
    {
        DB::transaction(function () use ($order, $shipping, $userId) {
            $order = $this->lock($order, ['packed']);
            $order->load('items.picks');

            $movement = $this->stock->record([
                'type' => 'out',
                'warehouse_id' => $order->warehouse_id,
                'movement_date' => now()->toDateString(),
                'reference' => $order->number,
                'notes' => trim("Pengiriman ke {$order->customer_name}".($order->channel_name ? " · {$order->channel_name}" : '').($order->external_number ? " #{$order->external_number}" : '')),
                'created_by' => $userId,
            ], $order->items->flatMap(fn ($item) => $item->picks->map(fn (SalesOrderPick $pick) => [
                'product_variant_id' => $item->product_variant_id,
                'from_location_id' => $pick->warehouse_location_id,
                'qty' => $pick->qty,
            ]))->values()->all());

            $order->update([
                ...$shipping,
                'status' => 'shipped',
                'stock_movement_id' => $movement->id,
                'shipped_at' => now(),
                'shipped_by' => $userId,
            ]);
        });
    }

    /** Batal dari status mana pun sebelum dikirim; cadangan stok otomatis lepas. */
    public function cancel(SalesOrder $order, ?string $reason): void
    {
        DB::transaction(function () use ($order, $reason) {
            $this->lock($order, ['draft', ...SalesOrder::RESERVING])->update([
                'status' => 'cancelled',
                'cancel_reason' => $reason,
                'cancelled_at' => now(),
            ]);
        });
    }

    /** Kembalikan pesanan yang belum dikirim ke draft supaya bisa diubah; hasil picking dihapus. */
    public function reopen(SalesOrder $order): void
    {
        DB::transaction(function () use ($order) {
            $order = $this->lock($order, SalesOrder::RESERVING);
            SalesOrderPick::whereIn('sales_order_item_id', $order->items()->pluck('id'))->delete();
            $order->update(['status' => 'draft', 'confirmed_at' => null, 'picked_at' => null, 'packed_at' => null]);
        });
    }

    /**
     * @param  array<int, string>  $allowed
     *
     * @throws ValidationException
     */
    private function lock(SalesOrder $order, array $allowed): SalesOrder
    {
        $locked = SalesOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

        if (! in_array($locked->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'order' => "{$locked->number} berstatus {$locked->statusLabel()}, aksi ini tidak bisa dilakukan.",
            ]);
        }

        return $locked;
    }

    /** Serialkan konfirmasi/picking pesanan lain yang memakai varian yang sama. */
    private function lockVariants(SalesOrder $order): void
    {
        ProductVariant::whereIn('id', $order->items->pluck('product_variant_id')->unique()->sort()->values())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /** @throws ValidationException */
    private function ensureAvailable(SalesOrder $order): void
    {
        $availability = $this->availability($order->warehouse_id, $order->items->pluck('product_variant_id')->all(), $order->id);
        $needed = $order->items->groupBy('product_variant_id')->map(fn ($rows) => $rows->sum('qty'));
        $messages = [];

        foreach ($needed as $variantId => $qty) {
            $available = $availability[$variantId]['available'];
            if ($qty > $available) {
                $rows = $order->items->where('product_variant_id', $variantId);
                $sku = $rows->first()->variant->sku;
                $bundles = $rows->map(fn ($item) => $item->bundleLine?->bundle?->sku)->filter()->unique();
                $source = $bundles->isNotEmpty() ? ' (termasuk isi paket '.$bundles->join(', ').')' : '';
                $messages["stock.{$variantId}"] = "Stok {$sku} tidak cukup{$source}: dipesan {$qty}, tersedia {$available}.";
            }
        }

        if ($messages) {
            throw ValidationException::withMessages($messages);
        }
    }

    /**
     * Stok bebas per bin: saldo fisik dikurangi yang sudah dialokasikan picking
     * pesanan lain (diambil/dikemas, belum dikirim).
     *
     * @param  array<int, int>  $variantIds
     * @return Collection<int, Collection<int, array{location_id: ?int, code: ?string, qty: int}>> per varian
     */
    public function freeByLocation(int $warehouseId, array $variantIds, ?int $exceptOrderId = null): Collection
    {
        $allocated = DB::table('sales_order_picks')
            ->join('sales_order_items', 'sales_order_items.id', '=', 'sales_order_picks.sales_order_item_id')
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_items.sales_order_id')
            ->where('sales_orders.warehouse_id', $warehouseId)
            ->whereIn('sales_orders.status', ['picked', 'packed'])
            ->when($exceptOrderId, fn ($q) => $q->where('sales_orders.id', '!=', $exceptOrderId))
            ->whereIn('sales_order_items.product_variant_id', $variantIds)
            ->groupBy('sales_order_items.product_variant_id', 'sales_order_picks.warehouse_location_id')
            ->selectRaw('sales_order_items.product_variant_id as variant_id, sales_order_picks.warehouse_location_id as location_id, SUM(sales_order_picks.qty) as qty')
            ->get()
            ->keyBy(fn ($row) => $row->variant_id.':'.($row->location_id ?? 'none'));

        return Stock::query()
            ->with('location:id,code,is_active')
            ->where('warehouse_id', $warehouseId)
            ->whereIn('product_variant_id', $variantIds)
            ->where('qty', '>', 0)
            ->get()
            ->map(fn (Stock $stock) => [
                'variant_id' => $stock->product_variant_id,
                'location_id' => $stock->warehouse_location_id,
                'code' => $stock->location?->code,
                'qty' => $stock->qty - (int) ($allocated[$stock->product_variant_id.':'.($stock->warehouse_location_id ?? 'none')]->qty ?? 0),
            ])
            ->filter(fn ($slot) => $slot['qty'] > 0)
            ->groupBy('variant_id')
            ->map(fn ($slots) => $slots->sortBy('code')->values());
    }
}
