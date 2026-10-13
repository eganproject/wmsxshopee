<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockCountItem extends Model
{
    protected $fillable = [
        'stock_count_id',
        'product_variant_id',
        'warehouse_location_id',
        'target_location_id',
        'system_qty',
        'counted_qty',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'system_qty' => 'integer',
            'counted_qty' => 'integer',
        ];
    }

    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class, 'stock_count_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'warehouse_location_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'target_location_id');
    }

    /** Selisih hitung terhadap saldo sistem; null bila belum dihitung. */
    public function variance(): ?int
    {
        return $this->counted_qty === null ? null : $this->counted_qty - $this->system_qty;
    }
}
