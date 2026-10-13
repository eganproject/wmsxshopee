<?php

namespace App\Models\Concerns;

use App\Support\WarehouseAccess;
use Illuminate\Database\Eloquent\Builder;

/**
 * Model yang datanya milik satu gudang. Query otomatis dibatasi ke gudang
 * yang boleh diakses user login, termasuk route model binding (record gudang
 * lain jadi 404). Pakai withoutGlobalScope('warehouse_access') bila perlu.
 */
trait ScopedToWarehouse
{
    public static function bootScopedToWarehouse(): void
    {
        static::addGlobalScope('warehouse_access', function (Builder $query) {
            $ids = WarehouseAccess::ids();

            if ($ids !== null) {
                $query->getModel()->restrictToWarehouses($query, $ids);
            }
        });
    }

    /**
     * @param  list<int>  $ids
     */
    public function restrictToWarehouses(Builder $query, array $ids): void
    {
        $query->whereIn($this->qualifyColumn('warehouse_id'), $ids);
    }
}
