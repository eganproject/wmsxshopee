<?php

namespace App\Models;

use App\Models\Concerns\ScopedToWarehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/**
 * Saldo barang rusak satu varian di satu gudang, terpisah dari stok layak jual.
 * Diubah hanya lewat DamagedStockService.
 */
class DamagedStock extends Model
{
    use ScopedToWarehouse;

    protected static function booted(): void
    {
        static::saving(function (DamagedStock $stock) {
            if ($stock->qty < 0) {
                throw ValidationException::withMessages([
                    'stock' => 'Stok rusak tidak boleh minus. Muat ulang halaman lalu periksa saldo terbaru.',
                ]);
            }
        });
    }

    protected $fillable = [
        'product_variant_id',
        'warehouse_id',
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
}
