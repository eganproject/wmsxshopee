<?php

namespace App\Services\Tiktok;

use App\Models\TiktokShop;
use App\Models\TiktokSkuMapping;
use App\Support\SkuLookup;

/**
 * Daftar produk & SKU toko TikTok untuk pemetaan SKU.
 *
 *   POST /product/202309/products/search  -> produk beserta skus[] (maks 100 per halaman)
 *
 * Semua status ditarik (bukan hanya ACTIVATE): produk baru di toko, terutama
 * toko uji, sering masih PENDING (direview) atau DRAFT, dan pesanan / stok
 * tetap perlu dipetakan. Hanya produk yang sudah dihapus yang dilewati.
 *
 * Satu baris pemetaan = satu sku_id. Produk tanpa variasi tetap punya satu SKU.
 */
class TiktokCatalog
{
    /** Status produk yang tidak ikut ditarik. */
    private const SKIPPED_STATUSES = ['DELETED'];

    /**
     * Baris yang tidak muncul lagi (SKU dihapus di TikTok) ditandai removed_at,
     * sama seperti ShopeeCatalog::pull().
     *
     * @return array{products: int, rows: int, matched: int, inactive: int, removed: int}
     */
    public function pull(TiktokShop $shop, ?TiktokShopApi $api = null): array
    {
        $api ??= TiktokShopApi::for($shop);
        $products = 0;
        $rows = 0;
        $inactive = 0;
        $pageToken = null;
        $seen = [];

        do {
            $page = $api->post('/product/products/search', ['status' => 'ALL'], array_filter(['page_size' => 100, 'page_token' => $pageToken]));

            foreach ($page['products'] ?? [] as $product) {
                $status = (string) ($product['status'] ?? '');

                if (in_array($status, self::SKIPPED_STATUSES, true)) {
                    continue;
                }

                $products++;
                $inactive += $status !== '' && $status !== 'ACTIVATE' ? 1 : 0;

                foreach ($product['skus'] ?? [] as $sku) {
                    $seen[] = $this->upsert($shop, $product, $sku);
                    $rows++;

                    // Gudang TikTok tempat stok dikirim diambil dari SKU bila belum diatur.
                    if (blank($shop->tiktok_warehouse_id) && filled($warehouse = $sku['inventory'][0]['warehouse_id'] ?? null)) {
                        $shop->update(['tiktok_warehouse_id' => (string) $warehouse]);
                    }
                }
            }

            $pageToken = $page['next_page_token'] ?? null;
        } while (filled($pageToken));

        // Daftar kosong bisa berarti jawaban API yang tidak lengkap: jangan tandai semua hilang.
        $removed = $seen === [] ? 0 : $shop->mappings()->whereNull('removed_at')->whereNotIn('id', $seen)
            ->update(['removed_at' => now(), 'stock_error' => null, 'updated_at' => now()]);

        $shop->update(['last_synced_at' => now()]);

        return ['products' => $products, 'rows' => $rows, 'matched' => $this->autoMatch($shop), 'inactive' => $inactive, 'removed' => $removed];
    }

    /** Cocokkan baris yang belum terpetakan dengan SKU varian / paket yang sama persis. */
    public function autoMatch(TiktokShop $shop): int
    {
        $matched = 0;
        $lookup = null;

        $shop->mappings()->whereNull('removed_at')->whereNull('product_variant_id')->whereNull('bundle_id')->whereNotNull('seller_sku')
            ->each(function (TiktokSkuMapping $mapping) use (&$matched, &$lookup) {
                // Peta SKU dimuat sekali, hanya bila memang ada baris yang perlu dicocokkan.
                $lookup ??= new SkuLookup;
                [$variantId, $bundleId] = $lookup->find($mapping->seller_sku);

                if ($variantId || $bundleId) {
                    $mapping->update(['product_variant_id' => $variantId, 'bundle_id' => $bundleId, 'match_type' => 'auto']);
                    $matched++;
                }
            });

        return $matched;
    }

    /**
     * Nama variasi dari sales_attributes, cth. "Hitam, M".
     *
     * @param  array<string, mixed>  $sku
     */
    public static function skuName(array $sku): ?string
    {
        if (filled($sku['sku_name'] ?? null)) {
            return $sku['sku_name'];
        }

        $values = array_filter(array_map(fn ($attribute) => $attribute['value_name'] ?? null, $sku['sales_attributes'] ?? []));

        return $values ? implode(', ', $values) : null;
    }

    /**
     * Simpan satu SKU. Bila Seller SKU-nya berganti dan baris itu dulu dicocokkan
     * otomatis, pemetaannya dilepas supaya dicocokkan ulang (manual dipertahankan).
     *
     * @param  array<string, mixed>  $product
     * @param  array<string, mixed>  $sku
     * @return int id baris
     */
    private function upsert(TiktokShop $shop, array $product, array $sku): int
    {
        $sellerSku = filled($sku['seller_sku'] ?? null) ? trim($sku['seller_sku']) : null;
        $mapping = $shop->mappings()->firstOrNew(['sku_id' => (string) $sku['id']]);

        if ($mapping->exists && $mapping->match_type === 'auto' && mb_strtoupper((string) $mapping->seller_sku) !== mb_strtoupper((string) $sellerSku)) {
            $mapping->fill(['product_variant_id' => null, 'bundle_id' => null, 'match_type' => null, 'stock_error' => null]);
        }

        $mapping->fill([
            'product_id' => (string) $product['id'],
            'seller_sku' => $sellerSku,
            'product_name' => isset($product['title']) ? mb_substr($product['title'], 0, 255) : null,
            'sku_name' => self::skuName($sku),
            'removed_at' => null,
        ])->save();

        return $mapping->id;
    }
}
