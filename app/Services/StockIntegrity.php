<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Memeriksa saldo stok terhadap kartu stok: tiap saldo harus sama dengan jumlah
 * masuk dikurangi keluar di kartu stok, dan tidak ada saldo minus.
 */
class StockIntegrity
{
    /**
     * @return Collection<int, array{sku: string, warehouse: string, location: ?string, stock: int, ledger: int, problem: string}>
     */
    public function problems(): Collection
    {
        $key = fn ($row) => $row->product_variant_id.':'.$row->warehouse_id.':'.($row->warehouse_location_id ?? 'none');

        $stocks = DB::table('stocks')
            ->get(['product_variant_id', 'warehouse_id', 'warehouse_location_id', 'qty'])
            ->keyBy($key);

        $ledgers = DB::table('stock_ledgers')
            ->groupBy('product_variant_id', 'warehouse_id', 'warehouse_location_id')
            ->selectRaw('product_variant_id, warehouse_id, warehouse_location_id, SUM(qty_in) - SUM(qty_out) as qty')
            ->get()
            ->keyBy($key);

        $problems = collect();

        foreach ($stocks->keys()->merge($ledgers->keys())->unique() as $k) {
            $row = $stocks[$k] ?? $ledgers[$k];
            $stock = (int) ($stocks[$k]->qty ?? 0);
            $ledger = (int) ($ledgers[$k]->qty ?? 0);

            if ($stock < 0) {
                $problems->push([$row, $stock, $ledger, 'Saldo minus']);
            } elseif ($stock !== $ledger) {
                $problems->push([$row, $stock, $ledger, 'Saldo beda dengan kartu stok']);
            }
        }

        if ($problems->isEmpty()) {
            return collect();
        }

        $skus = DB::table('product_variants')->whereIn('id', $problems->pluck('0.product_variant_id'))->pluck('sku', 'id');
        $warehouses = DB::table('warehouses')->whereIn('id', $problems->pluck('0.warehouse_id'))->pluck('code', 'id');
        $locations = DB::table('warehouse_locations')->whereIn('id', $problems->pluck('0.warehouse_location_id')->filter())->pluck('code', 'id');

        return $problems->map(fn ($problem) => [
            'sku' => $skus[$problem[0]->product_variant_id] ?? '#'.$problem[0]->product_variant_id,
            'warehouse' => $warehouses[$problem[0]->warehouse_id] ?? '#'.$problem[0]->warehouse_id,
            'location' => $problem[0]->warehouse_location_id ? ($locations[$problem[0]->warehouse_location_id] ?? '#'.$problem[0]->warehouse_location_id) : null,
            'stock' => $problem[1],
            'ledger' => $problem[2],
            'problem' => $problem[3],
        ])->values();
    }
}
