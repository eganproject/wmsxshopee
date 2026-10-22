<?php

namespace App\Services\Shopee;

use App\Models\Bundle;
use App\Models\ShopeeShop;
use App\Models\ShopeeSkuMapping;
use App\Services\StockPushFilter;
use App\Services\WarehouseSupply;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Kirim stok tersedia ke Shopee lewat /product/update_stock (seller_stock).
 *
 * Stok tersedia = stok fisik di gudang pengirim toko dikurangi cadangan pesanan
 * yang belum dikirim, ditambah stok gudang cadangannya bila ada (WarehouseSupply). SKU paket memakai stok paket virtual (komponen paling
 * sedikit yang menentukan). Hanya baris yang sudah dipetakan yang dikirim.
 */
class ShopeeStockSync
{
    /** Batas stock_list per panggilan /product/update_stock. */
    public const MAX_MODELS = 50;

    public function __construct(private readonly WarehouseSupply $supply) {}

    /**
     * $onlyChanged: kirim hanya baris yang stoknya beda dari yang terakhir terkirim
     * (dipakai kirim stok otomatis tiap ada perubahan); tanpa itu semua baris dikirim ulang.
     *
     * @return array{items: int, models: int, failed: list<string>}
     */
    public function push(ShopeeShop $shop, ?ShopeeShopApi $api = null, bool $onlyChanged = false): array
    {
        // Satu kiriman stok per toko pada satu waktu, supaya angka lama tidak menimpa yang baru.
        return Cache::lock('stock-push:shopee:'.$shop->id, 300)
            ->block(120, fn () => $this->pushLocked($shop, $api, $onlyChanged));
    }

    /**
     * @return array{items: int, models: int, failed: list<string>}
     */
    private function pushLocked(ShopeeShop $shop, ?ShopeeShopApi $api, bool $onlyChanged): array
    {
        $mappings = $shop->mappings()->where(fn ($q) => $q->whereNotNull('product_variant_id')->orWhereNotNull('bundle_id'))->get();
        $stock = $this->levels($shop, $mappings);

        if ($onlyChanged) {
            $mappings = $mappings->filter(fn (ShopeeSkuMapping $row) => StockPushFilter::changed($row, max(0, $stock($row))))->values();

            if ($mappings->isEmpty()) {
                return ['items' => 0, 'models' => 0, 'failed' => []];
            }
        }

        $api ??= ShopeeShopApi::for($shop);
        $failed = [];
        $models = 0;

        // Satu panggilan update_stock untuk satu item, maksimal 50 model per panggilan.
        foreach ($mappings->groupBy('item_id')->flatMap(fn ($rows, $itemId) => $rows->chunk(self::MAX_MODELS)->map(fn ($chunk) => [$itemId, $chunk->values()])) as [$itemId, $rows]) {
            $stockList = $rows->map(fn (ShopeeSkuMapping $row) => [
                'model_id' => $row->model_id,
                'seller_stock' => [['stock' => max(0, $stock($row))]],
            ])->values()->all();

            try {
                $response = $api->post('/api/v2/product/update_stock', ['item_id' => (int) $itemId, 'stock_list' => $stockList])['response'] ?? [];
            } catch (ShopeeException $e) {
                $failed[] = "item {$itemId}: {$e->getMessage()}";
                $this->record($rows, $stock, fn () => $e->getMessage());

                continue;
            }

            $models += count($response['success_list'] ?? $stockList);
            $failures = collect($response['failure_list'] ?? [])->keyBy(fn ($failure) => (int) ($failure['model_id'] ?? 0));

            foreach ($failures as $modelId => $failure) {
                $failed[] = "item {$itemId}/{$modelId}: ".($failure['failed_reason'] ?? 'gagal');
            }

            $this->record($rows, $stock, fn (ShopeeSkuMapping $row) => isset($failures[$row->model_id]) ? ($failures[$row->model_id]['failed_reason'] ?? 'Ditolak Shopee') : null);
        }

        $shop->update([
            'stock_pushed_at' => now(),
            'last_error' => $failed ? mb_substr('Kirim stok: '.implode('; ', $failed), 0, 500) : $shop->last_error,
        ]);

        return ['items' => $mappings->pluck('item_id')->unique()->count(), 'models' => $models, 'failed' => $failed];
    }

    /**
     * Simpan hasil kirim per baris: stok yang dikirim & waktunya bila berhasil,
     * atau alasan gagal (stok terakhir yang berhasil tetap disimpan).
     *
     * @param  Collection<int, ShopeeSkuMapping>  $rows
     * @param  callable(ShopeeSkuMapping): ?string  $error
     */
    private function record(Collection $rows, callable $stock, callable $error): void
    {
        foreach ($rows as $row) {
            $message = $error($row);

            $row->update($message === null
                ? ['stock_pushed' => max(0, $stock($row)), 'stock_pushed_at' => now(), 'stock_error' => null]
                : ['stock_error' => mb_substr($message, 0, 255)]);
        }
    }

    /**
     * Fungsi stok tersedia per baris pemetaan di gudang toko.
     *
     * @param  Collection<int, ShopeeSkuMapping>  $mappings
     * @return callable(ShopeeSkuMapping): int
     */
    public function levels(ShopeeShop $shop, $mappings): callable
    {
        // Stok jual: gudang toko + gudang cadangannya (bila ada), lihat WarehouseSupply.
        $variants = $this->supply->sellable($shop->warehouse_id, $mappings->pluck('product_variant_id')->filter()->unique()->values()->all())
            ->map(fn ($row) => ['available' => $row['sellable']]);
        $bundles = $this->supply->bundleSellable($shop->warehouse_id, Bundle::with('items')->whereIn('id', $mappings->pluck('bundle_id')->filter()->unique())->get());

        return fn (ShopeeSkuMapping $row) => $row->product_variant_id
            ? (int) ($variants[$row->product_variant_id]['available'] ?? 0)
            : (int) ($bundles[$row->bundle_id] ?? 0);
    }
}
