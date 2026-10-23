<?php

namespace App\Services;

use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\Stock;
use App\Models\StockLedger;
use App\Models\StockMovement;
use App\Models\StockMovementItem;
use App\Models\WarehouseLocation;
use App\Support\DocumentNumber;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Satu-satunya pintu perubahan stok: menyimpan dokumen mutasi, memperbarui
 * saldo per lokasi dan menulis kartu stok dalam satu transaksi.
 */
class StockService
{
    /** Transaksi diulang otomatis bila kena deadlock dengan pengguna lain. */
    private const ATTEMPTS = 3;

    /**
     * Penyesuaian memakai to_location_id dengan qty bertanda (+ tambah, - kurang).
     * Rakit / campur juga bertanda: - keluar dari from_location_id, + masuk ke to_location_id.
     * Lokasi bin boleh null: stok disimpan di level gudang ("Tanpa bin").
     *
     * @param  array{type: string, warehouse_id: int, movement_date: string, reference?: ?string, notes?: ?string, created_by?: ?int}  $header
     * @param  array<int, array{product_variant_id: int, from_location_id?: ?int, to_location_id?: ?int, qty: int, notes?: ?string}>  $items
     *
     * $protectOrders: stok yang sudah di-picking (per bin) dan yang dicadangkan pesanan
     * belum dikirim (per gudang) tidak boleh dipakai dokumen ini. Hanya pengiriman
     * pesanan sendiri (yang memang mengeluarkan stok hasil picking-nya) yang mematikannya.
     *
     * @throws ValidationException bila stok di lokasi asal tidak cukup atau sudah untuk pesanan
     */
    public function record(array $header, array $items, bool $protectOrders = true): StockMovement
    {
        return DB::transaction(function () use ($header, $items, $protectOrders) {
            $this->lockVariants($items);

            $variantIds = collect($items)->pluck('product_variant_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
            $before = $protectOrders ? $this->onHand($header['warehouse_id'], $variantIds) : collect();

            $movement = StockMovement::create([
                ...$header,
                'number' => $this->nextNumber($header['type']),
            ]);

            foreach (array_values($items) as $index => $row) {
                $item = $movement->items()->create($row);

                foreach ($this->deltas($movement->type, $item) as [$locationId, $delta]) {
                    $this->apply($movement, $item, $locationId, $delta, $index, $protectOrders);
                }
            }

            if ($protectOrders) {
                $this->ensureReservationsKept($movement->warehouse_id, $items, $before);
            }

            return $movement;
        }, self::ATTEMPTS);
    }

    /**
     * Saldo gudang per varian.
     *
     * @param  list<int>  $variantIds
     * @return Collection<int, int>
     */
    private function onHand(int $warehouseId, array $variantIds): Collection
    {
        return Stock::withoutGlobalScope('warehouse_access')
            ->where('warehouse_id', $warehouseId)
            ->whereIn('product_variant_id', $variantIds)
            ->groupBy('product_variant_id')
            ->selectRaw('product_variant_id, SUM(qty) as qty')
            ->pluck('qty', 'product_variant_id')
            ->map(fn ($qty) => (int) $qty);
    }

    /**
     * Stok gudang yang berkurang tidak boleh turun di bawah cadangan pesanan yang
     * belum dikirim (dikonfirmasi / diambil / dikemas). Pindah bin di gudang yang
     * sama tidak mengubah saldo gudang, jadi lolos.
     *
     * @param  array<int, array{product_variant_id: int}>  $items
     * @param  Collection<int, int>  $before
     *
     * @throws ValidationException
     */
    private function ensureReservationsKept(int $warehouseId, array $items, Collection $before): void
    {
        $rows = collect(array_values($items))->map(fn ($row, $index) => ['variant' => (int) $row['product_variant_id'], 'index' => $index]);
        $variantIds = $rows->pluck('variant')->unique()->values()->all();
        $after = $this->onHand($warehouseId, $variantIds);
        $dropped = collect($variantIds)->filter(fn ($id) => ($after[$id] ?? 0) < ($before[$id] ?? 0));

        if ($dropped->isEmpty()) {
            return;
        }

        $reserved = DB::table('sales_order_items')
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_items.sales_order_id')
            ->where('sales_orders.warehouse_id', $warehouseId)
            ->whereIn('sales_orders.status', SalesOrder::RESERVING)
            ->whereIn('sales_order_items.product_variant_id', $dropped->all())
            ->groupBy('sales_order_items.product_variant_id')
            ->selectRaw('sales_order_items.product_variant_id, SUM(sales_order_items.qty) as qty')
            ->pluck('qty', 'product_variant_id');

        $messages = [];

        foreach ($dropped as $variantId) {
            $held = (int) ($reserved[$variantId] ?? 0);
            $left = $after[$variantId] ?? 0;

            if ($left < $held) {
                $sku = ProductVariant::whereKey($variantId)->value('sku');
                $free = max(0, ($before[$variantId] ?? 0) - $held);
                $index = $rows->firstWhere('variant', $variantId)['index'];
                $messages["items.{$index}.qty"] = "Stok {$sku} di gudang ini dicadangkan {$held} pcs untuk pesanan yang belum dikirim; yang bebas dipakai hanya {$free}. Kirim, batalkan, atau kembalikan pesanannya ke draft dulu.";
            }
        }

        if ($messages) {
            throw ValidationException::withMessages($messages);
        }
    }

    /**
     * Qty yang sudah dialokasikan picking (pesanan diambil / dikemas) di satu bin,
     * beserta nomor pesanannya.
     *
     * @return array{qty: int, orders: list<string>}
     */
    private function allocatedAt(int $warehouseId, int $variantId, ?int $locationId): array
    {
        $rows = DB::table('sales_order_picks')
            ->join('sales_order_items', 'sales_order_items.id', '=', 'sales_order_picks.sales_order_item_id')
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_items.sales_order_id')
            ->where('sales_orders.warehouse_id', $warehouseId)
            ->whereIn('sales_orders.status', ['picking', 'packed'])
            ->where('sales_order_items.product_variant_id', $variantId)
            ->when($locationId === null, fn ($q) => $q->whereNull('sales_order_picks.warehouse_location_id'), fn ($q) => $q->where('sales_order_picks.warehouse_location_id', $locationId))
            ->groupBy('sales_orders.number')
            ->selectRaw('sales_orders.number, SUM(sales_order_picks.qty) as qty')
            ->pluck('qty', 'number');

        return ['qty' => (int) $rows->sum(), 'orders' => $rows->keys()->all()];
    }

    /**
     * Kunci semua varian yang disentuh mutasi ini, urut id, sebelum membaca saldo.
     * Dua mutasi (atau pesanan/opname) bersamaan untuk varian yang sama jadi antre,
     * jadi pengecekan "stok cukup" di bawah selalu membaca saldo terbaru. Urutan id
     * yang sama di semua tempat mencegah deadlock antar dokumen multi-baris.
     *
     * @param  array<int, array{product_variant_id: int}>  $items
     */
    private function lockVariants(array $items): void
    {
        $ids = collect($items)->pluck('product_variant_id')->map(fn ($id) => (int) $id)->unique()->sort()->values();

        ProductVariant::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id']);
    }

    /**
     * Perubahan saldo yang ditimbulkan satu baris mutasi.
     *
     * @return array<int, array{0: ?int, 1: int}> [lokasi, delta]
     */
    private function deltas(string $type, StockMovementItem $item): array
    {
        return match ($type) {
            'in' => [[$item->to_location_id, $item->qty]],
            'out' => [[$item->from_location_id, -$item->qty]],
            'transfer' => [[$item->from_location_id, -$item->qty], [$item->to_location_id, $item->qty]],
            'adjustment' => [[$item->to_location_id, $item->qty]],
            'damage' => [[$item->from_location_id, -$item->qty]],
            'repair' => [[$item->to_location_id, $item->qty]],
            'transfer_out' => [[$item->from_location_id, -$item->qty]],
            'transfer_in' => [[$item->to_location_id, $item->qty]],
            'assembly' => $item->qty < 0 ? [[$item->from_location_id, $item->qty]] : [[$item->to_location_id, $item->qty]],
        };
    }

    private function apply(StockMovement $movement, StockMovementItem $item, ?int $locationId, int $delta, int $index, bool $protectOrders): void
    {
        $warehouseId = $movement->warehouse_id;

        // Varian sudah dikunci di record(), jadi baris saldo (termasuk "Tanpa bin" yang
        // tidak dijaga unique index) tidak bisa tercipta ganda oleh transaksi lain.
        // Tanpa batas akses user: saldo yang tak terlihat user tidak boleh dibuat ganda.
        $stock = Stock::withoutGlobalScope('warehouse_access')
            ->where('product_variant_id', $item->product_variant_id)
            ->when(
                $locationId === null,
                fn ($query) => $query->where('warehouse_id', $warehouseId)->whereNull('warehouse_location_id'),
                fn ($query) => $query->where('warehouse_location_id', $locationId),
            )
            ->lockForUpdate()
            ->first();

        $current = $stock?->qty ?? 0;

        if ($current + $delta < 0) {
            $item->loadMissing('variant');
            $place = $locationId === null ? 'tanpa bin' : 'di lokasi '.WarehouseLocation::whereKey($locationId)->value('code');

            throw ValidationException::withMessages([
                "items.{$index}.qty" => "Stok {$item->variant->sku} {$place} tidak cukup (tersedia {$current}).",
            ]);
        }

        // Stok bin yang sudah diambil picker untuk pesanan lain tidak boleh dipindah / dikeluarkan.
        if ($protectOrders && $delta < 0) {
            $allocated = $this->allocatedAt($warehouseId, $item->product_variant_id, $locationId);

            if ($allocated['qty'] > 0 && $current + $delta < $allocated['qty']) {
                $item->loadMissing('variant');
                $place = $locationId === null ? 'tanpa bin' : 'di lokasi '.WarehouseLocation::whereKey($locationId)->value('code');
                $orders = implode(', ', array_slice($allocated['orders'], 0, 5)).(count($allocated['orders']) > 5 ? ', …' : '');

                throw ValidationException::withMessages([
                    "items.{$index}.qty" => "Stok {$item->variant->sku} {$place}: {$allocated['qty']} pcs sudah diambil untuk pesanan {$orders}, jadi yang bisa dipakai hanya ".max(0, $current - $allocated['qty']).'. Kirim pesanan itu atau kembalikan ke draft dulu.',
                ]);
            }
        }

        if ($stock) {
            $stock->update(['qty' => $current + $delta]);
        } else {
            Stock::create([
                'product_variant_id' => $item->product_variant_id,
                'warehouse_id' => $warehouseId,
                'warehouse_location_id' => $locationId,
                'qty' => $delta,
            ]);
        }

        StockLedger::create([
            'product_variant_id' => $item->product_variant_id,
            'warehouse_id' => $warehouseId,
            'warehouse_location_id' => $locationId,
            'stock_movement_id' => $movement->id,
            'stock_movement_item_id' => $item->id,
            'type' => $movement->type,
            'movement_date' => $movement->movement_date,
            'qty_in' => max($delta, 0),
            'qty_out' => max(-$delta, 0),
        ]);
    }

    /** Nomor dokumen berurutan per jenis per bulan, cth. MSK-202610-0001. */
    private function nextNumber(string $type): string
    {
        $prefix = StockMovement::typeMeta($type, 'prefix').'-'.now()->format('Ym').'-';

        return DocumentNumber::next(StockMovement::withoutGlobalScope('warehouse_access'), $prefix, 4);
    }
}
