<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Produk paket: satu SKU jual berisi beberapa varian gudang. */
class Bundle extends Model
{
    protected $fillable = [
        'sku',
        'name',
        'barcode',
        'price',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(BundleItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function orderLines(): HasMany
    {
        return $this->hasMany(SalesOrderBundle::class);
    }

    /** "SKU · Nama Paket" untuk pilihan & label. */
    public function displayName(): string
    {
        return "{$this->sku} · {$this->name}";
    }
}
