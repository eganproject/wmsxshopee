<?php

namespace App\Services;

use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockCount;
use App\Models\WarehouseLocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stock opname: buat lembar hitung dari saldo saat ini, simpan hasil hitung,
 * lalu posting selisihnya sebagai satu mutasi Penyesuaian lewat StockService.
 *
 * Saat posting, stok disetel tepat ke hasil hitung terhadap saldo aktual saat
 * itu (bukan saldo saat lembar dibuat), jadi barang yang keluar/masuk selama
 * penghitungan tidak membuat selisih ganda.
 */
class StockCountService
{
    public function __construct(private StockService $stock) {}

    /**
     * @param  array{warehouse_id: int, scope: string, warehouse_zone_id?: ?int, count_date: string, notes?: ?string, created_by?: ?int}  $header
     */
    public function create(array $header): StockCount
    {
        return DB::transaction(function () use ($header) {
            $count = StockCount::create([
                ...$header,
                'number' => StockCount::nextNumber(),
                'status' => 'draft',
            ]);

            $stocks = $this->scopedStocks($count)
                ->where('stocks.qty', '!=', 0)
                ->leftJoin('warehouse_locations', 'warehouse_locations.id', '=', 'stocks.warehouse_location_id')
                ->join('product_variants', 'product_variants.id', '=', 'stocks.product_variant_id')
                // Bin urut kode (jalur hitung), stok tanpa bin di akhir.
                ->orderByRaw('stocks.warehouse_location_id IS NULL')
                ->orderBy('warehouse_locations.code')
                ->orderBy('product_variants.sku')
                ->get(['stocks.product_variant_id', 'stocks.warehouse_location_id', 'stocks.qty']);

            $count->items()->createMany($stocks->map(fn ($stock) => [
                'product_variant_id' => $stock->product_variant_id,
                'warehouse_location_id' => $stock->warehouse_location_id,
                'system_qty' => $stock->qty,
            ])->all());

            return $count;
        });
    }

    /**
     * Simpan hasil hitung. Baris tanpa id adalah barang yang ditemukan tapi belum
     * tercatat di lembar; baris yang tidak dikirim ulang dihapus dari lembar.
     *
     * @param  array<int, array{id?: ?int, product_variant_id: int, warehouse_location_id?: ?int, counted_qty?: ?int, target_location_id?: ?int, notes?: ?string}>  $lines
     *
     * @throws ValidationException
     */
    public function save(StockCount $count, array $lines): void
    {
        DB::transaction(function () use ($count, $lines) {
            $count = $this->lockDraft($count);
            $existing = $count->items()->get()->keyBy('id');
            $seen = [];
            $keep = [];

            foreach (array_values($lines) as $index => $line) {
                $item = filled($line['id'] ?? null) ? $existing->get((int) $line['id']) : null;
                if (filled($line['id'] ?? null) && ! $item) {
                    throw ValidationException::withMessages(["lines.{$index}.id" => 'Baris sudah tidak ada di lembar ini, muat ulang halaman.']);
                }
                $variantId = $item?->product_variant_id ?? (int) $line['product_variant_id'];
                $locationId = $item ? $item->warehouse_location_id : (filled($line['warehouse_location_id'] ?? null) ? (int) $line['warehouse_location_id'] : null);

                $key = $variantId.':'.($locationId ?? 'none');
                if (isset($seen[$key])) {
                    $sku = ProductVariant::whereKey($variantId)->value('sku');
                    $place = $locationId ? 'di bin '.WarehouseLocation::whereKey($locationId)->value('code') : 'tanpa bin';
                    throw ValidationException::withMessages([
                        "lines.{$index}.product_variant_id" => "{$sku} {$place} sudah ada di baris ".($seen[$key] + 1).'.',
                    ]);
                }
                $seen[$key] = $index;

                $attributes = [
                    'counted_qty' => filled($line['counted_qty'] ?? null) ? (int) $line['counted_qty'] : null,
                    // Pindah ke bin hanya berlaku untuk stok tanpa bin.
                    'target_location_id' => $locationId === null && filled($line['target_location_id'] ?? null) ? (int) $line['target_location_id'] : null,
                    'notes' => $line['notes'] ?? null,
                ];

                if ($item) {
                    $item->update($attributes);
                } else {
                    $item = $count->items()->create([
                        ...$attributes,
                        'product_variant_id' => $variantId,
                        'warehouse_location_id' => $locationId,
                        'system_qty' => $this->currentQty($count->warehouse_id, $variantId, $locationId),
                    ]);
                }

                $keep[] = $item->id;
            }

            $count->items()->whereNotIn('id', $keep)->delete();
        });
    }

