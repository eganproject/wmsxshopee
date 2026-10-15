<?php

namespace App\Services\Tiktok;

use App\Models\Bundle;
use App\Models\TiktokShop;
use App\Models\TiktokSkuMapping;
use App\Services\WarehouseSupply;
use Illuminate\Support\Collection;

/**
 * Kirim stok tersedia ke TikTok lewat
 * POST /product/202309/products/{product_id}/inventory/update.
 *
 * Stok tersedia dihitung sama seperti Shopee: stok fisik gudang pengirim toko
 * dikurangi cadangan pesanan yang belum dikirim; paket memakai stok virtual.
 * Stok dikirim ke satu gudang TikTok (tiktok_warehouse_id, default gudang
 * penjualan utama toko).
 */
class TiktokStockSync
{
    public function __construct(private readonly WarehouseSupply $supply) {}

    /**
     * @return array{products: int, skus: int, failed: list<string>}
     */
    public function push(TiktokShop $shop, ?TiktokShopApi $api = null): array
    {
        $api ??= TiktokShopApi::for($shop);
        $mappings = $shop->mappings()->where(fn ($q) => $q->whereNotNull('product_variant_id')->orWhereNotNull('bundle_id'))->get();
        $failed = [];
        $skus = 0;

        if ($mappings->isEmpty()) {
            return ['products' => 0, 'skus' => 0, 'failed' => []];
        }

        $warehouseId = $this->tiktokWarehouse($shop, $api);
        $stock = $this->levels($shop, $mappings);

        foreach ($mappings->groupBy('product_id') as $productId => $rows) {
            $body = ['skus' => $rows->map(fn (TiktokSkuMapping $row) => [
                'id' => $row->sku_id,
                'inventory' => [array_filter(['warehouse_id' => $warehouseId, 'quantity' => max(0, $stock($row))], fn ($v) => $v !== null)],
            ])->values()->all()];

            try {
                $api->post("/product/products/{$productId}/inventory/update", $body);
            } catch (TiktokException $e) {
                $failed[] = "produk {$productId}: {$e->getMessage()}";
                $rows->each->update(['stock_error' => mb_substr($e->getMessage(), 0, 255)]);

                continue;
            }

            $skus += $rows->count();
            $rows->each(fn (TiktokSkuMapping $row) => $row->update(['stock_pushed' => max(0, $stock($row)), 'stock_pushed_at' => now(), 'stock_error' => null]));
        }

        $shop->update([
            'stock_pushed_at' => now(),
            'last_error' => $failed ? mb_substr('Kirim stok: '.implode('; ', $failed), 0, 500) : $shop->last_error,
        ]);

        return ['products' => $mappings->pluck('product_id')->unique()->count(), 'skus' => $skus, 'failed' => $failed];
    }

    /**
     * Fungsi stok tersedia per baris pemetaan di gudang toko.
     *
     * @param  Collection<int, TiktokSkuMapping>  $mappings
     * @return callable(TiktokSkuMapping): int
     */
    public function levels(TiktokShop $shop, Collection $mappings): callable
    {
        // Stok jual: gudang toko + gudang cadangannya (bila ada), lihat WarehouseSupply.
        $variants = $this->supply->sellable($shop->warehouse_id, $mappings->pluck('product_variant_id')->filter()->unique()->values()->all())
            ->map(fn ($row) => ['available' => $row['sellable']]);
        $bundles = $this->supply->bundleSellable($shop->warehouse_id, Bundle::with('items')->whereIn('id', $mappings->pluck('bundle_id')->filter()->unique())->get());

        return fn (TiktokSkuMapping $row) => $row->product_variant_id
            ? (int) ($variants[$row->product_variant_id]['available'] ?? 0)
            : (int) ($bundles[$row->bundle_id] ?? 0);
    }

    /** Gudang penjualan TikTok toko: yang tersimpan, atau gudang default dari /logistics/warehouses. */
    private function tiktokWarehouse(TiktokShop $shop, TiktokShopApi $api): ?string
    {
        if (filled($shop->tiktok_warehouse_id)) {
            return $shop->tiktok_warehouse_id;
        }

        $warehouses = collect($api->get('/logistics/warehouses')['warehouses'] ?? [])
            ->filter(fn ($w) => ($w['type'] ?? 'SALES_WAREHOUSE') === 'SALES_WAREHOUSE');
        $id = ($warehouses->firstWhere('is_default', true) ?? $warehouses->first())['id'] ?? null;

        if ($id) {
            $shop->update(['tiktok_warehouse_id' => (string) $id]);
        }

        return $id ? (string) $id : null;
    }
}
