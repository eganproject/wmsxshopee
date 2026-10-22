<?php

namespace App\Services\Tiktok;

use App\Models\Bundle;
use App\Models\ProductVariant;
use App\Models\TiktokShop;
use App\Models\TiktokSkuMapping;

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
     * @return array{products: int, rows: int, matched: int, inactive: int}
     */
    public function pull(TiktokShop $shop, ?TiktokShopApi $api = null): array
    {
        $api ??= TiktokShopApi::for($shop);
        $products = 0;
        $rows = 0;
        $inactive = 0;
        $pageToken = null;

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
                    $this->upsert($shop, $product, $sku);
                    $rows++;

                    // Gudang TikTok tempat stok dikirim diambil dari SKU bila belum diatur.
                    if (blank($shop->tiktok_warehouse_id) && filled($warehouse = $sku['inventory'][0]['warehouse_id'] ?? null)) {
                        $shop->update(['tiktok_warehouse_id' => (string) $warehouse]);
                    }
                }
            }

            $pageToken = $page['next_page_token'] ?? null;
        } while (filled($pageToken));

        $shop->update(['last_synced_at' => now()]);

        return ['products' => $products, 'rows' => $rows, 'matched' => $this->autoMatch($shop), 'inactive' => $inactive];
    }

    /** Cocokkan baris yang belum terpetakan dengan SKU varian / paket yang sama persis. */
    public function autoMatch(TiktokShop $shop): int
    {
        $matched = 0;

        $shop->mappings()->whereNull('product_variant_id')->whereNull('bundle_id')->whereNotNull('seller_sku')
            ->each(function (TiktokSkuMapping $mapping) use (&$matched) {
                $sku = mb_strtoupper(trim($mapping->seller_sku));

                if ($sku === '') {
                    return;
                }

                $variantId = ProductVariant::whereRaw('UPPER(sku) = ?', [$sku])->value('id');
                $bundleId = $variantId ? null : Bundle::whereRaw('UPPER(sku) = ?', [$sku])->value('id');

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
     * @param  array<string, mixed>  $product
     * @param  array<string, mixed>  $sku
     */
    private function upsert(TiktokShop $shop, array $product, array $sku): void
    {
        $shop->mappings()->updateOrCreate(
            ['sku_id' => (string) $sku['id']],
            [
                'product_id' => (string) $product['id'],
                'seller_sku' => filled($sku['seller_sku'] ?? null) ? trim($sku['seller_sku']) : null,
                'product_name' => isset($product['title']) ? mb_substr($product['title'], 0, 255) : null,
                'sku_name' => self::skuName($sku),
            ],
        );
    }
}
