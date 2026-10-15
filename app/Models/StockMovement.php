<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use App\Models\Concerns\ScopedToWarehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockMovement extends Model
{
    use LogsActivity, ScopedToWarehouse;

    /** Jenis mutasi: label, prefix nomor dokumen, ikon & warna badge. */
    public const TYPES = [
        'in' => ['label' => 'Barang Masuk', 'prefix' => 'MSK', 'icon' => 'heroicon-o-arrow-down-tray', 'color' => 'emerald'],
        'out' => ['label' => 'Barang Keluar', 'prefix' => 'KLR', 'icon' => 'heroicon-o-arrow-up-tray', 'color' => 'rose'],
        'transfer' => ['label' => 'Transfer Lokasi', 'prefix' => 'TRF', 'icon' => 'heroicon-o-arrows-right-left', 'color' => 'sky'],
        'adjustment' => ['label' => 'Penyesuaian', 'prefix' => 'ADJ', 'icon' => 'heroicon-o-adjustments-horizontal', 'color' => 'amber'],
        // Dicatat otomatis oleh dokumen barang rusak (lihat DamagedDocument).
        'damage' => ['label' => 'Pindah ke Rusak', 'prefix' => 'MRS', 'icon' => 'heroicon-o-exclamation-triangle', 'color' => 'rose'],
        'repair' => ['label' => 'Hasil Perbaikan', 'prefix' => 'MPB', 'icon' => 'heroicon-o-wrench-screwdriver', 'color' => 'emerald'],
        'assembly' => ['label' => 'Rakit / Campur', 'prefix' => 'MRK', 'icon' => 'heroicon-o-puzzle-piece', 'color' => 'violet'],
        // Dicatat otomatis oleh Transfer Antar Gudang (lihat WarehouseTransfer).
        'transfer_out' => ['label' => 'Kirim Antar Gudang', 'prefix' => 'TKG', 'icon' => 'heroicon-o-truck', 'color' => 'sky'],
        'transfer_in' => ['label' => 'Terima Antar Gudang', 'prefix' => 'TMG', 'icon' => 'heroicon-o-inbox-arrow-down', 'color' => 'sky'],
    ];

    /** Jenis dengan qty bertanda: + masuk ke to_location_id, - keluar dari from_location_id (penyesuaian: keduanya to_location_id). */
    public const SIGNED_TYPES = ['adjustment', 'assembly'];

    /**
     * Jenis yang boleh dicatat manual lewat halaman Stok. Barang masuk hanya lewat
     * Penerimaan Barang (dan impor stok awal), barang keluar lewat pesanan/retur.
     */
    public const MANUAL_TYPES = ['transfer', 'adjustment'];

    protected $fillable = [
        'number',
        'type',
        'warehouse_id',
        'movement_date',
        'reference',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'movement_date' => 'date',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockMovementItem::class)->orderBy('id');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type]['label'] ?? $this->type;
    }

    public static function typeMeta(string $type, string $key): ?string
    {
        return self::TYPES[$type][$key] ?? null;
    }

    public function activityModule(): string
    {
        return 'inventory';
    }

    public function activityName(): string
    {
        return 'Mutasi Stok';
    }

    /**
     * @return array<string, mixed>
     */
    public function activityRelations(): array
    {
        return [
            'Item' => $this->items()->with(['variant', 'fromLocation', 'toLocation'])->get()->map(fn ($item) => $item->variant?->sku.' × '.$item->qty
                .($item->fromLocation || $item->toLocation ? ' · '.($item->fromLocation?->code ?? '—').' → '.($item->toLocation?->code ?? '—') : '')
                .($item->notes ? ' · '.$item->notes : ''))->all(),
        ];
    }
}
