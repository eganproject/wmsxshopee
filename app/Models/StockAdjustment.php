<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use App\Models\Concerns\ScopedToWarehouse;
use App\Support\DocumentNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Dokumen penyesuaian stok. Draft bisa diubah/dihapus, lalu diajukan untuk
 * approval. Stok baru berubah saat disetujui (lewat satu mutasi Penyesuaian);
 * dokumen yang disetujui atau ditolak terkunci.
 */
class StockAdjustment extends Model
{
    use LogsActivity, ScopedToWarehouse;

    public const PREFIX = 'PNY';

    public const STATUSES = [
        'draft' => ['label' => 'Draft', 'icon' => 'heroicon-o-document', 'color' => 'slate',
            'hint' => 'Belum diajukan. Stok belum berubah dan dokumen masih bisa diubah atau dihapus.'],
        'pending' => ['label' => 'Menunggu Approval', 'icon' => 'heroicon-o-clock', 'color' => 'amber',
            'hint' => 'Sudah diajukan dan menunggu disetujui atau ditolak. Stok belum berubah.'],
        'approved' => ['label' => 'Disetujui', 'icon' => 'heroicon-o-check-circle', 'color' => 'emerald',
            'hint' => 'Disetujui: stok sudah disesuaikan lewat mutasi Penyesuaian dan dokumen terkunci.'],
        'rejected' => ['label' => 'Ditolak', 'icon' => 'heroicon-o-x-circle', 'color' => 'rose',
            'hint' => 'Ditolak: stok tidak berubah dan dokumen terkunci.'],
    ];

    /** Alasan penyesuaian, wajib dipilih supaya selisih stok bisa dianalisis. */
    public const REASONS = [
        'input_error' => 'Salah catat / input',
        'lost' => 'Barang hilang / kurang',
        'found' => 'Barang ditemukan / lebih',
        'sample' => 'Sampel / promosi / pemakaian sendiri',
        'expired' => 'Kedaluwarsa / dibuang',
        'other' => 'Lainnya',
    ];

    protected $fillable = [
        'number',
        'status',
        'warehouse_id',
        'adjustment_date',
        'reason',
        'reference',
        'notes',
        'stock_movement_id',
        'created_by',
        'submitted_by',
        'submitted_at',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'adjustment_date' => 'date',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
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

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockAdjustmentItem::class)->orderBy('id');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status]['label'] ?? $this->status;
    }

    public function reasonLabel(): string
    {
        return self::REASONS[$this->reason] ?? $this->reason;
    }

    /** Nomor pendek berurutan per bulan, cth. PNY2610-001. Panggil di dalam transaksi. */
    public static function nextNumber(): string
    {
        $prefix = self::PREFIX.now()->format('ym').'-';

        return DocumentNumber::next(self::withoutGlobalScope('warehouse_access'), $prefix, 3);
    }

    public function activityModule(): string
    {
        return 'adjustments';
    }

    public function activityName(): string
    {
        return 'Penyesuaian Stok';
    }

    /**
     * Siapa yang mengajukan / menyetujui sudah tercatat sebagai pelaku log status.
     *
     * @return array<int, string>
     */
    public function activityIgnored(): array
    {
        return ['submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'rejected_by', 'rejected_at'];
    }

    /**
     * @return array<string, mixed>
     */
    public function activityRelations(): array
    {
        return [
            'Item' => $this->items()->with(['variant', 'location'])->get()->map(fn ($item) => $item->variant?->sku.' '
                .($item->qty > 0 ? '+' : '').$item->qty.' · '.($item->location?->code ?? 'tanpa bin')
                .($item->notes ? ' · '.$item->notes : ''))->all(),
        ];
    }
}
