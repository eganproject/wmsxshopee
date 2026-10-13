<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use App\Models\Concerns\ScopedToWarehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WarehouseRack extends Model
{
    use LogsActivity, ScopedToWarehouse;

    protected $fillable = [
        'warehouse_zone_id',
        'code',
        'name',
        'levels',
        'is_active',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'levels' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Rak tidak punya kolom gudang; batasi lewat zonanya.
     *
     * @param  list<int>  $ids
     */
    public function restrictToWarehouses(Builder $query, array $ids): void
    {
        $query->whereIn($this->qualifyColumn('warehouse_zone_id'), fn ($sub) => $sub
            ->select('id')->from('warehouse_zones')->whereIn('warehouse_id', $ids));
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(WarehouseZone::class, 'warehouse_zone_id');
    }

    public function locations(): HasMany
    {
        return $this->hasMany(WarehouseLocation::class);
    }

    public function activityModule(): string
    {
        return 'warehouses';
    }

    public function activityName(): string
    {
        return 'Rak';
    }
}
