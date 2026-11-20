<?php

namespace App\Services\Shopee;

use App\Models\ShopeeShop;
use App\Models\ShopeeSkuMapping;
use App\Support\SkuLookup;

/**
 * Daftar item & variasi (model) toko Shopee untuk pemetaan SKU.
 *
 *   /product/get_item_list       -> item_id per halaman (maks 100)
 *   /product/get_item_base_info  -> nama, item_sku, status, gambar, harga & stok (maks 50 item per panggilan)
 *   /product/get_model_list      -> model_id, model_sku, harga & stok untuk item bervariasi
 *
 * Harga, stok di Shopee, status tayang dan gambar ikut disimpan untuk halaman
 * Produk Channel (lihat MarketplaceChannelProductController).
 */
class ShopeeCatalog
{
    public function __construct(private readonly ShopeeAuth $auth) {}

    /** Status item yang ditarik: tayang & diarsipkan (stok item arsip tetap bisa dikirim). */
    private const ITEM_STATUSES = ['NORMAL', 'UNLIST'];

    /**
     * Tarik semua item toko ke tabel pemetaan (pemetaan yang sudah ada
     * dipertahankan), lalu cocokkan otomatis yang belum terpetakan.
     *
     * Baris yang tidak muncul lagi (variasi/SKU dihapus atau diganti di Shopee)
     * ditandai removed_at: tidak dikirim stoknya dan tidak dihitung gagal, tapi
     * tidak dihapus supaya pesanan & retur lama tetap dikenali. Bila muncul lagi,
     * tanda itu dilepas dan pemetaannya dipakai kembali.
     *
     * @return array{items: int, rows: int, matched: int, removed: int}
     */
    public function pull(ShopeeShop $shop, ?ShopeeClient $client = null): array
    {
        $client ??= ShopeeClient::make($shop->environment);
        $call = fn (string $path, array $query = []) => $client->shopRequest('GET', $path, $shop->shop_id, $this->auth->freshAccessToken($shop, $client), $query);

        $itemIds = [];

        foreach (self::ITEM_STATUSES as $status) {
            $offset = 0;

            do {
                $page = $call('/api/v2/product/get_item_list', ['offset' => $offset, 'page_size' => 100, 'item_status' => $status])['response'] ?? [];
                array_push($itemIds, ...array_column($page['item'] ?? [], 'item_id'));
                $offset = $page['next_offset'] ?? 0;
            } while (! empty($page['has_next_page']));
        }

        $itemIds = array_values(array_unique($itemIds));
        $rows = 0;
        $seen = [];

        foreach (array_chunk($itemIds, 50) as $chunk) {
            $items = $call('/api/v2/product/get_item_base_info', ['item_id_list' => implode(',', $chunk)])['response']['item_list'] ?? [];

            foreach ($items as $item) {
                if (empty($item['has_model'])) {
                    $seen[] = $this->upsert($shop, $item, 0, $item['item_sku'] ?? null, null, $this->listing($item, $item, null));
                    $rows++;

                    continue;
                }

                $models = $call('/api/v2/product/get_model_list', ['item_id' => $item['item_id']])['response'] ?? [];
                $tiers = $models['tier_variation'] ?? [];

                foreach ($models['model'] ?? [] as $model) {
                    $seen[] = $this->upsert($shop, $item, (int) $model['model_id'], $model['model_sku'] ?? null, $this->modelName($model, $tiers), $this->listing($item, $model, $this->modelImage($model, $tiers)));
                    $rows++;
                }
            }
        }

        // Daftar kosong bisa berarti jawaban API yang tidak lengkap: jangan tandai semua hilang.
        $removed = $seen === [] ? 0 : $this->markRemoved($shop, $seen);

        $shop->update(['last_synced_at' => now()]);

        return ['items' => count($itemIds), 'rows' => $rows, 'matched' => $this->autoMatch($shop), 'removed' => $removed];
    }

    /**
     * Tandai baris yang tidak ikut tertarik sebagai sudah tidak ada di Shopee.
     * Error kirim stoknya dihapus karena SKU itu memang tidak dikirim lagi.
     *
     * @param  list<int>  $seen  id baris yang masih ada
     */
    private function markRemoved(ShopeeShop $shop, array $seen): int
    {
        return $shop->mappings()->whereNull('removed_at')->whereNotIn('id', $seen)
            ->update(['removed_at' => now(), 'stock_error' => null, 'updated_at' => now()]);
    }

