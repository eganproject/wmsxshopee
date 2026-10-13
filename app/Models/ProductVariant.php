<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariant extends Model
{
    protected $fillable = [
        'product_id',
        'sku',
        'name',
        'barcode',
        'weight_gram',
        'length_cm',
        'width_cm',
        'height_cm',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'weight_gram' => 'integer',
            'length_cm' => 'decimal:2',
            'width_cm' => 'decimal:2',
            'height_cm' => 'decimal:2',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Dimensi "P × L × T cm", atau null bila belum lengkap. */
    public function dimensionsLabel(): ?string
    {
        if ($this->length_cm === null || $this->width_cm === null || $this->height_cm === null) {
            return null;
        }

        $format = fn ($value) => rtrim(rtrim((string) $value, '0'), '.');

        return "{$format($this->length_cm)} × {$format($this->width_cm)} × {$format($this->height_cm)} cm";
    }
}