    /**
     * Posting: stok tiap baris yang sudah dihitung disetel ke hasil hitung lewat
     * satu mutasi Penyesuaian. Baris yang belum dihitung tidak mengubah stok.
     *
     * @throws ValidationException
     */
    public function post(StockCount $count, ?int $userId): void
    {
        DB::transaction(function () use ($count, $userId) {
            $count = $this->lockDraft($count);
            $items = $count->items()->whereNotNull('counted_qty')->get();

            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['count' => 'Belum ada baris yang dihitung.']);
            }

            // Serialkan dengan mutasi/pesanan lain yang memakai varian yang sama.
            ProductVariant::whereIn('id', $items->pluck('product_variant_id')->unique()->sort()->values())
                ->orderBy('id')->lockForUpdate()->get();

            $adjustments = [];

            foreach ($items as $item) {
                $current = $this->currentQty($count->warehouse_id, $item->product_variant_id, $item->warehouse_location_id);
                $item->update(['system_qty' => $current]);
                $note = "Opname {$count->number}";

                if ($item->warehouse_location_id === null && $item->target_location_id) {
                    // Stok tanpa bin yang dihitung langsung ditaruh ke bin tujuan.
                    $target = WarehouseLocation::whereKey($item->target_location_id)->value('code');
                    if ($current !== 0) {
                        $adjustments[] = ['product_variant_id' => $item->product_variant_id, 'to_location_id' => null, 'qty' => -$current, 'notes' => "{$note} · dipindah ke {$target}"];
                    }
                    if ($item->counted_qty !== 0) {
                        $adjustments[] = ['product_variant_id' => $item->product_variant_id, 'to_location_id' => $item->target_location_id, 'qty' => $item->counted_qty, 'notes' => "{$note} · dari tanpa bin"];
                    }

                    continue;
                }

                if ($item->counted_qty !== $current) {
                    $adjustments[] = ['product_variant_id' => $item->product_variant_id, 'to_location_id' => $item->warehouse_location_id, 'qty' => $item->counted_qty - $current, 'notes' => $note];
                }
            }

            $movementId = null;
            if ($adjustments) {
                $movementId = $this->stock->record([
                    'type' => 'adjustment',
                    'warehouse_id' => $count->warehouse_id,
                    'movement_date' => $count->count_date->toDateString(),
                    'reference' => $count->number,
                    'notes' => "Hasil stock opname {$count->number}",
                    'created_by' => $userId,
                ], $adjustments)->id;
            }

            $count->update([
                'status' => 'posted',
                'stock_movement_id' => $movementId,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);
        });
    }

    /**
     * Saldo saat ini per baris lembar (kunci "varian:lokasi"), untuk menandai
     * baris yang saldonya berubah sejak lembar dibuat.
     *
     * @return Collection<string, int>
     */
    public function currentBalances(StockCount $count): Collection
    {
        return Stock::query()
            ->where('warehouse_id', $count->warehouse_id)
            ->whereIn('product_variant_id', $count->items->pluck('product_variant_id')->unique())
            ->get(['product_variant_id', 'warehouse_location_id', 'qty'])
            ->mapWithKeys(fn ($stock) => [$stock->product_variant_id.':'.($stock->warehouse_location_id ?? 'none') => $stock->qty]);
    }

    /** Saldo di gudang yang dicakup lembar ini (seluruh gudang, satu zona, atau tanpa bin). */
    private function scopedStocks(StockCount $count): Builder
    {
        return Stock::query()
            ->where('stocks.warehouse_id', $count->warehouse_id)
            ->when($count->scope === 'unbinned', fn ($query) => $query->whereNull('stocks.warehouse_location_id'))
            ->when($count->scope === 'zone', fn ($query) => $query->whereIn('stocks.warehouse_location_id', WarehouseLocation::query()
                ->select('warehouse_locations.id')
                ->join('warehouse_racks', 'warehouse_racks.id', '=', 'warehouse_locations.warehouse_rack_id')
                ->where('warehouse_racks.warehouse_zone_id', $count->warehouse_zone_id)));
    }

    private function currentQty(int $warehouseId, int $variantId, ?int $locationId): int
    {
        return (int) Stock::query()
            ->where('product_variant_id', $variantId)
            ->when(
                $locationId === null,
                fn ($query) => $query->where('warehouse_id', $warehouseId)->whereNull('warehouse_location_id'),
                fn ($query) => $query->where('warehouse_location_id', $locationId),
            )
            ->value('qty');
    }

    /** @throws ValidationException */
    private function lockDraft(StockCount $count): StockCount
    {
        $locked = StockCount::whereKey($count->id)->lockForUpdate()->firstOrFail();

        if (! $locked->isDraft()) {
            throw ValidationException::withMessages(['count' => "{$locked->number} sudah diposting dan tidak bisa diubah."]);
        }

        return $locked;
    }
}