    /**
     * Cocokkan baris yang belum terpetakan berdasarkan SKU Shopee yang sama
     * persis (tanpa beda huruf besar/kecil) dengan SKU varian atau SKU paket.
     */
    public function autoMatch(ShopeeShop $shop): int
    {
        $matched = 0;
        $lookup = null;

        $shop->mappings()->whereNull('removed_at')->whereNull('product_variant_id')->whereNull('bundle_id')->whereNotNull('shopee_sku')
            ->each(function (ShopeeSkuMapping $mapping) use (&$matched, &$lookup) {
                // Peta SKU dimuat sekali, hanya bila memang ada baris yang perlu dicocokkan.
                $lookup ??= new SkuLookup;
                [$variantId, $bundleId] = $lookup->find($mapping->shopee_sku);

                if ($variantId || $bundleId) {
                    $mapping->update(['product_variant_id' => $variantId, 'bundle_id' => $bundleId, 'match_type' => 'auto']);
                    $matched++;
                }
            });

        return $matched;
    }

    /**
     * Simpan satu baris item/variasi. Bila SKU Shopee-nya berganti dan baris itu
     * dulu dicocokkan otomatis, pemetaannya dilepas supaya dicocokkan ulang ke SKU
     * yang baru (pemetaan manual tetap dipertahankan).
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $listing  harga, stok, status & gambar (lihat listing())
     * @return int id baris
     */
    private function upsert(ShopeeShop $shop, array $item, int $modelId, ?string $sku, ?string $modelName, array $listing = []): int
    {
        $sku = filled($sku) ? trim($sku) : null;
        $mapping = $shop->mappings()->firstOrNew(['item_id' => $item['item_id'], 'model_id' => $modelId]);

        if ($mapping->exists && $mapping->match_type === 'auto' && mb_strtoupper((string) $mapping->shopee_sku) !== mb_strtoupper((string) $sku)) {
            $mapping->fill(['product_variant_id' => null, 'bundle_id' => null, 'match_type' => null, 'stock_error' => null]);
        }

        $mapping->fill(['shopee_sku' => $sku, 'item_name' => $item['item_name'] ?? null, 'model_name' => $modelName, 'removed_at' => null, ...$listing])->save();

        return $mapping->id;
    }

    /**
     * Data listing untuk halaman Produk Channel. Harga & stok diambil dari
     * item (tanpa variasi) atau model (bervariasi); status & gambar dari item.
     * Kunci yang datanya tidak ada di jawaban API tidak ikut, supaya nilai lama
     * tidak tertimpa null.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $priced  item atau model yang membawa price_info & stock_info_v2
     * @return array<string, mixed>
     */
    private function listing(array $item, array $priced, ?string $image): array
    {
        $price = $priced['price_info'][0]['current_price'] ?? $priced['price_info'][0]['original_price'] ?? null;
        $stock = $priced['stock_info_v2']['summary_info']['total_available_stock']
            ?? (isset($priced['stock_info_v2']['seller_stock']) ? array_sum(array_column($priced['stock_info_v2']['seller_stock'], 'stock')) : null);

        return array_filter([
            'channel_price' => is_numeric($price) ? round((float) $price, 2) : null,
            'channel_stock' => is_numeric($stock) ? (int) $stock : null,
            'channel_status' => isset($item['item_status']) ? mb_substr((string) $item['item_status'], 0, 20) : null,
            'image_url' => mb_substr((string) ($image ?? $item['image']['image_url_list'][0] ?? ''), 0, 500) ?: null,
        ], fn ($value) => $value !== null);
    }

    /**
     * Gambar variasi: gambar opsi tier pertama bila Shopee memberinya (cth. per warna).
     *
     * @param  array<string, mixed>  $model
     * @param  list<array<string, mixed>>  $tiers
     */
    private function modelImage(array $model, array $tiers): ?string
    {
        foreach ($model['tier_index'] ?? [] as $tier => $index) {
            if (filled($url = $tiers[$tier]['option_list'][$index]['image']['image_url'] ?? null)) {
                return $url;
            }
        }

        return null;
    }

    /**
     * Nama variasi: model_name bila ada, kalau tidak dirangkai dari tier_index.
     *
     * @param  array<string, mixed>  $model
     * @param  list<array<string, mixed>>  $tiers
     */
    private function modelName(array $model, array $tiers): ?string
    {
        if (filled($model['model_name'] ?? null)) {
            return $model['model_name'];
        }

        $parts = [];

        foreach ($model['tier_index'] ?? [] as $tier => $index) {
            $parts[] = $tiers[$tier]['option_list'][$index]['option'] ?? null;
        }

        return implode(', ', array_filter($parts)) ?: null;
    }
}
