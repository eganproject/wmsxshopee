<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImage extends Model
{
    protected $fillable = [
        'product_id',
        'path',
        'is_primary',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * URL gambar lewat symlink public/storage (php artisan storage:link).
     * Memakai host request saat ini, jadi tetap tampil walau APP_URL berbeda.
     */
    public function url(): string
    {
        return asset('storage/'.$this->path);
    }
}
