<?php

namespace App\Models;

use App\Models\Concerns\ScopedToWarehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pengaturan SKU per gudang: lokasi tetap (bin langganan, disarankan saat
 * penerimaan & transfer) serta stok minimum/maksimum untuk saran isi ulang
 * dari gudang cadangan.
 */
class WarehouseVariantSetting extends Model
{
    use ScopedToWarehouse;

    protected $fillable = [
        'warehouse_id',
        'product_variant_id',
        'default_location_id',
        'min_qty',
        'max_qty',
    ];

    protected function casts(): array
    {
        return [
            'min_qty' => 'integer',
            'max_qty' => 'integer',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function defaultLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'default_location_id');
    }
}
