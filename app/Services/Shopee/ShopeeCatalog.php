<?php

namespace App\Services\Shopee;

use App\Models\Bundle;
use App\Models\ProductVariant;
use App\Models\ShopeeShop;
use App\Models\ShopeeSkuMapping;

/**
 * Daftar item & variasi (model) toko Shopee untuk pemetaan SKU.
 *
 *   /product/get_item_list       -> item_id per halaman (maks 100)
 *   /product/get_item_base_info  -> nama & item_sku (maks 50 item per panggilan)
 *   /product/get_model_list      -> model_id & model_sku untuk item bervariasi
 */
class ShopeeCatalog
{
    public function __construct(private readonly ShopeeAuth $auth) {}

    /**
     * Tarik semua item aktif toko ke tabel pemetaan (pemetaan yang sudah ada
     * dipertahankan), lalu cocokkan otomatis yang belum terpetakan.
     *
     * @return array{items: int, rows: int, matched: int}
     */
    public function pull(ShopeeShop $shop, ?ShopeeClient $client = null): array
    {
        $client ??= ShopeeClient::make($shop->environment);
        $call = fn (string $path, array $query = []) => $client->shopRequest('GET', $path, $shop->shop_id, $this->auth->freshAccessToken($shop, $client), $query);

        $itemIds = [];
        $offset = 0;

        do {
            $page = $call('/api/v2/product/get_item_list', ['offset' => $offset, 'page_size' => 100, 'item_status' => 'NORMAL'])['response'] ?? [];
            array_push($itemIds, ...array_column($page['item'] ?? [], 'item_id'));
            $offset = $page['next_offset'] ?? 0;
        } while (! empty($page['has_next_page']));

        $rows = 0;

        foreach (array_chunk($itemIds, 50) as $chunk) {
            $items = $call('/api/v2/product/get_item_base_info', ['item_id_list' => implode(',', $chunk)])['response']['item_list'] ?? [];

            foreach ($items as $item) {
                if (empty($item['has_model'])) {
                    $this->upsert($shop, $item, 0, $item['item_sku'] ?? null, null);
                    $rows++;

                    continue;
                }

                $models = $call('/api/v2/product/get_model_list', ['item_id' => $item['item_id']])['response'] ?? [];

                foreach ($models['model'] ?? [] as $model) {
                    $this->upsert($shop, $item, (int) $model['model_id'], $model['model_sku'] ?? null, $this->modelName($model, $models['tier_variation'] ?? []));
                    $rows++;
                }
            }
        }

        $shop->update(['last_synced_at' => now()]);

        return ['items' => count($itemIds), 'rows' => $rows, 'matched' => $this->autoMatch($shop)];
    }

    /**
     * Cocokkan baris yang belum terpetakan berdasarkan SKU Shopee yang sama
     * persis (tanpa beda huruf besar/kecil) dengan SKU varian atau SKU paket.
     */
    public function autoMatch(ShopeeShop $shop): int
    {
        $matched = 0;

        $shop->mappings()->whereNull('product_variant_id')->whereNull('bundle_id')->whereNotNull('shopee_sku')
            ->each(function (ShopeeSkuMapping $mapping) use (&$matched) {
                $sku = mb_strtoupper(trim($mapping->shopee_sku));

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
     * @param  array<string, mixed>  $item
     */
    private function upsert(ShopeeShop $shop, array $item, int $modelId, ?string $sku, ?string $modelName): void
    {
        $shop->mappings()->updateOrCreate(
            ['item_id' => $item['item_id'], 'model_id' => $modelId],
            ['shopee_sku' => filled($sku) ? trim($sku) : null, 'item_name' => $item['item_name'] ?? null, 'model_name' => $modelName],
        );
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
