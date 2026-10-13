<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesOrderItem extends Model
{
    protected $fillable = [
        'sales_order_id',
        'sales_order_bundle_id',
        'product_variant_id',
        'qty',
        'bundle_component_qty',
        'price',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'bundle_component_qty' => 'integer',
            'price' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** Baris paket asal, bila barang ini isi dari paket. */
    public function bundleLine(): BelongsTo
    {
        return $this->belongsTo(SalesOrderBundle::class, 'sales_order_bundle_id');
    }

    public function picks(): HasMany
    {
        return $this->hasMany(SalesOrderPick::class)->orderBy('id');
    }
}
