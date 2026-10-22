<?php

namespace App\Support;

use App\Models\ProductVariant;
use App\Models\SalesOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ringkasan stok per SKU untuk halaman Stok: fisik, dipesan (cadangan pesanan
 * terbuka), tersedia, tertahan (pesanan draft) dan status. Hanya membaca data;
 * angka cadangan memakai aturan yang sama dengan SalesOrderService.
 */
class StockOverview
{
    /** Fisik di semua bin gudang terpilih. */
    public const ON_HAND = 'COALESCE(on_hand.qty, 0)';

    /** Cadangan pesanan Dikonfirmasi + Sudah Diambil + Dikemas. */
    public const RESERVED = '(COALESCE(reserved.waiting, 0) + COALESCE(reserved.picked, 0))';

    public const AVAILABLE = '(COALESCE(on_hand.qty, 0) - COALESCE(reserved.waiting, 0) - COALESCE(reserved.picked, 0))';

    /** Qty di pesanan Draft (belum mencadangkan stok). */
    public const HELD = 'COALESCE(held.qty, 0)';

    /** Filter cepat yang tersedia di halaman. */
    public const FILTERS = [
        'ordered' => 'Ada pesanan',
        'short' => 'Kurang stok',
        'low' => 'Di bawah minimum',
        'out' => 'Habis',
    ];

    /** Label, warna badge & keterangan status. */
    public const STATUSES = [
        'short' => ['label' => 'Kurang', 'color' => 'rose', 'hint' => 'Pesanan (termasuk draft) melebihi stok tersedia'],
        'out' => ['label' => 'Habis', 'color' => 'slate', 'hint' => 'Tidak ada stok tersedia'],
        'low' => ['label' => 'Menipis', 'color' => 'amber', 'hint' => 'Stok tersedia di bawah atau sama dengan stok minimum'],
        'ok' => ['label' => 'Aman', 'color' => 'emerald', 'hint' => 'Stok tersedia cukup'],
    ];

    /**
     * Varian beserta angka stoknya. Tanpa $withZero hanya varian yang punya
     * stok fisik atau pesanan terbuka.
     */
    public static function query(?int $warehouseId, bool $withZero = false, ?string $filter = null): Builder
    {
        $minimum = self::minimum($warehouseId);
        $available = self::AVAILABLE;
        $held = self::HELD;

        $query = ProductVariant::query()
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->leftJoinSub(self::onHandQuery($warehouseId), 'on_hand', 'on_hand.product_variant_id', '=', 'product_variants.id')
            ->leftJoinSub(self::reservedQuery($warehouseId), 'reserved', 'reserved.product_variant_id', '=', 'product_variants.id')
            ->leftJoinSub(self::heldQuery($warehouseId), 'held', 'held.product_variant_id', '=', 'product_variants.id')
            ->when($warehouseId, fn ($q) => $q->leftJoin('warehouse_variant_settings as wvs', fn ($join) => $join
                ->on('wvs.product_variant_id', '=', 'product_variants.id')
                ->where('wvs.warehouse_id', $warehouseId)))
            ->selectRaw('product_variants.*')
            ->selectRaw(self::ON_HAND.' as on_hand')
            ->selectRaw('COALESCE(reserved.waiting, 0) as reserved_waiting')
            ->selectRaw('COALESCE(reserved.picked, 0) as reserved_picked')
            ->selectRaw(self::RESERVED.' as reserved')
            ->selectRaw("{$available} as available")
            ->selectRaw("{$held} as held")
            ->selectRaw('COALESCE(on_hand.locations, 0) as location_count')
            ->selectRaw("{$minimum} as minimum");

        if (! $withZero) {
            $query->where(fn ($q) => $q
                ->whereRaw(self::ON_HAND.' != 0')
                ->orWhereRaw(self::RESERVED.' > 0')
                ->orWhereRaw("{$held} > 0"));
        }

        match ($filter) {
            'ordered' => $query->where(fn ($q) => $q->whereRaw(self::RESERVED.' > 0')->orWhereRaw("{$held} > 0")),
            'short' => $query->whereRaw(self::shortCondition()),
            'low' => $query->whereRaw("{$minimum} IS NOT NULL AND {$available} <= {$minimum}"),
            'out' => $query->whereRaw("{$available} <= 0"),
            default => null,
        };

        return $query;
    }

    /** Status satu baris hasil query(). */
    public static function status(object $row): string
    {
        $available = (int) $row->available;
        $held = (int) $row->held;

        return match (true) {
            $available < 0 || ($held > 0 && $held > $available) => 'short',
            $available <= 0 => 'out',
            $row->minimum !== null && $available <= (int) $row->minimum => 'low',
            default => 'ok',
        };
    }

