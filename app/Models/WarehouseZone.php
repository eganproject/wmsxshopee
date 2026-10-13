<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WarehouseZone extends Model
{
    /** Jenis zona beserta labelnya. */
    public const TYPES = [
        'receiving' => 'Penerimaan (Inbound)',
        'storage' => 'Penyimpanan',
        'picking' => 'Picking',
        'packing' => 'Packing',
        'shipping' => 'Pengiriman (Outbound)',
        'quarantine' => 'Karantina / Rusak',
        'return' => 'Retur',
    ];

    protected $fillable = [
        'warehouse_id',
        'code',
        'name',
        'type',
        'is_active',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function racks(): HasMany
    {
        return $this->hasMany(WarehouseRack::class);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
