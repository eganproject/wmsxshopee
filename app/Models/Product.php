<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Product extends Model
{
    use LogsActivity;

    protected $fillable = [
        'sku',
        'name',
        'category',
        'brand',
        'unit_id',
        'has_variants',
        'description',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'has_variants' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Filter daftar produk (cari, kategori, status), dipakai halaman Master Produk,
     * ekspor dan template update massal supaya hasilnya selalu sama.
     *
     * @param  array{search?: ?string, category?: ?string, status?: ?string}  $filters
     */
    public function scopeFilter(Builder $query, array $filters): void
    {
        $search = trim((string) ($filters['search'] ?? ''));

        $query
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('sku', 'like', "%{$search}%")
                ->orWhere('brand', 'like', "%{$search}%")
                // Cari juga SKU & barcode varian, supaya bisa langsung scan barcode.
                ->orWhereHas('variants', fn ($v) => $v->where(fn ($w) => $w
                    ->where('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', $search)
                    ->orWhereHas('aliases', fn ($a) => $a->where('barcode', $search)))))
            )
            ->when(filled($filters['category'] ?? null), fn ($query) => $query->where('category', $filters['category']))
            ->when(filled($filters['status'] ?? null), fn ($query) => $query->where('is_active', $filters['status'] === 'active'));
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('sort_order')->orderBy('id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderByDesc('is_primary')->orderBy('sort_order')->orderBy('id');
    }

    public function primaryImage(): HasOne
    {
        return $this->hasOne(ProductImage::class)->where('is_primary', true);
    }

    public function activityModule(): string
    {
        return 'products';
    }

    public function activityName(): string
    {
        return 'Produk';
    }

    public function activityLabel(): string
    {
        return "{$this->sku} · {$this->name}";
    }

    /**
     * @return array<string, mixed>
     */
    public function activityRelations(): array
    {
        return [
            'Varian' => $this->variants()->get()->map(fn ($variant) => $variant->sku
                .($variant->name ? ' · '.$variant->name : '')
                .($variant->barcode ? ' · barcode '.$variant->barcode : '')
                .($variant->weight_gram ? ' · '.$variant->weight_gram.' g' : '')
                .($variant->is_active ? '' : ' · nonaktif'))->all(),
            'Jumlah Gambar' => $this->images()->count(),
        ];
    }
}
