<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WarehouseLocation extends Model
{
    /** Jenis lokasi penyimpanan beserta labelnya. */
    public const TYPES = [
        'bin' => 'Bin',
        'shelf' => 'Shelf',
        'pallet' => 'Pallet',
        'floor' => 'Lantai',
    ];

    protected $fillable = [
        'warehouse_id',
        'warehouse_rack_id',
        'code',
        'level',
        'position',
        'type',
        'max_qty',
        'max_weight_kg',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'position' => 'integer',
            'max_qty' => 'integer',
            'max_weight_kg' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function rack(): BelongsTo
    {
        return $this->belongsTo(WarehouseRack::class, 'warehouse_rack_id');
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }

    public function ledgers(): HasMany
    {
        return $this->hasMany(StockLedger::class);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    /** Kode lokasi standar: {RAK}-{LEVEL 2 digit}-{POSISI 2 digit}, cth. A01-02-03. */
    public static function makeCode(string $rackCode, int $level, int $position): string
    {
        return sprintf('%s-%02d-%02d', $rackCode, $level, $position);
    }
}