    /**
     * Total untuk kartu ringkasan dari query yang sudah difilter.
     *
     * @return array{on_hand: int, reserved: int, available: int, held: int, short: int, skus: int}
     */
    public static function totals(Builder $query): array
    {
        $row = DB::query()->fromSub($query->toBase(), 'rows')
            ->selectRaw('COUNT(*) as skus, SUM(on_hand) as on_hand, SUM(reserved) as reserved, SUM(available) as available, SUM(held) as held')
            ->selectRaw('SUM(CASE WHEN available < 0 OR (held > 0 AND held > available) THEN 1 ELSE 0 END) as short')
            ->first();

        return [
            'skus' => (int) ($row->skus ?? 0),
            'on_hand' => (int) ($row->on_hand ?? 0),
            'reserved' => (int) ($row->reserved ?? 0),
            'available' => (int) ($row->available ?? 0),
            'held' => (int) ($row->held ?? 0),
            'short' => (int) ($row->short ?? 0),
        ];
    }

    /**
     * Qty yang sudah dialokasikan picking (pesanan diambil/dikemas, belum
     * dikirim) per varian, gudang & bin. Kunci: "varian:gudang:bin|none".
     *
     * @return Collection<string, int>
     */
    public static function pickedByLocation(?int $warehouseId = null): Collection
    {
        $accessible = WarehouseAccess::ids();

        return DB::table('sales_order_picks')
            ->join('sales_order_items', 'sales_order_items.id', '=', 'sales_order_picks.sales_order_item_id')
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_items.sales_order_id')
            ->whereIn('sales_orders.status', ['picked', 'packed'])
            ->when($warehouseId, fn ($q) => $q->where('sales_orders.warehouse_id', $warehouseId))
            ->when($accessible !== null, fn ($q) => $q->whereIn('sales_orders.warehouse_id', $accessible))
            ->groupBy('sales_order_items.product_variant_id', 'sales_orders.warehouse_id', 'sales_order_picks.warehouse_location_id')
            ->selectRaw('sales_order_items.product_variant_id as variant_id, sales_orders.warehouse_id, sales_order_picks.warehouse_location_id as location_id, SUM(sales_order_picks.qty) as qty')
            ->get()
            ->mapWithKeys(fn ($row) => [self::locationKey($row->variant_id, $row->warehouse_id, $row->location_id) => (int) $row->qty]);
    }

    public static function locationKey(int|string $variantId, int|string $warehouseId, int|string|null $locationId): string
    {
        return $variantId.':'.$warehouseId.':'.($locationId ?? 'none');
    }

    private static function shortCondition(): string
    {
        $available = self::AVAILABLE;
        $held = self::HELD;

        return "({$available} < 0 OR ({$held} > 0 AND {$held} > {$available}))";
    }

    /** Stok minimum: setelan per gudang bila satu gudang dipilih, selain itu minimum di master produk. */
    private static function minimum(?int $warehouseId): string
    {
        return $warehouseId ? 'COALESCE(wvs.min_qty, product_variants.min_stock)' : 'product_variants.min_stock';
    }

    private static function onHandQuery(?int $warehouseId): QueryBuilder
    {
        $accessible = WarehouseAccess::ids();

        return DB::table('stocks')
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->when($accessible !== null, fn ($q) => $q->whereIn('warehouse_id', $accessible))
            ->groupBy('product_variant_id')
            ->selectRaw('product_variant_id, SUM(qty) as qty, SUM(CASE WHEN qty != 0 THEN 1 ELSE 0 END) as locations');
    }

    private static function reservedQuery(?int $warehouseId): QueryBuilder
    {
        return self::orderLines($warehouseId, SalesOrder::RESERVING)
            ->selectRaw("sales_order_items.product_variant_id,
                SUM(CASE WHEN sales_orders.status = 'confirmed' THEN sales_order_items.qty ELSE 0 END) as waiting,
                SUM(CASE WHEN sales_orders.status != 'confirmed' THEN sales_order_items.qty ELSE 0 END) as picked");
    }

    private static function heldQuery(?int $warehouseId): QueryBuilder
    {
        return self::orderLines($warehouseId, ['draft'])
            ->selectRaw('sales_order_items.product_variant_id, SUM(sales_order_items.qty) as qty');
    }

    /** @param  list<string>  $statuses */
    private static function orderLines(?int $warehouseId, array $statuses): QueryBuilder
    {
        $accessible = WarehouseAccess::ids();

        return DB::table('sales_order_items')
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_items.sales_order_id')
            ->whereIn('sales_orders.status', $statuses)
            ->whereNotNull('sales_order_items.product_variant_id')
            ->when($warehouseId, fn ($q) => $q->where('sales_orders.warehouse_id', $warehouseId))
            ->when($accessible !== null, fn ($q) => $q->whereIn('sales_orders.warehouse_id', $accessible))
            ->groupBy('sales_order_items.product_variant_id');
    }
}
