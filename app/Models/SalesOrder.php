<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use App\Models\Concerns\ScopedToWarehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesOrder extends Model
{
    use LogsActivity, ScopedToWarehouse;

    public const PREFIX = 'SO';

    /** Urutan alur pesanan; label, ikon & warna badge. */
    public const STATUSES = [
        'draft' => ['label' => 'Draft', 'icon' => 'heroicon-o-document', 'color' => 'slate'],
        'confirmed' => ['label' => 'Dikonfirmasi', 'icon' => 'heroicon-o-check-badge', 'color' => 'sky'],
        'picked' => ['label' => 'Sudah Diambil', 'icon' => 'heroicon-o-hand-raised', 'color' => 'violet'],
        'packed' => ['label' => 'Sudah Dikemas', 'icon' => 'heroicon-o-archive-box', 'color' => 'amber'],
        'shipped' => ['label' => 'Dikirim', 'icon' => 'heroicon-o-truck', 'color' => 'emerald'],
        'cancelled' => ['label' => 'Batal', 'icon' => 'heroicon-o-x-circle', 'color' => 'rose'],
    ];

    /** Status yang menahan (mencadangkan) stok di gudang. */
    public const RESERVING = ['confirmed', 'picked', 'packed'];

    /** Cara paket diserahkan ke kurir. */
    public const SHIPPING_METHODS = [
        'pickup' => ['label' => 'Pickup', 'description' => 'Kurir menjemput paket di gudang', 'icon' => 'heroicon-o-truck'],
        'dropoff' => ['label' => 'Drop Off', 'description' => 'Paket diantar ke cabang / agen kurir', 'icon' => 'heroicon-o-building-storefront'],
    ];

    /** Rincian pendapatan Shopee tidak ikut ke JSON/array (hanya untuk hak sales.income). */
    protected $hidden = ['shopee_income', 'shopee_escrow_amount'];

    protected $fillable = [
        'number',
        'status',
        'warehouse_id',
        'order_date',
        'channel_name',
        'external_number',
        'shopee_shop_id',
        'shopee_order_status',
        'shopee_package_number',
        'shopee_sync_error',
        'shopee_synced_at',
        'shopee_ship_by',
        'shopee_income',
        'shopee_escrow_amount',
        'shopee_income_final',
        'shopee_income_synced_at',
        'customer_name',
        'customer_phone',
        'shipping_address',
        'courier_id',
        'courier_service',
        'tracking_number',
        'shipping_fee',
        'shipping_method',
        'pickup_at',
        'pickup_slot',
        'pickup_address',
        'dropoff_point',
        'shipment_arranged_at',
        'shipment_arranged_by',
        'shipping_label_printed_at',
        'qc_checked_by',
        'shipment_handover_id',
        'shipped_by',
        'notes',
        'cancel_reason',
        'stock_movement_id',
        'created_by',
        'confirmed_at',
        'picked_at',
        'packed_at',
        'shipped_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'shipping_fee' => 'decimal:2',
            'confirmed_at' => 'datetime',
            'picked_at' => 'datetime',
            'packed_at' => 'datetime',
            'shipped_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'shopee_synced_at' => 'datetime',
            'shopee_ship_by' => 'datetime',
            'pickup_at' => 'datetime',
            'shipment_arranged_at' => 'datetime',
            'shipping_label_printed_at' => 'datetime',
            'shopee_income' => 'array',
            'shopee_escrow_amount' => 'decimal:2',
            'shopee_income_final' => 'boolean',
            'shopee_income_synced_at' => 'datetime',
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

    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }

    public function shopeeShop(): BelongsTo
    {
        return $this->belongsTo(ShopeeShop::class);
    }

    public function arranger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shipment_arranged_by');
    }

    public function qcChecker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'qc_checked_by');
    }

    public function shipper(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shipped_by');
    }

    public function handover(): BelongsTo
    {
        return $this->belongsTo(ShipmentHandover::class, 'shipment_handover_id');
    }

    /**
     * Cari pesanan dari hasil scan: nomor resi, nomor SO atau no. pesanan marketplace.
     *
     * @param  list<string>  $statuses
     */
    public static function findByScan(string $code, array $statuses = []): ?self
    {
        $code = trim($code);

        if ($code === '') {
            return null;
        }

        return self::query()
            ->when($statuses, fn ($q) => $q->whereIn('status', $statuses))
            ->where(fn ($q) => $q->where('tracking_number', $code)->orWhere('number', $code)->orWhere('external_number', $code))
            ->orderByRaw('CASE WHEN tracking_number = ? THEN 0 WHEN number = ? THEN 1 ELSE 2 END', [$code, $code])
            ->latest('id')
            ->first();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class)->orderBy('id');
    }

    /** Baris barang biasa (bukan isi paket). */
    public function looseItems(): HasMany
    {
        return $this->items()->whereNull('sales_order_bundle_id');
    }

    public function bundles(): HasMany
    {
        return $this->hasMany(SalesOrderBundle::class)->orderBy('id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(SalesReturn::class)->orderBy('id');
    }

    public function hasStatus(string ...$statuses): bool
    {
        return in_array($this->status, $statuses, true);
    }

    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }

    public function isCancellable(): bool
    {
        return in_array($this->status, ['draft', ...self::RESERVING], true);
    }

    public function isShopee(): bool
    {
        return $this->shopee_shop_id !== null;
    }

    public function isShipmentArranged(): bool
    {
        return $this->shipment_arranged_at !== null;
    }

    /**
     * Pengiriman bisa diatur selama stok sudah dicadangkan dan belum dikirim.
     * Pesanan Shopee hanya selama statusnya di Shopee masih READY_TO_SHIP;
     * setelah diatur (PROCESSED) Shopee tidak menerima pengaturan ulang.
     * Pesanan manual boleh diubah kapan saja sebelum dikirim.
     */
    public function canArrangeShipment(): bool
    {
        if (! $this->hasStatus(...self::RESERVING)) {
            return false;
        }

        return ! $this->isShopee() || $this->shopee_order_status === 'READY_TO_SHIP';
    }

    /** Pesanan Shopee yang pengirimannya sudah diatur (di Optima atau Seller Centre). */
    public function shopeeShipmentArranged(): bool
    {
        return $this->isShopee() && filled($this->shopee_order_status) && ! in_array($this->shopee_order_status, ['UNPAID', 'READY_TO_SHIP', 'IN_CANCEL', 'CANCELLED'], true);
    }

    /** Label resi: Shopee setelah pengiriman diatur, manual setelah ada metode / resi. */
    public function hasShippingLabel(): bool
    {
        if ($this->hasStatus('draft', 'cancelled')) {
            return false;
        }

        return $this->isShopee() ? $this->shopeeShipmentArranged() : ($this->isShipmentArranged() || filled($this->tracking_number));
    }

    public function shippingMethodLabel(): ?string
    {
        return self::SHIPPING_METHODS[$this->shipping_method]['label'] ?? null;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status]['label'] ?? $this->status;
    }

    public function subtotal(): float
    {
        // Isi paket berharga 0; harga paket ada di baris paketnya.
        return (float) $this->items->sum(fn ($item) => $item->qty * $item->price)
            + (float) $this->bundles->sum(fn ($line) => $line->qty * $line->price);
    }

    public function trackingUrl(): ?string
    {
        return $this->courier && $this->tracking_number ? $this->courier->trackingUrlFor($this->tracking_number) : null;
    }

    /**
     * Nomor SO lain (bukan batal) yang sudah memakai no. pesanan marketplace ini di
     * saluran yang sama, supaya pesanan yang sama tidak diproses dua kali.
     */
    public static function duplicateOf(?string $channel, string $external, ?int $exceptId = null): ?string
    {
        return self::query()
            ->where('external_number', trim($external))
            ->where('channel_name', filled($channel) ? trim($channel) : null)
            ->where('status', '!=', 'cancelled')
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->value('number');
    }

    /**
     * Nomor pendek berurutan per bulan, cth. SO2610-001. Lewat 999 urutan
     * tetap jalan jadi 4 digit (SO2610-1000). Panggil di dalam transaksi.
     */
    public static function nextNumber(): string
    {
        $prefix = self::PREFIX.now()->format('ym').'-';

        $last = self::where('number', 'like', "{$prefix}%")->lockForUpdate()->pluck('number')
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
        return 'Pesanan';
    }

    /** Jejak waktu sinkron Shopee berubah tiap tarikan; tidak perlu masuk log. */
    public function activityIgnored(): array
    {
        return ['shopee_synced_at', 'shipping_label_printed_at', 'shopee_income', 'shopee_escrow_amount', 'shopee_income_final', 'shopee_income_synced_at'];
    }

    /**
     * @return array<string, mixed>
     */
    public function activityRelations(): array
    {
        return [
            'Item' => $this->items()->with('variant')->get()->map(fn ($item) => $item->variant?->sku.' × '.$item->qty
                .' @ '.number_format((float) $item->price, 0, ',', '.')
                .($item->notes ? ' · '.$item->notes : ''))->all(),
        ];
    }
}
