<?php

namespace App\Services;

use App\Models\Bundle;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\ShopeeSkuMapping;
use App\Models\TiktokSkuMapping;
use Illuminate\Support\Collection;

/**
 * SKU marketplace di satu pesanan yang belum terhubung ke produk Optima,
 * lengkap dengan saran produk yang mirip, supaya bisa dipetakan langsung
 * dari halaman pesanan.
 */
class OrderSkuResolver
{
    /**
     * @return Collection<int, array{mapping: ShopeeSkuMapping|TiktokSkuMapping, platform: string, sku: ?string, name: ?string, variation: ?string, qty: int, suggestions: Collection<int, array{target: string, sku: string, label: string}>}>
     */
    public function unmapped(SalesOrder $order): Collection
    {
        if ($order->unmapped_skus === null || ! is_array($order->marketplace_lines)) {
            return collect();
        }

        $lines = collect($order->marketplace_lines);

        if ($order->shopee_shop_id) {
            $rows = $lines->groupBy(fn ($line) => ($line['item_id'] ?? '').':'.(int) ($line['model_id'] ?? 0))
                ->map(function (Collection $group) use ($order) {
                    $line = $group->first();
                    $mapping = ShopeeSkuMapping::where('shopee_shop_id', $order->shopee_shop_id)
                        ->where('item_id', $line['item_id'] ?? 0)->where('model_id', (int) ($line['model_id'] ?? 0))->first();

                    return $mapping ? [
                        'mapping' => $mapping,
                        'platform' => 'shopee',
                        'sku' => $mapping->shopee_sku,
                        'name' => $mapping->item_name ?? ($line['item_name'] ?? null),
                        'variation' => $mapping->model_name ?: ($line['model_name'] ?? null),
                        'qty' => (int) $group->sum(fn ($l) => (int) ($l['model_quantity_purchased'] ?? 1)),
                    ] : null;
                });
        } elseif ($order->tiktok_shop_id) {
            $rows = $lines->reject(fn ($line) => ($line['display_status'] ?? null) === 'CANCELLED' || blank($line['sku_id'] ?? null))
                ->groupBy(fn ($line) => (string) $line['sku_id'])
                ->map(function (Collection $group, $skuId) use ($order) {
                    $line = $group->first();
                    $mapping = TiktokSkuMapping::where('tiktok_shop_id', $order->tiktok_shop_id)->where('sku_id', (string) $skuId)->first();

                    return $mapping ? [
                        'mapping' => $mapping,
                        'platform' => 'tiktok',
                        'sku' => $mapping->seller_sku,
                        'name' => $mapping->product_name ?? ($line['product_name'] ?? null),
                        'variation' => $mapping->sku_name ?: ($line['sku_name'] ?? null),
                        'qty' => $group->count(),
                    ] : null;
                });
        } else {
            return collect();
        }

        return $rows->filter()
            ->reject(fn ($row) => $row['mapping']->isMapped())
            ->map(fn ($row) => [...$row, 'suggestions' => $this->suggest($row['sku'], trim(($row['name'] ?? '').' '.($row['variation'] ?? '')))])
            ->values();
    }

    /**
     * Varian / paket Optima yang paling mirip dengan SKU & nama marketplace (maks 3).
     *
     * @return Collection<int, array{target: string, sku: string, label: string}>
     */
    public function suggest(?string $sku, ?string $name): Collection
    {
        $sku = mb_strtoupper(trim((string) $sku));
        $name = mb_strtolower(trim((string) $name));

        // Kata kunci: awalan SKU & kata nama yang cukup panjang.
        $terms = collect([mb_substr(preg_replace('/[^A-Z0-9]/', '', $sku), 0, 3)])
            ->merge(preg_split('/[\s\-_,.\/]+/', $name) ?: [])
            ->filter(fn ($term) => mb_strlen((string) $term) >= 3)
            ->unique()->take(5)->values();

        if ($terms->isEmpty()) {
            return collect();
        }

        $like = fn (string $term) => '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        $variants = ProductVariant::with('product:id,name')
            ->where(function ($q) use ($terms, $like) {
                foreach ($terms as $term) {
                    $q->orWhere('sku', 'like', $like($term))->orWhere('name', 'like', $like($term))
                        ->orWhereHas('product', fn ($p) => $p->where('name', 'like', $like($term)));
                }
            })
            ->limit(40)->get()
            ->map(fn (ProductVariant $v) => ['target' => 'variant:'.$v->id, 'sku' => $v->sku, 'label' => $v->displayName(), 'text' => $v->product?->name.' '.$v->name]);

        $bundles = Bundle::where(function ($q) use ($terms, $like) {
            foreach ($terms as $term) {
                $q->orWhere('sku', 'like', $like($term))->orWhere('name', 'like', $like($term));
            }
        })
            ->limit(10)->get()
            ->map(fn (Bundle $b) => ['target' => 'bundle:'.$b->id, 'sku' => $b->sku, 'label' => 'Paket · '.$b->displayName(), 'text' => $b->name]);

        return $variants->concat($bundles)
            ->map(function ($candidate) use ($sku, $name) {
                similar_text($sku, mb_strtoupper($candidate['sku']), $skuScore);
                similar_text($name, mb_strtolower($candidate['text']), $nameScore);

                return [...$candidate, 'score' => $sku !== '' ? $skuScore * 0.6 + $nameScore * 0.4 : $nameScore];
            })
            ->filter(fn ($candidate) => $candidate['score'] >= 30)
            ->sortByDesc('score')
            ->take(3)
            ->map(fn ($candidate) => ['target' => $candidate['target'], 'sku' => $candidate['sku'], 'label' => $candidate['label']])
            ->values();
    }
}
