<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use App\Models\Concerns\ScopedToWarehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Dokumen barang rusak. Disimpan langsung (tanpa draft) dan tidak bisa diubah. */
class DamagedDocument extends Model
{
    use LogsActivity, ScopedToWarehouse;

    /**
     * Jenis dokumen: label, prefix nomor, ikon, permission yang dibutuhkan,
     * dan penjelasan singkat untuk form.
     */
    public const TYPES = [
        'intake' => [
            'label' => 'Pindah ke Rusak', 'prefix' => 'RSK', 'icon' => 'heroicon-o-exclamation-triangle', 'permission' => 'damaged.move',
            'hint' => 'Barang yang ditemukan rusak di rak: stok baik berkurang dari lokasi bin, stok rusak gudang bertambah.',
        ],
        'supplier_return' => [
            'label' => 'Retur ke Supplier', 'prefix' => 'RTS', 'icon' => 'heroicon-o-truck', 'permission' => 'damaged.return',
            'hint' => 'Kirim barang rusak kembali ke supplier atau pihak lain (distributor, service center). Stok rusak berkurang.',
        ],
        'disposal' => [
            'label' => 'Pemusnahan', 'prefix' => 'MSN', 'icon' => 'heroicon-o-trash', 'permission' => 'damaged.dispose',
            'hint' => 'Barang rusak yang dibuang / dimusnahkan. Stok rusak berkurang dan tidak kembali ke stok baik.',
        ],
        'repair' => [
            'label' => 'Perbaikan', 'prefix' => 'PRB', 'icon' => 'heroicon-o-wrench-screwdriver', 'permission' => 'damaged.repair',
            'hint' => 'Barang rusak yang sudah diperbaiki: stok rusak berkurang dan masuk lagi ke stok baik di lokasi bin tujuan.',
        ],
        'assembly' => [
            'label' => 'Rakit / Campur', 'prefix' => 'RKT', 'icon' => 'heroicon-o-puzzle-piece', 'permission' => 'damaged.assemble',
            'hint' => 'Barang rusak (bisa ditambah barang lain dari stok baik) dirakit / dicampur jadi barang baru yang masuk stok baik.',
        ],
    ];

    protected $fillable = [
        'number',
        'type',
        'warehouse_id',
        'document_date',
        'supplier_id',
        'recipient',
        'reference',
        'notes',
        'stock_movement_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'document_date' => 'date',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(DamagedDocumentItem::class)->orderBy('id');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type]['label'] ?? $this->type;
    }

    public static function typeMeta(string $type, string $key): ?string
    {
        return self::TYPES[$type][$key] ?? null;
    }

    /** Nama penerima retur: supplier atau pihak lain yang diketik. */
    public function recipientName(): ?string
    {
        return $this->supplier?->name ?? $this->recipient;
    }

    public function activityModule(): string
    {
        return 'damaged';
    }

    public function activityName(): string
    {
        return $this->typeLabel();
    }

    /**
     * @return array<string, mixed>
     */
    public function activityRelations(): array
    {
        return [
            'Item' => $this->items()->with(['variant', 'fromLocation', 'toLocation'])->get()->map(fn ($item) => ($item->isOutput() ? 'Hasil: ' : '')
                .$item->variant?->sku.' × '.$item->qty
                .($item->fromGoodStock() ? ' · stok baik '.($item->fromLocation?->code ?? 'tanpa bin') : '')
                .($item->toLocation ? ' → '.$item->toLocation->code : '')
                .($item->notes ? ' · '.$item->notes : ''))->all(),
        ];
    }
}
