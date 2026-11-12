<?php

namespace App\Support;

use App\Models\ProductVariant;
use App\Models\SalesOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Stok tersedia per varian per gudang (fisik dikurangi cadangan pesanan)
 * dibandingkan dengan stok minimum gudang itu. Dipakai laporan Stok Minimum,
 * notifikasi Stok menipis, dashboard dan Saran Pembelian.
 *
 * Stok minimum per gudang diambil dari halaman SKU per Gudang
 * (warehouse_variant_settings.min_qty). Kalau kosong, dipakai stok minimum di
 * Master Produk, tapi hanya untuk gudang yang memang memegang SKU itu (pernah
 * ada stoknya, ada pesanan yang mencadangkan, atau sudah diatur di SKU per
 * Gudang) supaya gudang lain tidak ikut dianggap kekurangan.
 */
class StockLevels
{
    /** Ekspresi SQL stok tersedia; hanya valid di query dari minStockQuery() / perWarehouse(). */
    public const AVAILABLE = 'COALESCE(on_hand.qty, 0) - COALESCE(reserved.qty, 0)';

    /** Stok minimum yang berlaku di gudang baris itu. */
    public const MINIMUM = 'COALESCE(wvs.min_qty, product_variants.min_stock)';

    /** Gudang memegang SKU: pernah ada baris stok, ada cadangan pesanan, atau sudah diatur. */
    public const CARRIED = '(on_hand.product_variant_id IS NOT NULL OR COALESCE(reserved.qty, 0) > 0 OR wvs.id IS NOT NULL)';

    /** Baris yang punya stok minimum di gudangnya. */
    public const HAS_MINIMUM = '(wvs.min_qty IS NOT NULL OR (product_variants.min_stock IS NOT NULL AND '.self::CARRIED.'))';

    /**
     * Pasangan varian × gudang aktif (yang boleh diakses user) beserta stok
     * fisik, cadangan dan setelan SKU per Gudang. Tanpa filter baris.
     */
    public static function perWarehouse(?int $warehouseId = null): Builder
    {
        $accessible = WarehouseAccess::ids();

        return ProductVariant::query()
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->leftJoin('units', 'units.id', '=', 'products.unit_id')
            // Join tanpa kolom penghubung = setiap varian dipasangkan dengan setiap gudang aktif.
            ->join('warehouses', fn (JoinClause $join) => $join->where('warehouses.is_active', true)
                ->when($warehouseId, fn ($j) => $j->where('warehouses.id', $warehouseId))
                ->when($accessible !== null, fn ($j) => $j->whereIn('warehouses.id', $accessible ?: [0])))
            ->leftJoin('warehouse_variant_settings as wvs', fn (JoinClause $join) => $join
                ->on('wvs.product_variant_id', '=', 'product_variants.id')
                ->on('wvs.warehouse_id', '=', 'warehouses.id'))
            ->leftJoinSub(self::onHandQuery($warehouseId), 'on_hand', fn (JoinClause $join) => $join
                ->on('on_hand.product_variant_id', '=', 'product_variants.id')
                ->on('on_hand.warehouse_id', '=', 'warehouses.id'))
            ->leftJoinSub(self::reservedQuery($warehouseId), 'reserved', fn (JoinClause $join) => $join
                ->on('reserved.product_variant_id', '=', 'product_variants.id')
                ->on('reserved.warehouse_id', '=', 'warehouses.id'))
            ->where('product_variants.is_active', true)
            ->where('products.is_active', true);
    }

    /**
     * Varian × gudang yang punya stok minimum, paling kritis dulu. Tanpa
     * gudang, satu baris per gudang yang boleh diakses user.
     */
    public static function minStockQuery(?int $warehouseId = null): Builder
    {
        $available = self::AVAILABLE;
        $minimum = self::MINIMUM;

        return self::perWarehouse($warehouseId)
            ->whereRaw(self::HAS_MINIMUM)
            // Paling kritis dulu: kekurangan terbesar terhadap stok minimum.
            ->orderByRaw("{$minimum} - ({$available}) desc")
            ->orderBy('product_variants.sku')
            ->orderBy('warehouses.code')
            ->selectRaw("product_variants.id, product_variants.sku, product_variants.name as variant_name, {$minimum} as min_stock,
                CASE WHEN wvs.min_qty IS NULL THEN 1 ELSE 0 END as min_from_master,
                warehouses.id as warehouse_id, warehouses.code as warehouse_code, warehouses.name as warehouse_name,
                products.name as product_name, units.code as unit_code,
                COALESCE(on_hand.qty, 0) as on_hand, COALESCE(reserved.qty, 0) as reserved, {$available} as available");
    }

    /**
     * Stok fisik per varian per gudang (product_variant_id, warehouse_id, qty);
     * dipakai sebagai subquery. Baris stok qty 0 tetap ikut (tanda gudang memegang SKU).
     */
    public static function onHandQuery(?int $warehouseId = null): QueryBuilder
    {
        $accessible = WarehouseAccess::ids();

        return DB::table('stocks')
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->when($accessible !== null, fn ($q) => $q->whereIn('warehouse_id', $accessible))
            ->groupBy('product_variant_id', 'warehouse_id')
            ->selectRaw('product_variant_id, warehouse_id, SUM(qty) as qty');
    }

    /** Qty yang dicadangkan pesanan terbuka per varian per gudang (product_variant_id, warehouse_id, qty). */
    public static function reservedQuery(?int $warehouseId = null): QueryBuilder
    {
        $accessible = WarehouseAccess::ids();

        return DB::table('sales_order_items')
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_items.sales_order_id')
            ->where(fn ($q) => SalesOrder::whereReserving($q))
            ->when($warehouseId, fn ($q) => $q->where('sales_orders.warehouse_id', $warehouseId))
            ->when($accessible !== null, fn ($q) => $q->whereIn('sales_orders.warehouse_id', $accessible))
            ->groupBy('sales_order_items.product_variant_id', 'sales_orders.warehouse_id')
            ->selectRaw('sales_order_items.product_variant_id, sales_orders.warehouse_id, SUM(sales_order_items.qty) as qty');
    }

    /** Hanya baris yang stok tersedianya sudah di bawah atau sama dengan minimum gudangnya. */
    public static function belowMinimum(Builder $query): Builder
    {
        return $query->whereRaw(self::AVAILABLE.' <= '.self::MINIMUM);
    }
}
