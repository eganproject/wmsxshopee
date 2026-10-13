<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Satu baris log aktivitas: siapa melakukan apa, kapan, beserta nilai
 * sebelum & sesudah. Tidak pernah diubah setelah dicatat.
 */
class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    public const EVENTS = [
        'created' => ['label' => 'Dibuat', 'icon' => 'heroicon-o-plus-circle', 'color' => 'emerald'],
        'updated' => ['label' => 'Diubah', 'icon' => 'heroicon-o-pencil-square', 'color' => 'sky'],
        'deleted' => ['label' => 'Dihapus', 'icon' => 'heroicon-o-trash', 'color' => 'rose'],
        'status' => ['label' => 'Ubah Status', 'icon' => 'heroicon-o-arrow-path', 'color' => 'violet'],
        'login' => ['label' => 'Login', 'icon' => 'heroicon-o-arrow-right-end-on-rectangle', 'color' => 'slate'],
        'logout' => ['label' => 'Logout', 'icon' => 'heroicon-o-arrow-left-start-on-rectangle', 'color' => 'slate'],
        'login_failed' => ['label' => 'Login Gagal', 'icon' => 'heroicon-o-exclamation-triangle', 'color' => 'amber'],
    ];

    protected $fillable = [
        'user_id',
        'user_name',
        'module',
        'event',
        'subject_type',
        'subject_id',
        'subject_label',
        'description',
        'properties',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Modul yang bisa difilter: modul permission ditambah "auth" untuk login.
     *
     * @return array<string, string>
     */
    public static function modules(): array
    {
        return ['auth' => 'Login & Sesi']
            + collect(config('permissions.modules'))->map(fn ($module) => $module['label'])->all();
    }

    public function moduleLabel(): string
    {
        return self::modules()[$this->module] ?? $this->module;
    }

    public function eventLabel(): string
    {
        return self::EVENTS[$this->event]['label'] ?? $this->event;
    }

    public function eventColor(): string
    {
        return self::EVENTS[$this->event]['color'] ?? 'slate';
    }

    public function eventIcon(): string
    {
        return self::EVENTS[$this->event]['icon'] ?? 'heroicon-o-bolt';
    }

    /**
     * Baris perubahan untuk ditampilkan: [label kolom, nilai lama, nilai baru].
     *
     * @return array<int, array{field: string, old: mixed, new: mixed}>
     */
    public function diffRows(): array
    {
        $old = $this->properties['old'] ?? [];
        $new = $this->properties['new'] ?? [];

        return collect(array_keys($old + $new))
            ->map(fn ($field) => ['field' => $field, 'old' => $old[$field] ?? null, 'new' => $new[$field] ?? null])
            ->all();
    }

    /**
     * Halaman detail/edit data yang dicatat, kalau ada dan user boleh membukanya.
     */
    public function subjectUrl(): ?string
    {
        $routes = [
            GoodsReceipt::class => ['inventory.receipts.show', 'receipts.view'],
            SalesOrder::class => ['sales.orders.show', 'sales.view'],
            StockMovement::class => ['inventory.movements.show', 'inventory.view'],
            Product::class => ['master.products.edit', 'products.edit'],
            Unit::class => ['master.units.edit', 'products.edit'],
            Supplier::class => ['master.suppliers.edit', 'suppliers.edit'],
            Courier::class => ['master.couriers.edit', 'couriers.edit'],
            Warehouse::class => ['master.warehouses.edit', 'warehouses.edit'],
            WarehouseZone::class => ['master.zones.edit', 'warehouses.edit'],
            WarehouseRack::class => ['master.racks.edit', 'warehouses.edit'],
            WarehouseLocation::class => ['master.locations.edit', 'warehouses.edit'],
            User::class => ['admin.users.edit', 'users.edit'],
        ];

        [$route, $permission] = $routes[$this->subject_type] ?? [null, null];

        if (! $route || ! $this->subject_id || $this->event === 'deleted' || ! auth()->user()?->can($permission)) {
            return null;
        }

        return route($route, $this->subject_id);
    }

    public function hasDiff(): bool
    {
        return ! empty($this->properties['old']) || ! empty($this->properties['new']);
    }
}
