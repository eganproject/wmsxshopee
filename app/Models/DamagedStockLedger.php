<?php

namespace App\Models;

use App\Models\Concerns\ScopedToWarehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Satu baris kartu stok rusak: perubahan saldo rusak satu varian di satu gudang. */
class DamagedStockLedger extends Model
{
    use ScopedToWarehouse;

    /**
     * Jenis baris kartu stok rusak: dari penerimaan & retur (masuk) atau
     * dari dokumen barang rusak (lihat DamagedDocument::TYPES).
     */
    public const SOURCE_TYPES = [
        'receipt' => ['label' => 'Penerimaan Barang', 'icon' => 'heroicon-o-inbox-arrow-down',
            'hint' => 'Barang yang datang dari supplier dalam kondisi rusak dan langsung masuk Stok Rusak.'],
        'return' => ['label' => 'Retur Penjualan', 'icon' => 'heroicon-o-arrow-uturn-left',
            'hint' => 'Barang retur dari pembeli yang kondisinya rusak dan masuk Stok Rusak.'],
    ];

    protected $fillable = [
        'product_variant_id',
        'warehouse_id',
        'type',
        'source_type',
        'source_id',
        'reference',
        'movement_date',
        'qty_in',
        'qty_out',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'movement_date' => 'date',
            'qty_in' => 'integer',
            'qty_out' => 'integer',
        ];
    }

    /** @return array<string, array{label: string, icon: string}> */
    public static function types(): array
    {
        return self::SOURCE_TYPES + collect(DamagedDocument::TYPES)->map(fn ($meta) => ['label' => $meta['label'], 'icon' => $meta['icon'], 'hint' => $meta['hint'] ?? null])->all();
    }

    public function typeLabel(): string
    {
        return self::types()[$this->type]['label'] ?? $this->type;
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /** Link ke dokumen sumber, atau null bila user tidak berhak melihatnya. */
    public function sourceUrl(): ?string
    {
        $user = auth()->user();

        return match ($this->source_type) {
            GoodsReceipt::class => $user?->can('receipts.view') ? route('inventory.receipts.show', $this->source_id) : null,
            SalesReturn::class => $user?->can('returns.view') ? route('sales.returns.show', $this->source_id) : null,
            DamagedDocument::class => $user?->can('damaged.view') ? route('inventory.damaged.documents.show', $this->source_id) : null,
            default => null,
        };
    }
}
