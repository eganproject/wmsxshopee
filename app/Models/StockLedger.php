<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu baris kartu stok: perubahan saldo satu varian di satu lokasi. */
class StockLedger extends Model
{
    protected $fillable = [
        'product_variant_id',
        'warehouse_id',
        'warehouse_location_id',
        'stock_movement_id',
        'stock_movement_item_id',
        'type',
        'movement_date',
        'qty_in',
        'qty_out',
    ];

    protected function casts(): array
    {
        return [
            'movement_date' => 'date',
            'qty_in' => 'integer',
            'qty_out' => 'integer',
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

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'warehouse_location_id');
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }
}
