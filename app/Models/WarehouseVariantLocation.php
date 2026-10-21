<?php

namespace App\Models;

use App\Models\Concerns\ScopedToWarehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu bin lokasi tetap SKU di satu gudang. Priority 1 = bin utama, berikutnya
 * bin cadangan. Kapasitas (pcs) opsional untuk membagi saran isi ke bin berikutnya.
 */
class WarehouseVariantLocation extends Model
{
    use ScopedToWarehouse;

    protected $fillable = [
        'warehouse_id',
        'product_variant_id',
        'warehouse_location_id',
        'priority',
        'capacity',
    ];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'capacity' => 'integer',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'warehouse_location_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
