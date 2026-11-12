<?php

namespace App\Support;

use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Saran jumlah pembelian ulang per varian per gudang.
 *
 *   rata-rata harian = qty terkirim N hari terakhir / N
 *   stok target      = stok minimum gudang + ceil(rata-rata harian × hari stok)
 *   saran beli       = stok target − tersedia − qty di draft penerimaan
 *
 * Penjualan paket sudah dipecah per komponen di baris pesanan, jadi permintaan
 * paket ikut terhitung ke varian komponennya. Stok minimum gudang mengikuti
 * StockLevels (SKU per Gudang, kosong = Master Produk). Stok, cadangan, penjualan dan
 * draft penerimaan dibatasi ke gudang yang boleh diakses user.
 */
class ReorderSuggestions
{
    public function __construct(
        public readonly int $lookbackDays,
        public readonly int $coverDays,
    ) {}

    public static function fromConfig(?int $lookbackDays = null, ?int $coverDays = null): self
    {
        return new self(
            max(1, min(365, $lookbackDays ?? (int) config('alerts.reorder.lookback_days', 30))),
            max(0, min(365, $coverDays ?? (int) config('alerts.reorder.cover_days', 30))),
        );
    }

    /**
     * Varian × gudang yang punya stok minimum atau terjual dalam periode,
     * paling mendesak dulu. Tanpa filter gudang, satu baris per gudang.
     *
     * @return Collection<int, object>
     */
    public function rows(?int $warehouseId = null, ?string $search = null): Collection
    {
        $accessible = WarehouseAccess::ids();

        $sold = DB::table('sales_order_items')
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_items.sales_order_id')
            ->where('sales_orders.status', 'shipped')
            ->where('sales_orders.shipped_at', '>=', now()->subDays($this->lookbackDays))
            ->when($warehouseId, fn ($q) => $q->where('sales_orders.warehouse_id', $warehouseId))
            ->when($accessible !== null, fn ($q) => $q->whereIn('sales_orders.warehouse_id', $accessible))
            ->groupBy('sales_order_items.product_variant_id', 'sales_orders.warehouse_id')
            ->selectRaw('sales_order_items.product_variant_id, sales_orders.warehouse_id, SUM(sales_order_items.qty) as qty');

        $incoming = DB::table('goods_receipt_items')
            ->join('goods_receipts', 'goods_receipts.id', '=', 'goods_receipt_items.goods_receipt_id')
            ->where('goods_receipts.status', 'draft')
            ->when($warehouseId, fn ($q) => $q->where('goods_receipts.warehouse_id', $warehouseId))
            ->when($accessible !== null, fn ($q) => $q->whereIn('goods_receipts.warehouse_id', $accessible))
            ->groupBy('goods_receipt_items.product_variant_id', 'goods_receipts.warehouse_id')
            ->selectRaw('goods_receipt_items.product_variant_id, goods_receipts.warehouse_id, SUM(COALESCE(goods_receipt_items.qty_ordered, goods_receipt_items.qty_received)) as qty');

        $perWarehouse = fn (string $alias) => fn (JoinClause $join) => $join
            ->on("{$alias}.product_variant_id", '=', 'product_variants.id')
            ->on("{$alias}.warehouse_id", '=', 'warehouses.id');

        $rows = StockLevels::perWarehouse($warehouseId)
            ->leftJoinSub($sold, 'sold', $perWarehouse('sold'))
            ->leftJoinSub($incoming, 'incoming', $perWarehouse('incoming'))
            ->leftJoin('warehouses as supply', 'supply.id', '=', 'warehouses.supply_warehouse_id')
            ->where(fn ($q) => $q->whereRaw(StockLevels::HAS_MINIMUM)->orWhere('sold.qty', '>', 0))
            ->when(filled($search), function ($q) use ($search) {
                $like = '%'.trim($search).'%';
                $q->where(fn ($q) => $q->where('product_variants.sku', 'like', $like)->orWhere('products.name', 'like', $like));
            })
            ->toBase()
            ->selectRaw('product_variants.id, product_variants.sku, product_variants.name as variant_name, '.StockLevels::MINIMUM.' as min_stock,
                warehouses.id as warehouse_id, warehouses.code as warehouse_code, supply.code as supply_code,
                products.name as product_name, units.code as unit_code,
                COALESCE(on_hand.qty, 0) as on_hand, COALESCE(reserved.qty, 0) as reserved,
                COALESCE(sold.qty, 0) as sold, COALESCE(incoming.qty, 0) as incoming')
            ->get()
            ->map(fn ($row) => $this->calculate($row));

        // Perlu dibeli dulu, lalu yang sudah di bawah minimum, paling cepat habis, kekurangan terbesar.
        return $rows->sort(fn ($a, $b) => [$b->suggested > 0, $b->below_min, $a->days_left ?? PHP_INT_MAX, $b->shortage, $a->sku, $a->warehouse_code]
            <=> [$a->suggested > 0, $a->below_min, $b->days_left ?? PHP_INT_MAX, $a->shortage, $b->sku, $b->warehouse_code])->values();
    }

    private function calculate(object $row): object
    {
        foreach (['on_hand', 'reserved', 'sold', 'incoming'] as $field) {
            $row->{$field} = (int) $row->{$field};
        }
        $row->min_stock = $row->min_stock === null ? null : (int) $row->min_stock;
        $row->available = $row->on_hand - $row->reserved;
        $row->daily = $row->sold / $this->lookbackDays;
        $row->days_left = $row->daily > 0 ? (int) floor(max(0, $row->available) / $row->daily) : null;
        $row->target = ($row->min_stock ?? 0) + (int) ceil(round($row->daily * $this->coverDays, 6));
        $row->shortage = max(0, ($row->min_stock ?? 0) - $row->available);
        $row->below_min = $row->min_stock !== null && $row->available <= $row->min_stock;
        $row->suggested = max(0, $row->target - $row->available - $row->incoming);

        return $row;
    }

    /**
     * Supplier dari penerimaan terakhir yang sudah diterima, per varian.
     *
     * @param  array<int, int>  $variantIds
     * @return Collection<int, string> nama supplier per product_variant_id
     */
    public static function lastSuppliers(array $variantIds): Collection
    {
        if (! $variantIds) {
            return collect();
        }

        $accessible = WarehouseAccess::ids();

        return DB::table('goods_receipt_items')
            ->join('goods_receipts', 'goods_receipts.id', '=', 'goods_receipt_items.goods_receipt_id')
            ->join('suppliers', 'suppliers.id', '=', 'goods_receipts.supplier_id')
            ->where('goods_receipts.status', 'received')
            ->whereIn('goods_receipt_items.product_variant_id', $variantIds)
            ->when($accessible !== null, fn ($q) => $q->whereIn('goods_receipts.warehouse_id', $accessible))
            ->orderByDesc('goods_receipts.received_at')
            ->orderByDesc('goods_receipts.id')
            ->get(['goods_receipt_items.product_variant_id', 'suppliers.name'])
            ->unique('product_variant_id')
            ->mapWithKeys(fn ($row) => [(int) $row->product_variant_id => $row->name]);
    }
}
