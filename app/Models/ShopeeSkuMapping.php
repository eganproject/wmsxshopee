<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu item/model (variasi) di toko Shopee dipetakan ke satu SKU Optima:
 * varian gudang atau produk paket. model_id 0 berarti item tanpa variasi.
 */
class ShopeeSkuMapping extends Model
{
    protected $fillable = [
        'shopee_shop_id',
        'item_id',
        'model_id',
        'shopee_sku',
        'item_name',
        'model_name',
        'product_variant_id',
        'bundle_id',
        'match_type',
    ];

    protected function casts(): array
    {
        return [
            'item_id' => 'integer',
            'model_id' => 'integer',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(ShopeeShop::class, 'shopee_shop_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function bundle(): BelongsTo
    {
        return $this->belongsTo(Bundle::class);
    }

    public function isMapped(): bool
    {
        return $this->product_variant_id !== null || $this->bundle_id !== null;
    }

    /** Nilai pilihan "variant:ID" / "bundle:ID" untuk dropdown pemetaan. */
    public function targetKey(): ?string
    {
        return match (true) {
            $this->product_variant_id !== null => 'variant:'.$this->product_variant_id,
            $this->bundle_id !== null => 'bundle:'.$this->bundle_id,
            default => null,
        };
    }
}
