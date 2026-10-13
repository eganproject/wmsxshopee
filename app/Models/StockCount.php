<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Lembar stock opname satu gudang (opsional dibatasi satu zona). */
class StockCount extends Model
{
    public const PREFIX = 'OP';

    public const STATUSES = [
        'draft' => ['label' => 'Sedang Dihitung', 'icon' => 'heroicon-o-pencil-square', 'color' => 'amber'],
        'posted' => ['label' => 'Diposting', 'icon' => 'heroicon-o-check-badge', 'color' => 'emerald'],
    ];

    public const SCOPES = [
        'all' => 'Seluruh gudang (semua bin + tanpa bin)',
        'zone' => 'Satu zona',
        'unbinned' => 'Hanya stok tanpa bin',
    ];

    protected $fillable = [
        'number',
        'status',
        'warehouse_id',
        'scope',
        'warehouse_zone_id',
        'count_date',
        'notes',
        'stock_movement_id',
        'created_by',
        'posted_by',
        'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'count_date' => 'date',
            'posted_at' => 'datetime',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(WarehouseZone::class, 'warehouse_zone_id');
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockCountItem::class)->orderBy('id');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status]['label'] ?? $this->status;
    }

    public function scopeLabel(): string
    {
        return match ($this->scope) {
            'zone' => "Zona {$this->zone?->code} · {$this->zone?->name}",
            'unbinned' => 'Hanya stok tanpa bin',
            default => 'Seluruh gudang',
        };
    }

    /** Lokasi boleh dihitung di lembar ini: null = tanpa bin. */
    public function coversLocation(?WarehouseLocation $location): bool
    {
        return match ($this->scope) {
            'zone' => $location !== null && $location->warehouse_id === $this->warehouse_id && $location->rack?->warehouse_zone_id === $this->warehouse_zone_id,
            'unbinned' => $location === null,
            default => $location === null || $location->warehouse_id === $this->warehouse_id,
        };
    }

    /** Nomor pendek berurutan per bulan, cth. OP2610-001. Panggil di dalam transaksi. */
    public static function nextNumber(): string
    {
        $prefix = self::PREFIX.now()->format('ym').'-';

        $last = self::where('number', 'like', "{$prefix}%")->lockForUpdate()->pluck('number')
            ->map(fn ($number) => (int) substr($number, strlen($prefix)))
            ->max();

        return $prefix.str_pad((string) (($last ?? 0) + 1), 3, '0', STR_PAD_LEFT);
    }
}
