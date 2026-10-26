<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use App\Models\Concerns\ScopedToWarehouse;
use App\Support\DocumentNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoodsReceipt extends Model
{
    use LogsActivity, ScopedToWarehouse;

    public const PREFIX = 'GR';

    public const STATUSES = [
        'draft' => ['label' => 'Draft', 'color' => 'slate',
            'hint' => 'Barang sudah dicatat tapi belum dikonfirmasi diterima. Stok belum bertambah dan dokumen masih bisa diubah.'],
        'received' => ['label' => 'Diterima', 'color' => 'emerald',
            'hint' => 'Penerimaan sudah dikonfirmasi: barang baik masuk stok bin, barang rusak masuk Stok Rusak. Dokumen terkunci.'],
    ];

    protected $fillable = [
        'number',
        'status',
        'supplier_id',
        'warehouse_id',
        'receipt_date',
        'supplier_reference',
        'attachment_path',
        'notes',
        'stock_movement_id',
        'created_by',
        'received_by',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'receipt_date' => 'date',
            'received_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class)->orderBy('id');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status]['label'] ?? $this->status;
    }

    public function attachmentIsImage(): bool
    {
        return $this->attachment_path !== null
            && in_array(strtolower(pathinfo($this->attachment_path, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp'], true);
    }

    /**
     * Nomor pendek berurutan per bulan, cth. GR2610-001. Lewat 999 urutan
     * tetap jalan jadi 4 digit (GR2610-1000). Panggil di dalam transaksi.
     */
    public static function nextNumber(): string
    {
        $prefix = self::PREFIX.now()->format('ym').'-';

        return DocumentNumber::next(self::withoutGlobalScope('warehouse_access'), $prefix, 3);
    }

    public function activityModule(): string
    {
        return 'receipts';
    }

    public function activityName(): string
    {
        return 'Penerimaan Barang';
    }

    /**
     * @return array<string, mixed>
     */
    public function activityRelations(): array
    {
        return [
            'Item' => $this->items()->with(['variant', 'location'])->get()->map(fn ($item) => trim(
                $item->variant?->sku.' · terima '.$item->qty_received
                .($item->qty_damaged ? ', rusak '.$item->qty_damaged : '')
                .($item->qty_ordered !== null ? ', pesan '.$item->qty_ordered : '')
                .' · '.($item->location?->code ?? 'tanpa bin')
                .($item->notes ? ' · '.$item->notes : '')
            ))->all(),
        ];
    }
}
