<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesReturnItem extends Model
{
    protected $fillable = [
        'sales_return_id',
        'sales_order_item_id',
        'product_variant_id',
        'qty_good',
        'qty_damaged',
        'warehouse_location_id',
        'reason',
        'photo_path',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'qty_good' => 'integer',
            'qty_damaged' => 'integer',
        ];
    }

    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(SalesOrderItem::class, 'sales_order_item_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'warehouse_location_id');
    }

    public function reasonLabel(): string
    {
        return SalesReturn::REASONS[$this->reason] ?? $this->reason;
    }

    public function qty(): int
    {
        return $this->qty_good + $this->qty_damaged;
    }
}
