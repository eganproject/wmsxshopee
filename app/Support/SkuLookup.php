<?php

namespace App\Support;

use App\Models\Bundle;
use App\Models\ProductVariant;

/**
 * Peta SKU (huruf besar) -> varian / paket untuk pencocokan otomatis SKU marketplace.
 * Dimuat sekali per sinkron produk, bukan satu query UPPER(sku) per baris
 * (yang tidak bisa memakai index dan lambat saat SKU toko ribuan).
 */
class SkuLookup
{
    /** @var array<string, array{0: ?int, 1: ?int}> */
    private array $map = [];

    public function __construct()
    {
        // Varian didahulukan dari paket, dan id terkecil menang bila ada SKU kembar beda huruf.
        foreach (Bundle::query()->orderByDesc('id')->pluck('sku', 'id') as $id => $sku) {
            $this->map[$this->key($sku)] = [null, (int) $id];
        }

        foreach (ProductVariant::query()->orderByDesc('id')->pluck('sku', 'id') as $id => $sku) {
            $this->map[$this->key($sku)] = [(int) $id, null];
        }
    }

    /** @return array{0: ?int, 1: ?int} [product_variant_id, bundle_id] */
    public function find(?string $sku): array
    {
        $key = $this->key($sku);

        return $key === '' ? [null, null] : ($this->map[$key] ?? [null, null]);
    }

    private function key(?string $sku): string
    {
        return mb_strtoupper(trim((string) $sku));
    }
}
