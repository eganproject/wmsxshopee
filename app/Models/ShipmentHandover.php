<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use App\Models\Concerns\ScopedToWarehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Serah terima paket ke ekspedisi. Petugas scan resi tiap paket yang diambil
 * kurir (pickup) atau dibawa ke cabang (drop off); tiap scan mengirim pesanan
 * (stok keluar). Setelah selesai, manifest dicetak untuk ditandatangani kurir.
 */
class ShipmentHandover extends Model
{
    use LogsActivity, ScopedToWarehouse;

    public const PREFIX = 'ST';

    protected $fillable = [
        'number',
        'warehouse_id',
        'courier_id',
        'shipping_method',
        'status',
        'receiver_name',
        'notes',
        'created_by',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'closed_at' => 'datetime',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(SalesOrder::class)->orderBy('shipped_at')->orderBy('id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function methodLabel(): ?string
    {
        return SalesOrder::SHIPPING_METHODS[$this->shipping_method]['label'] ?? null;
    }

    /** Nomor per bulan, cth. ST2610-001. Panggil di dalam transaksi. */
    public static function nextNumber(): string
    {
        $prefix = self::PREFIX.now()->format('ym').'-';

        $last = self::withoutGlobalScope('warehouse_access')->where('number', 'like', "{$prefix}%")->lockForUpdate()->pluck('number')
            ->map(fn ($number) => (int) substr($number, strlen($prefix)))
            ->max();

        return $prefix.str_pad((string) (($last ?? 0) + 1), 3, '0', STR_PAD_LEFT);
    }

    public function activityModule(): string
    {
        return 'sales';
    }

    public function activityName(): string
    {
        return 'Serah Terima';
    }

    public function activityLabel(): string
    {
        return $this->number;
    }
}
