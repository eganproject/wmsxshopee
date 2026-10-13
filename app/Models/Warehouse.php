<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Warehouse extends Model
{
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
}
