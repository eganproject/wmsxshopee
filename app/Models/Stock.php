<?php

namespace App\Models;

use App\Models\Concerns\ScopedToWarehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/** Saldo stok saat ini satu varian di satu lokasi bin. Diubah hanya lewat StockService. */
class Stock extends Model
{
    use ScopedToWarehouse;

    /**
     * Pengaman terakhir: saldo tidak pernah boleh minus, walau ada kode yang
     * lupa lewat pengecekan StockService. Di MySQL juga dijaga CHECK constraint.
     */
    protected static function booted(): void
    {
        static::saving(function (Stock $stock) {
            if ($stock->qty < 0) {
                throw ValidationException::withMessages([
                    'stock' => 'Stok tidak boleh minus. Muat ulang halaman lalu periksa saldo terbaru.',
                ]);
            }
        });
    }

    protected $fillable = [
        'product_variant_id',
        'warehouse_id',
        'warehouse_location_id',
        'qty',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
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
}
