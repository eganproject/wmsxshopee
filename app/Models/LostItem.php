<?php

namespace App\Models;

use App\Models\Concerns\ScopedToWarehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu catatan barang hilang di gudang: kekurangan saat terima transfer antar
 * gudang atau barang yang tidak ada di paket retur. Hanya jejak untuk laporan;
 * stoknya memang tidak pernah masuk ke saldo mana pun.
 */
class LostItem extends Model
{
    use ScopedToWarehouse;

    public const SOURCES = [
        'transfer' => ['label' => 'Kurang saat terima transfer', 'icon' => 'heroicon-o-truck'],
        'return' => ['label' => 'Tidak ada di paket retur', 'icon' => 'heroicon-o-arrow-uturn-left'],
    ];

    protected $fillable = [
        'warehouse_id',
        'from_warehouse_id',
        'product_variant_id',
        'source',
        'source_id',
        'reference',
        'lost_date',
        'qty',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'lost_date' => 'date',
            'qty' => 'integer',
        ];
    }

    public function sourceLabel(): string
    {
        return self::SOURCES[$this->source]['label'] ?? $this->source;
    }

    /** Link ke dokumen asal (transfer / retur). */
    public function sourceUrl(): ?string
    {
        return match ($this->source) {
            'transfer' => route('inventory.transfers.show', $this->source_id),
            'return' => route('sales.returns.show', $this->source_id),
            default => null,
        };
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class)->withoutGlobalScope('warehouse_access');
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id')->withoutGlobalScope('warehouse_access');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
