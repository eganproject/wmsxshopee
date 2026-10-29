<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stok yang ditahan untuk pesanan Shopee / TikTok yang belum dibayar
 * (lihat App\Services\UnpaidOrderHolds).
 */
class MarketplaceStockHold extends Model
{
    use MassPrunable;

    protected $fillable = [
        'platform',
        'shop_id',
        'order_ref',
        'order_status',
        'warehouse_id',
        'product_variant_id',
        'qty',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @param  Builder<MarketplaceStockHold>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('expires_at', '>', now());
    }

    /** Cadangan yang sudah kedaluwarsa dihapus oleh model:prune. */
    public function prunable(): Builder
    {
        return static::where('expires_at', '<=', now()->subDay());
    }
}
