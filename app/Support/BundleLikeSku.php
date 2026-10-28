<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * SKU marketplace yang namanya seperti paket (awalan di config
 * marketplace.bundle_sku_prefixes), tapi dipetakan ke produk biasa.
 */
class BundleLikeSku
{
    /** @return list<string> */
    public static function prefixes(): array
    {
        return array_values(array_filter(array_map(fn ($p) => mb_strtoupper(trim((string) $p)), (array) config('marketplace.bundle_sku_prefixes', []))));
    }

    public static function matches(?string $sku): bool
    {
        $sku = mb_strtoupper(trim((string) $sku));

        return $sku !== '' && collect(self::prefixes())->contains(fn ($prefix) => str_starts_with($sku, $prefix));
    }

    /** Batasi query pemetaan ke baris SKU mirip paket yang dipetakan ke varian. */
    public static function apply(Builder $query, string $column): Builder
    {
        $prefixes = self::prefixes();

        return $query->whereNotNull('product_variant_id')->where(function (Builder $q) use ($prefixes, $column) {
            $q->whereRaw('1 = 0');

            foreach ($prefixes as $prefix) {
                $q->orWhereRaw("UPPER({$column}) LIKE ?", [$prefix.'%']);
            }
        });
    }
}
