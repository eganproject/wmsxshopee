<?php

namespace App\Support;

use App\Models\StockLedger;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Analisis stok untuk laporan:
 *
 *   Prakiraan stok habis : stok tersedia ÷ rata-rata terjual per hari = sisa hari.
 *   Saldo per tanggal    : saldo stok di akhir tanggal tertentu dari kartu stok.
 *   Fast / medium / slow : SKU diurutkan dari qty terjual terbanyak; SKU yang
 *                          menyumbang 80% penjualan pertama = fast, 15% berikutnya =
 *                          medium, sisanya slow, tidak terjual sama sekali = tidak bergerak.
 */
class StockAnalytics
{
    /** Status prakiraan stok habis, urut dari yang paling mendesak. */
    public const RUNOUT_STATUSES = [
        'out' => ['label' => 'Habis', 'color' => 'rose', 'hint' => 'Stok tersedia 0 atau minus, padahal barang ini terjual.'],
        'critical' => ['label' => 'Kritis', 'color' => 'rose', 'hint' => 'Perkiraan habis dalam batas hari kritis.'],
        'warning' => ['label' => 'Waspada', 'color' => 'amber', 'hint' => 'Perkiraan habis dalam batas hari waspada.'],
        'safe' => ['label' => 'Aman', 'color' => 'emerald', 'hint' => 'Stok cukup lebih lama dari batas waspada.'],
        'idle' => ['label' => 'Tidak Terjual', 'color' => 'slate', 'hint' => 'Ada stok tapi tidak terjual dalam periode hitung, jadi tidak bisa diperkirakan.'],
    ];

    /** Kelas pergerakan barang. */
    public const MOVING_CLASSES = [
        'fast' => ['label' => 'Fast Moving', 'color' => 'emerald'],
        'medium' => ['label' => 'Medium Moving', 'color' => 'sky'],
        'slow' => ['label' => 'Slow Moving', 'color' => 'amber'],
        'dead' => ['label' => 'Tidak Bergerak', 'color' => 'slate'],
    ];

    /** Jenis mutasi yang dihitung sebagai barang terjual / keluar ke pembeli. */
    private const SOLD_TYPES = ['out'];

