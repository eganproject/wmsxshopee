<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoodsReceiptItem extends Model
{
    protected $fillable = [
        'goods_receipt_id',
        'product_variant_id',
        'warehouse_location_id',
        'qty_ordered',
        'qty_received',
        'qty_damaged',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'qty_ordered' => 'integer',
            'qty_received' => 'integer',
            'qty_damaged' => 'integer',
        ];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'warehouse_location_id');
    }

    /** Selisih terhadap jumlah dipesan: negatif = kurang kirim, positif = lebih. */
    public function variance(): ?int
    {
        return $this->qty_ordered === null ? null : $this->qty_received + $this->qty_damaged - $this->qty_ordered;
    }
}
