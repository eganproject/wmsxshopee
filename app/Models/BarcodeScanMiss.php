<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kode yang di-scan tapi tidak dikenali sebagai SKU, barcode, barcode alias,
 * paket, lokasi bin, maupun resi. Satu baris terbuka per kode; scan berikutnya
 * menambah hitungan. Ditandai selesai setelah master datanya dibetulkan.
 */
class BarcodeScanMiss extends Model
{
    /** Tempat kode di-scan. */
    public const CONTEXTS = [
        'qc' => 'QC Kemas',
        'mobile' => 'Aplikasi Android',
        'receipt' => 'Penerimaan Barang',
        'opname' => 'Stock Opname',
        'transfer' => 'Transfer',
        'adjustment' => 'Penyesuaian Stok',
        'damaged' => 'Barang Rusak',
        'order' => 'Form Pesanan',
        'return' => 'Retur Penjualan',
        'stock' => 'Kartu Stok',
        'other' => 'Lainnya',
    ];

    public const RESOLUTIONS = [
        'alias' => 'Dijadikan barcode alias',
        'fixed' => 'Master data dibetulkan',
        'ignored' => 'Diabaikan',
    ];

    protected $fillable = [
        'code',
        'context',
        'warehouse_id',
        'sales_order_id',
        'user_id',
        'scan_count',
        'last_seen_at',
        'resolved_at',
        'resolved_by',
        'resolution',
        'resolution_note',
    ];

    protected function casts(): array
    {
        return [
            'scan_count' => 'integer',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id')->withoutGlobalScope('warehouse_access');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** @param  Builder<BarcodeScanMiss>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('resolved_at');
    }

    public function contextLabel(): string
    {
        return self::CONTEXTS[$this->context] ?? $this->context;
    }

    public function resolutionLabel(): ?string
    {
        return $this->resolution ? (self::RESOLUTIONS[$this->resolution] ?? $this->resolution) : null;
    }
}
