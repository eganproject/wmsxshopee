<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use App\Models\Concerns\ScopedToWarehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Transfer antar gudang (cth. gudang besar -> gudang kecil).
 * Dikirim: stok keluar dari gudang asal dan berstatus dalam perjalanan.
 * Diterima: stok masuk ke bin gudang tujuan. Dibatalkan: stok kembali ke asal.
 */
class WarehouseTransfer extends Model
{
    use LogsActivity, ScopedToWarehouse;

    public const STATUSES = [
        'sent' => ['label' => 'Dalam Perjalanan', 'color' => 'amber'],
        'received' => ['label' => 'Diterima', 'color' => 'emerald'],
        'cancelled' => ['label' => 'Dibatalkan', 'color' => 'slate'],
    ];

    public const PREFIX = 'TAG';

    protected $fillable = [
        'number',
        'status',
        'from_warehouse_id',
        'to_warehouse_id',
        'transfer_date',
        'reference',
        'notes',
        'out_movement_id',
        'in_movement_id',
        'return_movement_id',
        'created_by',
        'received_by',
        'received_at',
        'cancelled_at',
        'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'transfer_date' => 'date',
            'received_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** Terlihat bila user punya akses ke gudang asal atau gudang tujuan. */
    public function restrictToWarehouses(Builder $query, array $ids): void
    {
        $query->where(fn ($q) => $q->whereIn($this->qualifyColumn('from_warehouse_id'), $ids)->orWhereIn($this->qualifyColumn('to_warehouse_id'), $ids));
    }

    public function isSent(): bool
    {
        return $this->status === 'sent';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status]['label'] ?? $this->status;
    }

    public function statusColor(): string
    {
        return self::STATUSES[$this->status]['color'] ?? 'slate';
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(WarehouseTransferItem::class)->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function outMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'out_movement_id');
    }

    public function inMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'in_movement_id');
    }

    public function returnMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'return_movement_id');
    }

    public function activityModule(): string
    {
        return 'inventory';
    }

    public function activityName(): string
    {
        return 'Transfer Antar Gudang';
    }

    public function activityLabel(): string
    {
        return $this->number;
    }

    /**
     * @return array<string, mixed>
     */
    public function activityRelations(): array
    {
        return [
            'Item' => $this->items()->with('variant')->get()->map(fn ($item) => $item->variant?->sku.' × '.$item->qty
                .($item->received_qty !== null ? ' (diterima '.$item->received_qty.')' : ''))->all(),
        ];
    }
}
