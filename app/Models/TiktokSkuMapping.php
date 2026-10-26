<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu SKU produk TikTok (sku_id) dipetakan ke satu SKU Optima: varian gudang atau paket. */
class TiktokSkuMapping extends Model
{
    protected $fillable = [
        'tiktok_shop_id',
        'product_id',
        'sku_id',
        'seller_sku',
        'product_name',
        'sku_name',
        'product_variant_id',
        'bundle_id',
        'match_type',
        'stock_pushed',
        'stock_pushed_at',
        'stock_error',
        'removed_at',
    ];

    protected function casts(): array
    {
        return [
            'stock_pushed' => 'integer',
            'stock_pushed_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(TiktokShop::class, 'tiktok_shop_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function bundle(): BelongsTo
    {
        return $this->belongsTo(Bundle::class);
    }

    /** Baris yang SKU-nya masih ada di toko (belum ditandai hilang saat tarik produk). */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('removed_at');
    }

    public function isRemoved(): bool
    {
        return $this->removed_at !== null;
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
