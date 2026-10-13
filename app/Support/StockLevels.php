<?php

namespace App\Support;

use App\Models\ProductVariant;
use App\Models\SalesOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Stok tersedia per varian (fisik dikurangi cadangan pesanan) dibandingkan
 * dengan stok minimum. Dipakai laporan Stok Minimum dan dashboard.
 */
class StockLevels
{
    /** Ekspresi SQL stok tersedia; hanya valid di query dari minStockQuery(). */
    public const AVAILABLE = 'COALESCE(on_hand.qty, 0) - COALESCE(reserved.qty, 0)';

    /**
     * Varian aktif yang punya stok minimum, paling kritis dulu. Tanpa gudang,
     * stok & cadangan dijumlahkan dari semua gudang.
     */
    public static function minStockQuery(?int $warehouseId = null): Builder
    {
        $available = self::AVAILABLE;

        $onHand = DB::table('stocks')
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->groupBy('product_variant_id')
            ->selectRaw('product_variant_id, SUM(qty) as qty');

        $reserved = DB::table('sales_order_items')
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_items.sales_order_id')
            ->whereIn('sales_orders.status', SalesOrder::RESERVING)
            ->when($warehouseId, fn ($q) => $q->where('sales_orders.warehouse_id', $warehouseId))
            ->groupBy('sales_order_items.product_variant_id')
            ->selectRaw('sales_order_items.product_variant_id, SUM(sales_order_items.qty) as qty');

        return ProductVariant::query()
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->leftJoin('units', 'units.id', '=', 'products.unit_id')
            ->leftJoinSub($onHand, 'on_hand', 'on_hand.product_variant_id', '=', 'product_variants.id')
            ->leftJoinSub($reserved, 'reserved', 'reserved.product_variant_id', '=', 'product_variants.id')
            ->whereNotNull('product_variants.min_stock')
            ->where('product_variants.is_active', true)
            ->where('products.is_active', true)
            // Paling kritis dulu: kekurangan terbesar terhadap stok minimum.
            ->orderByRaw("product_variants.min_stock - ({$available}) desc")
            ->orderBy('product_variants.sku')
            ->selectRaw("product_variants.id, product_variants.sku, product_variants.name as variant_name, product_variants.min_stock,
                products.name as product_name, units.code as unit_code,
                COALESCE(on_hand.qty, 0) as on_hand, COALESCE(reserved.qty, 0) as reserved, {$available} as available");
    }

    /** Hanya varian yang stok tersedianya sudah di bawah atau sama dengan minimum. */
    public static function belowMinimum(Builder $query): Builder
    {
        return $query->whereRaw(self::AVAILABLE.' <= product_variants.min_stock');
    }
}
