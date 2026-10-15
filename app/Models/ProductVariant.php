<?php

namespace App\Models;

use App\Support\Cartons;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'min_stock',
        'pcs_per_carton',
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
            'min_stock' => 'integer',
            'pcs_per_carton' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }

    public function warehouseSettings(): HasMany
    {
        return $this->hasMany(WarehouseVariantSetting::class);
    }

    public function bundleItems(): HasMany
    {
        return $this->hasMany(BundleItem::class);
    }

    public function ledgers(): HasMany
    {
        return $this->hasMany(StockLedger::class);
    }

    /** "SKU · Nama Produk / Varian" untuk pilihan & label. */
    public function displayName(): string
    {
        $name = $this->product?->name.($this->name ? ' / '.$this->name : '');

        return "{$this->sku} · {$name}";
    }

    /** "12 koli + 5 pcs" bila isi per koli diisi, selain itu null. */
    public function cartonLabel(int $qty): ?string
    {
        return Cartons::label($qty, $this->pcs_per_carton);
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

    /** Baris pemetaan SKU Shopee (bisa di beberapa toko) yang menunjuk ke SKU ini. */
    public function shopeeMappings(): HasMany
    {
        return $this->hasMany(ShopeeSkuMapping::class);
    }

    /** Baris pemetaan SKU TikTok (bisa di beberapa toko) yang menunjuk ke SKU ini. */
    public function tiktokMappings(): HasMany
    {
        return $this->hasMany(TiktokSkuMapping::class);
    }
}
