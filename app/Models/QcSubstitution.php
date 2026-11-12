<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu penggantian SKU saat QC kemas: barang pesanan diganti varian lain, dengan alasan. */
class QcSubstitution extends Model
{
    protected $fillable = [
        'sales_order_id',
        'from_variant_id',
        'to_variant_id',
        'qty',
        'reason',
        'user_id',
    ];

    protected function casts(): array
    {
        return ['qty' => 'integer'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function fromVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'from_variant_id');
    }

    public function toVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'to_variant_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
