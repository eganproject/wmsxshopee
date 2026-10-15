<?php

namespace App\Services;

use App\Models\Bundle;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use App\Models\WarehouseVariantSetting;
use App\Support\Cartons;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Gudang kecil (fulfilment) dengan gudang besar sebagai cadangan.
 *
 * Pesanan selalu diambil dari gudang kecil, tapi stok yang dijual ke
 * marketplace = tersedia gudang kecil + tersedia gudang besar (dikali persen
 * buffer) + barang yang sedang dalam perjalanan dari gudang besar.
 * Gudang tanpa cadangan: stok jual = tersedia gudang itu saja.
 */
class WarehouseSupply
{
    public function __construct(private readonly SalesOrderService $orders) {}

    /** Gudang cadangan aktif untuk gudang ini (tanpa batasan akses user). */
    public function supplyOf(int $warehouseId): ?Warehouse
    {
        $warehouse = Warehouse::withoutGlobalScope('warehouse_access')->find($warehouseId);

        if (! $warehouse?->supply_warehouse_id) {
            return null;
        }

        return Warehouse::withoutGlobalScope('warehouse_access')
            ->whereKey($warehouse->supply_warehouse_id)
            ->where('is_active', true)
            ->first()
            ?->setAttribute('percent', $warehouse->supply_stock_percent ?? 100);
    }

    /**
     * Qty dalam perjalanan ke gudang ini (transfer berstatus dikirim) per varian.
     *
     * @param  array<int, int>  $variantIds
     * @return Collection<int, int>
     */
    public function inTransit(int $warehouseId, array $variantIds): Collection
    {
        return DB::table('warehouse_transfer_items')
            ->join('warehouse_transfers', 'warehouse_transfers.id', '=', 'warehouse_transfer_items.warehouse_transfer_id')
            ->where('warehouse_transfers.to_warehouse_id', $warehouseId)
            ->where('warehouse_transfers.status', 'sent')
            ->whereIn('warehouse_transfer_items.product_variant_id', $variantIds)
            ->groupBy('warehouse_transfer_items.product_variant_id')
            ->selectRaw('warehouse_transfer_items.product_variant_id, SUM(warehouse_transfer_items.qty) as qty')
            ->pluck('qty', 'product_variant_id')
            ->map(fn ($qty) => (int) $qty);
    }

    /**
     * Rincian stok jual per varian.
     *
     * @param  array<int, int>  $variantIds
     * @return Collection<int, array{own: int, supply: int, supply_raw: int, transit: int, sellable: int}>
     */
    public function sellable(int $warehouseId, array $variantIds, ?int $exceptOrderId = null): Collection
    {
        $variantIds = array_values(array_unique(array_map('intval', $variantIds)));
        $own = $this->orders->availability($warehouseId, $variantIds, $exceptOrderId);
        $supply = $this->supplyOf($warehouseId);

        $supplyAvailable = $supply ? $this->orders->availability($supply->id, $variantIds) : collect();
        $transit = $supply ? $this->inTransit($warehouseId, $variantIds) : collect();
        $percent = $supply ? max(0, min(100, (int) $supply->percent)) : 0;

        return collect($variantIds)->mapWithKeys(function ($id) use ($own, $supplyAvailable, $transit, $percent) {
            $ownQty = (int) ($own[$id]['available'] ?? 0);
            $raw = max(0, (int) ($supplyAvailable[$id]['available'] ?? 0));
            $supplyQty = intdiv($raw * $percent, 100);
            $transitQty = (int) ($transit[$id] ?? 0);

            return [$id => [
                'own' => $ownQty,
                'supply' => $supplyQty,
                'supply_raw' => $raw,
                'transit' => $transitQty,
                'sellable' => max(0, $ownQty + $supplyQty + $transitQty),
            ]];
        });
    }

    /**
     * Stok jual paket virtual: komponen paling sedikit yang menentukan.
     *
     * @return Collection<int, int> per bundle id
     */
    public function bundleSellable(int $warehouseId, iterable $bundles): Collection
    {
        $bundles = collect($bundles);
        $levels = $this->sellable($warehouseId, $bundles->flatMap(fn (Bundle $bundle) => $bundle->items->pluck('product_variant_id'))->unique()->values()->all());

        return $bundles->mapWithKeys(fn (Bundle $bundle) => [$bundle->id => $bundle->items->isEmpty() ? 0 : (int) max(0, $bundle->items->min(
            fn ($component) => intdiv(max(0, $levels[$component->product_variant_id]['sellable'] ?? 0), max(1, $component->qty))
        ))]);
    }

    /**
     * Saran isi ulang gudang ini dari gudang cadangannya. Kebutuhan dihitung dari
     * kekurangan pesanan (tersedia minus) dan stok minimum/maksimum per SKU,
     * dikurangi yang sudah dalam perjalanan, dibulatkan ke koli utuh dan dibatasi
     * stok bebas gudang cadangan.
     *
     * @return Collection<int, object>
     */
    public function suggestions(Warehouse $warehouse): Collection
    {
        $supply = $this->supplyOf($warehouse->id);

        if (! $supply) {
            return collect();
        }

        $settings = WarehouseVariantSetting::withoutGlobalScope('warehouse_access')
            ->where('warehouse_id', $warehouse->id)
            ->where(fn ($q) => $q->whereNotNull('min_qty')->orWhereNotNull('max_qty'))
            ->get()
            ->keyBy('product_variant_id');

        // SKU yang kurang untuk pesanan (cadangan > stok fisik) ikut dihitung walau belum diberi min/max.
        $short = DB::table('sales_order_items')
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_items.sales_order_id')
            ->where('sales_orders.warehouse_id', $warehouse->id)
            ->whereIn('sales_orders.status', SalesOrder::RESERVING)
            ->distinct()
            ->pluck('sales_order_items.product_variant_id')
            ->all();

        $ids = array_values(array_unique([...$settings->keys()->all(), ...$short]));

        if (! $ids) {
            return collect();
        }

        $own = $this->orders->availability($warehouse->id, $ids);
        $source = $this->orders->availability($supply->id, $ids);
        $transit = $this->inTransit($warehouse->id, $ids);
        $variants = ProductVariant::with('product:id,name')->whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)->map(function ($id) use ($settings, $own, $source, $transit, $variants, $supply) {
            $setting = $settings[$id] ?? null;
            $available = (int) ($own[$id]['available'] ?? 0);
            $coming = (int) ($transit[$id] ?? 0);
            $projected = $available + $coming;
            $min = $setting?->min_qty;
            $max = $setting?->max_qty;

            $belowMin = $min !== null && $projected <= $min;
            $deficit = max(0, -$projected);

            if (! $belowMin && $deficit === 0) {
                return null;
            }

            $target = $belowMin ? max($max ?? $min, $min) : 0;
            $need = max($deficit, $target - $projected);
            $variant = $variants[$id];
            $sourceFree = max(0, (int) ($source[$id]['available'] ?? 0));
            $qty = min(Cartons::roundUp($need, $variant->pcs_per_carton), $sourceFree);

            return (object) [
                'variant' => $variant,
                'available' => $available,
                'transit' => $coming,
                'min' => $min,
                'max' => $max,
                'need' => $need,
                'source_free' => $sourceFree,
                'qty' => $qty,
                'urgent' => $deficit > 0,
                'supply' => $supply,
            ];
        })->filter()->sortByDesc(fn ($row) => [$row->urgent ? 1 : 0, $row->need])->values();
    }
}
