<?php

namespace App\Services;

use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockLedger;
use App\Models\StockMovement;
use App\Models\StockMovementItem;
use App\Models\WarehouseLocation;
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
     * @throws ValidationException bila stok di lokasi asal tidak cukup
     */
    public function record(array $header, array $items): StockMovement
    {
        return DB::transaction(function () use ($header, $items) {
            $this->lockVariants($items);

            $movement = StockMovement::create([
                ...$header,
                'number' => $this->nextNumber($header['type']),
            ]);

            foreach (array_values($items) as $index => $row) {
                $item = $movement->items()->create($row);

                foreach ($this->deltas($movement->type, $item) as [$locationId, $delta]) {
                    $this->apply($movement, $item, $locationId, $delta, $index);
                }
            }

            return $movement;
        }, self::ATTEMPTS);
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

    private function apply(StockMovement $movement, StockMovementItem $item, ?int $locationId, int $delta, int $index): void
    {
        $warehouseId = $movement->warehouse_id;

        // Varian sudah dikunci di record(), jadi baris saldo (termasuk "Tanpa bin" yang
        // tidak dijaga unique index) tidak bisa tercipta ganda oleh transaksi lain.
        $stock = Stock::query()
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

        $last = StockMovement::withoutGlobalScope('warehouse_access')->where('number', 'like', "{$prefix}%")->lockForUpdate()->max('number');
        $sequence = $last ? (int) substr($last, strlen($prefix)) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
