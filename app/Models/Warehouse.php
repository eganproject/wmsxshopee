<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use App\Models\Concerns\ScopedToWarehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Warehouse extends Model
{
    use LogsActivity, ScopedToWarehouse;

    protected $fillable = [
        'code',
        'name',
        'address',
        'city',
        'province',
        'postal_code',
        'phone',
        'pic_name',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function zones(): HasMany
    {
        return $this->hasMany(WarehouseZone::class);
    }

    public function racks(): HasManyThrough
    {
        return $this->hasManyThrough(WarehouseRack::class, WarehouseZone::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(WarehouseLocation::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /**
     * @param  list<int>  $ids
     */
    public function restrictToWarehouses(Builder $query, array $ids): void
    {
        $query->whereIn($this->qualifyColumn('id'), $ids);
    }

    public function activityModule(): string
    {
        return 'warehouses';
    }

    public function activityName(): string
    {
        return 'Gudang';
    }
}
