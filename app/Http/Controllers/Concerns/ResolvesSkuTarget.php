<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Bundle;
use App\Models\ProductVariant;
use App\Services\MarketplaceOrderRelink;
use Illuminate\Validation\ValidationException;

/** Pilihan dropdown pemetaan SKU marketplace ("variant:ID" / "bundle:ID") -> kolom target. */
trait ResolvesSkuTarget
{
    /**
     * Kosong = lepas pemetaan.
     *
     * @return array{product_variant_id: ?int, bundle_id: ?int, match_type: ?string}
     */
    protected function target(?string $value): array
    {
        if (blank($value)) {
            return ['product_variant_id' => null, 'bundle_id' => null, 'match_type' => null];
        }

        [$type, $id] = array_pad(explode(':', $value, 2), 2, null);

        $exists = match ($type) {
            'variant' => ProductVariant::whereKey($id)->exists(),
            'bundle' => Bundle::whereKey($id)->exists(),
            default => false,
        };

        if (! $exists) {
            throw ValidationException::withMessages(['target' => 'SKU Optima yang dipilih tidak ditemukan.']);
        }

        return [
            'product_variant_id' => $type === 'variant' ? (int) $id : null,
            'bundle_id' => $type === 'bundle' ? (int) $id : null,
            'match_type' => 'manual',
        ];
    }

    /**
     * Hubungkan ulang pesanan yang tertahan karena SKU belum dipetakan, lalu
     * kembalikan kalimat ringkas untuk pesan sukses ("" bila tidak ada).
     */
    protected function relinkOrders(): string
    {
        $linked = app(MarketplaceOrderRelink::class)->relinkAll();

        return $linked ? " {$linked} pesanan yang tertahan kini terhubung dan diproses." : '';
    }
}