    /**
     * Prakiraan stok habis per varian × gudang.
     *
     * @return Collection<int, object>
     */
    public static function runout(?int $warehouseId, int $lookbackDays, int $criticalDays, int $warningDays, ?string $search = null): Collection
    {
        $since = now()->subDays($lookbackDays)->startOfDay();
        $accessible = WarehouseAccess::ids();

        $sold = DB::table('sales_order_items')
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_items.sales_order_id')
            ->where('sales_orders.status', 'shipped')
            ->where('sales_orders.shipped_at', '>=', $since)
            ->when($warehouseId, fn ($q) => $q->where('sales_orders.warehouse_id', $warehouseId))
            ->when($accessible !== null, fn ($q) => $q->whereIn('sales_orders.warehouse_id', $accessible))
            ->groupBy('sales_order_items.product_variant_id', 'sales_orders.warehouse_id')
            ->selectRaw('sales_order_items.product_variant_id, sales_orders.warehouse_id, SUM(sales_order_items.qty) as qty, MAX(sales_orders.shipped_at) as last_sold_at');

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
            // Hanya pasangan SKU × gudang yang relevan: ada stok / cadangan / setelan, atau terjual.
            ->where(fn ($q) => $q->whereRaw(StockLevels::CARRIED)->orWhere('sold.qty', '>', 0))
            ->when(filled($search), fn ($q) => self::search($q, $search))
            ->toBase()
            ->selectRaw('product_variants.id, product_variants.sku, product_variants.name as variant_name,
                products.name as product_name, units.code as unit_code,
                warehouses.id as warehouse_id, warehouses.code as warehouse_code,
                COALESCE(on_hand.qty, 0) as on_hand, COALESCE(reserved.qty, 0) as reserved,
                COALESCE(sold.qty, 0) as sold, sold.last_sold_at, COALESCE(incoming.qty, 0) as incoming')
            ->get();

        $rows = $rows->map(function ($row) use ($lookbackDays, $criticalDays, $warningDays) {
            foreach (['on_hand', 'reserved', 'sold', 'incoming'] as $field) {
                $row->{$field} = (int) $row->{$field};
            }
            $row->available = $row->on_hand - $row->reserved;
            $row->daily = $row->sold / $lookbackDays;
            $row->days_left = $row->daily > 0 ? (int) floor(max(0, $row->available) / $row->daily) : null;
            $row->runout_date = $row->days_left !== null ? now()->addDays($row->days_left)->startOfDay() : null;
            $row->status = match (true) {
                $row->available <= 0 && $row->sold > 0 => 'out',
                $row->days_left === null => 'idle',
                $row->days_left <= $criticalDays => 'critical',
                $row->days_left <= $warningDays => 'warning',
                default => 'safe',
            };

            return $row;
        })
            // Barang yang tidak terjual dan stoknya kosong tidak perlu diperkirakan.
            ->reject(fn ($row) => $row->status === 'idle' && $row->available <= 0);

        $order = array_flip(array_keys(self::RUNOUT_STATUSES));

        return $rows->sort(fn ($a, $b) => [$order[$a->status], $a->days_left ?? PHP_INT_MAX, -$a->daily, $a->sku, $a->warehouse_code]
            <=> [$order[$b->status], $b->days_left ?? PHP_INT_MAX, -$b->daily, $b->sku, $b->warehouse_code])->values();
    }

    /**
     * Saldo stok di akhir tanggal $date per varian (per gudang bila $perWarehouse),
     * dihitung dari kartu stok. Stok rusak tidak termasuk.
     */
    public static function balanceAsOf(CarbonInterface $date, ?int $warehouseId, bool $perWarehouse, ?string $search = null, bool $includeZero = false): Builder
    {
        $end = $date->copy()->addDay()->toDateString();

        $query = StockLedger::query()
            ->join('product_variants', 'product_variants.id', '=', 'stock_ledgers.product_variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->leftJoin('units', 'units.id', '=', 'products.unit_id')
            ->join('warehouses', 'warehouses.id', '=', 'stock_ledgers.warehouse_id')
            ->where('stock_ledgers.movement_date', '<', $end)
            ->when($warehouseId, fn ($q) => $q->where('stock_ledgers.warehouse_id', $warehouseId))
            ->when(filled($search), fn ($q) => self::search($q, $search))
            ->groupBy('stock_ledgers.product_variant_id', 'product_variants.sku', 'product_variants.name', 'products.name', 'units.code')
            ->orderBy('product_variants.sku')
            // qty_in/qty_out unsigned: dijumlahkan dulu baru dikurangkan (lihat laporan Pergerakan Barang).
            ->selectRaw('stock_ledgers.product_variant_id, product_variants.sku, product_variants.name as variant_name,
                products.name as product_name, units.code as unit_code,
                SUM(stock_ledgers.qty_in) - SUM(stock_ledgers.qty_out) as balance,
                MAX(stock_ledgers.movement_date) as last_movement');

        if ($perWarehouse) {
            $query->groupBy('stock_ledgers.warehouse_id', 'warehouses.code')
                ->orderBy('warehouses.code')
                ->addSelect(['stock_ledgers.warehouse_id', 'warehouses.code as warehouse_code']);
        }

        if (! $includeZero) {
            $query->havingRaw('SUM(stock_ledgers.qty_in) - SUM(stock_ledgers.qty_out) <> 0');
        }

        return $query;
    }

    /**
     * Klasifikasi fast / medium / slow moving per varian dari qty terjual
     * (mutasi Barang Keluar) dalam rentang tanggal.
     *
     * @return Collection<int, object>
     */
    public static function movingClasses(CarbonInterface $from, CarbonInterface $to, ?int $warehouseId, int $fastShare, int $mediumShare, ?string $search = null): Collection
    {
        $start = $from->toDateString();
        $end = $to->copy()->addDay()->toDateString();
        $days = max(1, (int) $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1);
        $sold = "'".implode("','", self::SOLD_TYPES)."'";

        $rows = StockLedger::query()
            ->join('product_variants', 'product_variants.id', '=', 'stock_ledgers.product_variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->leftJoin('units', 'units.id', '=', 'products.unit_id')
            ->where('stock_ledgers.movement_date', '<', $end)
            ->when($warehouseId, fn ($q) => $q->where('stock_ledgers.warehouse_id', $warehouseId))
            ->when(filled($search), fn ($q) => self::search($q, $search))
            ->groupBy('stock_ledgers.product_variant_id', 'product_variants.sku', 'product_variants.name', 'products.name', 'units.code', 'product_variants.is_active')
            ->selectRaw("stock_ledgers.product_variant_id as id, product_variants.sku, product_variants.name as variant_name,
                products.name as product_name, units.code as unit_code, product_variants.is_active,
                SUM(CASE WHEN stock_ledgers.movement_date >= '{$start}' AND stock_ledgers.type IN ({$sold}) THEN stock_ledgers.qty_out ELSE 0 END) as sold,
                SUM(CASE WHEN stock_ledgers.movement_date >= '{$start}' AND stock_ledgers.type IN ({$sold}) THEN 1 ELSE 0 END) as out_count,
                MAX(CASE WHEN stock_ledgers.type IN ({$sold}) THEN stock_ledgers.movement_date END) as last_sold,
                SUM(stock_ledgers.qty_in) - SUM(stock_ledgers.qty_out) as stock")
            ->toBase()
            ->get()
            ->map(function ($row) use ($days) {
                $row->sold = (int) $row->sold;
                $row->out_count = (int) $row->out_count;
                $row->stock = (int) $row->stock;
                $row->daily = $row->sold / $days;
                // Berapa hari stok sekarang cukup dengan kecepatan jual periode ini.
                $row->cover_days = $row->daily > 0 ? (int) floor(max(0, $row->stock) / $row->daily) : null;

                return $row;
            })
            // SKU yang tidak terjual dan tidak punya stok tidak relevan.
            ->reject(fn ($row) => $row->sold === 0 && $row->stock <= 0)
            ->sortBy([['sold', 'desc'], ['sku', 'asc']])
            ->values();

        $total = max(1, $rows->sum('sold'));
        $running = 0;

        return $rows->map(function ($row) use ($total, &$running, $fastShare, $mediumShare) {
            if ($row->sold === 0) {
                $row->share = 0.0;
                $row->cumulative = 100.0;
                $row->class = 'dead';

                return $row;
            }

            // Kelas ditentukan dari posisi SKU sebelum ditambahkan: SKU yang
            // memulai di bawah batas fast tetap fast walau melewatinya.
            $before = $running / $total * 100;
            $running += $row->sold;
            $row->share = $row->sold / $total * 100;
            $row->cumulative = $running / $total * 100;
            $row->class = $before < $fastShare ? 'fast' : ($before < $mediumShare ? 'medium' : 'slow');

            return $row;
        });
    }

    /** @param  Builder|\Illuminate\Database\Query\Builder  $query */
    private static function search($query, string $search): void
    {
        $like = '%'.trim($search).'%';
        $query->where(fn ($q) => $q->where('product_variants.sku', 'like', $like)->orWhere('products.name', 'like', $like));
    }
}
