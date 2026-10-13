<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesReturn extends Model
{
    use LogsActivity;

    public const PREFIX = 'RT';

    public const STATUSES = [
        'draft' => ['label' => 'Draft', 'color' => 'slate'],
        'received' => ['label' => 'Diterima', 'color' => 'emerald'],
    ];

    public const SOURCES = [
        'order' => 'Dari pesanan',
        'manual' => 'Tanpa pesanan',
    ];

    /** Alasan retur per barang. */
    public const REASONS = [
        'damaged' => 'Barang rusak / cacat',
        'wrong_item' => 'Salah kirim barang',
        'not_as_described' => 'Tidak sesuai deskripsi',
        'wrong_size' => 'Ukuran / varian tidak cocok',
        'changed_mind' => 'Pembeli berubah pikiran',
        'failed_delivery' => 'Gagal kirim / ditolak',
        'missing' => 'Tidak ada di paket (hilang)',
        'other' => 'Lainnya',
    ];

    protected $fillable = [
        'number',
        'status',
        'source',
        'sales_order_id',
        'warehouse_id',
        'return_date',
        'tracking_number',
        'sender_name',
        'sender_phone',
        'channel_name',
        'external_number',
        'external_return_number',
        'notes',
        'stock_movement_id',
        'created_by',
        'received_by',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'return_date' => 'date',
            'received_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
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
        return $this->hasMany(SalesReturnItem::class)->orderBy('id');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isFromOrder(): bool
    {
        return $this->source === 'order';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status]['label'] ?? $this->status;
    }

    public function sourceLabel(): string
    {
        return self::SOURCES[$this->source] ?? $this->source;
    }

    /**
     * Nomor pendek berurutan per bulan, cth. RT2610-001. Lewat 999 urutan
     * tetap jalan jadi 4 digit (RT2610-1000). Panggil di dalam transaksi.
     */
    public static function nextNumber(): string
    {
        $prefix = self::PREFIX.now()->format('ym').'-';

        $last = self::where('number', 'like', "{$prefix}%")->lockForUpdate()->pluck('number')
            ->map(fn ($number) => (int) substr($number, strlen($prefix)))
            ->max();

        return $prefix.str_pad((string) (($last ?? 0) + 1), 3, '0', STR_PAD_LEFT);
    }

    /**
     * Jumlah yang sudah diretur per baris pesanan (draft & diterima), supaya satu
     * barang tidak diretur melebihi jumlah yang dikirim.
     *
     * @param  array<int, int>  $orderItemIds
     * @return array<int, int> [sales_order_item_id => qty]
     */
    public static function returnedQty(array $orderItemIds, ?int $exceptReturnId = null): array
    {
        if (! $orderItemIds) {
            return [];
        }

        return SalesReturnItem::query()
            ->whereIn('sales_order_item_id', $orderItemIds)
            ->when($exceptReturnId, fn ($q) => $q->where('sales_return_id', '!=', $exceptReturnId))
            ->groupBy('sales_order_item_id')
            ->selectRaw('sales_order_item_id, SUM(qty_good + qty_damaged + qty_missing) as total')
            ->pluck('total', 'sales_order_item_id')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    public function activityModule(): string
    {
        return 'returns';
    }

    public function activityName(): string
    {
        return 'Retur Penjualan';
    }

    /**
     * @return array<string, mixed>
     */
    public function activityRelations(): array
    {
        return [
            'Item' => $this->items()->with(['variant', 'location'])->get()->map(fn ($item) => trim(
                $item->variant?->sku
                .($item->qty_good ? ' · layak jual '.$item->qty_good.' ('.($item->location?->code ?? 'tanpa bin').')' : '')
                .($item->qty_damaged ? ' · rusak '.$item->qty_damaged : '')
                .($item->qty_missing ? ' · hilang '.$item->qty_missing : '')
                .' · '.(self::REASONS[$item->reason] ?? $item->reason)
                .($item->notes ? ' · '.$item->notes : '')
            ))->all(),
        ];
    }
}
