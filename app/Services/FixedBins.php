<?php

namespace App\Services;

use App\Models\Stock;
use App\Models\WarehouseVariantLocation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lokasi tetap SKU berurutan (bin utama lalu cadangan): dipakai untuk saran
 * bin saat penerimaan & transfer, dan urutan bin saat picking.
 */
class FixedBins
{
    /**
     * Bin lokasi tetap per gudang+varian, urut prioritas, dengan sisa ruang
     * (kapasitas dikurangi stok di bin itu; null = tanpa batas).
     *
     * @param  list<int>  $warehouseIds
     * @param  list<int>|null  $variantIds
     * @return Collection<string, Collection<int, array{location_id: int, priority: int, capacity: ?int, room: ?int}>> key "gudang:varian"
     */
    public function for(array $warehouseIds, ?array $variantIds = null): Collection
    {
        $rows = WarehouseVariantLocation::withoutGlobalScope('warehouse_access')
            ->whereIn('warehouse_id', $warehouseIds)
            ->when($variantIds !== null, fn ($q) => $q->whereIn('product_variant_id', $variantIds))
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            return collect();
        }

        $stock = Stock::withoutGlobalScope('warehouse_access')
            ->whereIn('warehouse_location_id', $rows->pluck('warehouse_location_id')->unique()->all())
            ->whereIn('product_variant_id', $rows->pluck('product_variant_id')->unique()->all())
            ->get(['product_variant_id', 'warehouse_location_id', 'qty'])
            ->mapWithKeys(fn ($s) => [$s->product_variant_id.':'.$s->warehouse_location_id => (int) $s->qty]);

        return $rows->groupBy(fn ($row) => $row->warehouse_id.':'.$row->product_variant_id)
            ->map(fn ($bins) => $bins->values()->map(fn ($row) => [
                'location_id' => $row->warehouse_location_id,
                'priority' => $row->priority,
                'capacity' => $row->capacity,
                'room' => $row->capacity === null ? null : max(0, $row->capacity - ($stock[$row->product_variant_id.':'.$row->warehouse_location_id] ?? 0)),
            ]));
    }

    /**
     * Data untuk form (penerimaan/transfer): [gudang, varian, [[lokasi, sisa ruang|null], ...]].
     *
     * @param  list<int>  $warehouseIds
     * @return list<array{0: int, 1: int, 2: list<array{0: int, 1: ?int}>}>
     */
    public function forForm(array $warehouseIds): array
    {
        return $this->for($warehouseIds)->map(function ($bins, $key) {
            [$warehouse, $variant] = array_map('intval', explode(':', $key));

            return [$warehouse, $variant, $bins->map(fn ($bin) => [$bin['location_id'], $bin['room']])->all()];
        })->values()->all();
    }

    /**
     * Bagi qty ke bin lokasi tetap: isi bin utama sampai sisa ruangnya habis,
     * lalu bin berikutnya; sisa yang tidak muat masuk bin terakhir.
     *
     * @param  Collection<int, array{location_id: int, room: ?int}>|array  $bins
     * @return list<array{location_id: int, qty: int}>
     */
    public function split($bins, int $qty): array
    {
        $bins = array_values(collect($bins)->all());
        $rows = [];

        foreach ($bins as $i => $bin) {
            if ($qty <= 0) {
                break;
            }
            $last = $i === count($bins) - 1;
            $take = $last || $bin['room'] === null ? $qty : min($qty, $bin['room']);
            if ($take > 0) {
                $rows[] = ['location_id' => $bin['location_id'], 'qty' => $take];
                $qty -= $take;
            }
        }

        return $rows;
    }

    /**
     * Simpan urutan bin lokasi tetap satu SKU di satu gudang. Bin pertama jadi
     * default_location_id (bin utama) di pengaturan SKU.
     *
     * @param  list<array{location_id: int, capacity: ?int}>  $bins
     */
    public function sync(int $warehouseId, int $variantId, array $bins): void
    {
        DB::transaction(function () use ($warehouseId, $variantId, $bins) {
            WarehouseVariantLocation::withoutGlobalScope('warehouse_access')
                ->where('warehouse_id', $warehouseId)->where('product_variant_id', $variantId)->delete();

            foreach (array_values($bins) as $i => $bin) {
                WarehouseVariantLocation::create([
                    'warehouse_id' => $warehouseId,
                    'product_variant_id' => $variantId,
                    'warehouse_location_id' => $bin['location_id'],
                    'priority' => $i + 1,
                    'capacity' => $bin['capacity'],
                ]);
            }
        });
    }

    /**
     * Prioritas bin lokasi tetap per varian di satu gudang: [varian => [lokasi => prioritas]].
     *
     * @param  list<int>  $variantIds
     * @return array<int, array<int, int>>
     */
    public function priorities(int $warehouseId, array $variantIds): array
    {
        return WarehouseVariantLocation::withoutGlobalScope('warehouse_access')
            ->where('warehouse_id', $warehouseId)
            ->whereIn('product_variant_id', $variantIds)
            ->get(['product_variant_id', 'warehouse_location_id', 'priority'])
            ->groupBy('product_variant_id')
            ->map(fn ($rows) => $rows->pluck('priority', 'warehouse_location_id')->all())
            ->all();
    }
}
