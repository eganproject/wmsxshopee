<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use App\Support\Barcodes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Barcode tambahan (alias) untuk satu varian, cth. barcode dari supplier yang
 * berbeda dengan barcode utama. Scan alias dikenali sama seperti SKU / barcode utama.
 */
class ProductBarcode extends Model
{
    use LogsActivity;

    protected static function booted(): void
    {
        static::saved(fn () => Barcodes::flush());
        static::deleted(fn () => Barcodes::flush());
    }

    protected $fillable = [
        'product_variant_id',
        'barcode',
        'notes',
        'created_by',
    ];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function activityModule(): string
    {
        return 'products';
    }

    public function activityName(): string
    {
        return 'Barcode alias';
    }

    public function activityLabel(): string
    {
        return (string) $this->barcode;
    }
}
