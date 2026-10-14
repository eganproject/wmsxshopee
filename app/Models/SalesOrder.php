<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use App\Models\Concerns\ScopedToWarehouse;
use Illuminate\Database\Eloquent\Builder;
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

    /** Status pesanan di Shopee (order_status) dengan label Indonesia. */
    public const SHOPEE_STATUSES = [
        'UNPAID' => ['label' => 'Belum Bayar', 'color' => 'slate'],
        'READY_TO_SHIP' => ['label' => 'Siap Dikirim', 'color' => 'sky'],
        'PROCESSED' => ['label' => 'Diproses', 'color' => 'violet'],
        'RETRY_SHIP' => ['label' => 'Kirim Ulang', 'color' => 'amber'],
        'SHIPPED' => ['label' => 'Dikirim', 'color' => 'emerald'],
        'TO_CONFIRM_RECEIVE' => ['label' => 'Sampai di Pembeli', 'color' => 'emerald'],
        'COMPLETED' => ['label' => 'Selesai', 'color' => 'emerald'],
        'IN_CANCEL' => ['label' => 'Pengajuan Batal', 'color' => 'amber'],
        'CANCELLED' => ['label' => 'Batal', 'color' => 'rose'],
        'TO_RETURN' => ['label' => 'Retur', 'color' => 'rose'],
    ];

    /** Status Shopee yang berarti paket sudah di tangan kurir (resi berhasil dikirim). */
    public const SHOPEE_LEFT_WAREHOUSE = ['SHIPPED', 'TO_CONFIRM_RECEIVE', 'COMPLETED', 'TO_RETURN'];

    /** Status Shopee setelah pengiriman diatur tapi kurir belum scan paket. */
    public const SHOPEE_AWAITING_COURIER = ['PROCESSED', 'RETRY_SHIP'];

    /** Status pesanan di TikTok Shop dengan label Indonesia. */
    public const TIKTOK_STATUSES = [
        'UNPAID' => ['label' => 'Belum Bayar', 'color' => 'slate'],
        'ON_HOLD' => ['label' => 'Ditahan', 'color' => 'slate'],
        'AWAITING_SHIPMENT' => ['label' => 'Perlu Dikirim', 'color' => 'sky'],
        'PARTIALLY_SHIPPING' => ['label' => 'Dikirim Sebagian', 'color' => 'amber'],
        'AWAITING_COLLECTION' => ['label' => 'Menunggu Kurir', 'color' => 'violet'],
        'IN_TRANSIT' => ['label' => 'Dalam Perjalanan', 'color' => 'emerald'],
        'DELIVERED' => ['label' => 'Terkirim', 'color' => 'emerald'],
        'COMPLETED' => ['label' => 'Selesai', 'color' => 'emerald'],
        'CANCELLED' => ['label' => 'Batal', 'color' => 'rose'],
    ];

    /** Status TikTok yang berarti pesanan harus disiapkan gudang. */
    public const TIKTOK_TO_FULFIL = ['AWAITING_SHIPMENT', 'PARTIALLY_SHIPPING', 'AWAITING_COLLECTION'];

    /** Status TikTok yang berarti paket sudah di tangan kurir. */
    public const TIKTOK_LEFT_WAREHOUSE = ['IN_TRANSIT', 'DELIVERED', 'COMPLETED'];

    /** Status yang menahan (mencadangkan) stok di gudang. */
    public const RESERVING = ['confirmed', 'picked', 'packed'];

    /** Cara paket diserahkan ke kurir. */
    public const SHIPPING_METHODS = [
        'pickup' => ['label' => 'Pickup', 'description' => 'Kurir menjemput paket di gudang', 'icon' => 'heroicon-o-truck'],
        'dropoff' => ['label' => 'Drop Off', 'description' => 'Paket diantar ke cabang / agen kurir', 'icon' => 'heroicon-o-building-storefront'],
    ];

    /** Cara barang sampai ke pembeli. Diambil pembeli tidak butuh kurir, resi maupun serah terima. */
    public const DELIVERY_TYPES = [
        'courier' => ['label' => 'Dikirim Kurir', 'description' => 'Paket dikirim ke alamat pembeli', 'icon' => 'heroicon-o-truck'],
        'self_pickup' => ['label' => 'Diambil Pembeli', 'description' => 'Pembeli datang mengambil di gudang / toko', 'icon' => 'heroicon-o-hand-raised'],
    ];

    public const PAYMENT_METHODS = [
        'cash' => 'Tunai',
        'transfer' => 'Transfer Bank',
        'qris' => 'QRIS / E-Wallet',
        'cod' => 'COD (Bayar di Tempat)',
        'marketplace' => 'Lewat Marketplace',
        'other' => 'Lainnya',
    ];

    public const PAYMENT_STATUSES = [
        'unpaid' => ['label' => 'Belum Lunas', 'color' => 'amber'],
        'paid' => ['label' => 'Lunas', 'color' => 'emerald'],
    ];

    /** Rincian pendapatan Shopee tidak ikut ke JSON/array (hanya untuk hak sales.income). */
    protected $hidden = ['shopee_income', 'shopee_escrow_amount', 'tiktok_payment'];

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
        'shopee_shipped_at',
        'shipped_automatically',
        'shopee_income',
        'shopee_escrow_amount',
        'shopee_income_final',
        'shopee_income_synced_at',
        'tiktok_shop_id',
        'tiktok_order_status',
        'tiktok_package_id',
        'tiktok_sync_error',
        'tiktok_synced_at',
        'tiktok_ship_by',
        'tiktok_shipped_at',
        'tiktok_payment',
        'customer_name',
        'customer_phone',
        'shipping_address',
        'delivery_type',
        'courier_id',
        'courier_service',
        'tracking_number',
        'shipping_fee',
        'discount',
        'payment_method',
        'payment_status',
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
            'discount' => 'decimal:2',
            'confirmed_at' => 'datetime',
            'picked_at' => 'datetime',
            'packed_at' => 'datetime',
            'shipped_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'shopee_synced_at' => 'datetime',
            'shopee_ship_by' => 'datetime',
            'shopee_shipped_at' => 'datetime',
            'shipped_automatically' => 'boolean',
            'pickup_at' => 'datetime',
            'shipment_arranged_at' => 'datetime',
            'shipping_label_printed_at' => 'datetime',
            'shopee_income' => 'array',
            'shopee_escrow_amount' => 'decimal:2',
            'shopee_income_final' => 'boolean',
            'shopee_income_synced_at' => 'datetime',
            'tiktok_synced_at' => 'datetime',
            'tiktok_ship_by' => 'datetime',
            'tiktok_shipped_at' => 'datetime',
            'tiktok_payment' => 'array',
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

    public function tiktokShop(): BelongsTo
    {
        return $this->belongsTo(TiktokShop::class);
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

    public function isTiktok(): bool
    {
        return $this->tiktok_shop_id !== null;
    }

    /** Pesanan dari marketplace yang terhubung API (Shopee / TikTok). */
    public function isMarketplace(): bool
    {
        return $this->isShopee() || $this->isTiktok();
    }

    public function tiktokStatusLabel(): ?string
    {
        return $this->tiktok_order_status ? (self::TIKTOK_STATUSES[$this->tiktok_order_status]['label'] ?? $this->tiktok_order_status) : null;
    }

    /** TikTok sudah mengirim paket, tapi di Optima belum Dikirim. */
    public function tiktokAhead(): bool
    {
        return $this->isTiktok() && in_array($this->status, ['draft', ...self::RESERVING], true)
            && in_array($this->tiktok_order_status, self::TIKTOK_LEFT_WAREHOUSE, true);
    }

    /** Pengiriman pesanan TikTok sudah diatur di Seller Center (resi terbit / menunggu kurir). */
    public function tiktokShipmentArranged(): bool
    {
        return $this->isTiktok() && ($this->tiktok_order_status === 'AWAITING_COLLECTION'
            || in_array($this->tiktok_order_status, self::TIKTOK_LEFT_WAREHOUSE, true));
    }

    public function isSelfPickup(): bool
    {
        return $this->delivery_type === 'self_pickup';
    }

    public function deliveryTypeLabel(): string
    {
        return self::DELIVERY_TYPES[$this->delivery_type]['label'] ?? self::DELIVERY_TYPES['courier']['label'];
    }

    public function paymentMethodLabel(): ?string
    {
        return $this->payment_method ? (self::PAYMENT_METHODS[$this->payment_method] ?? $this->payment_method) : null;
    }

    public function paymentStatusLabel(): ?string
    {
        return $this->payment_status ? (self::PAYMENT_STATUSES[$this->payment_status]['label'] ?? $this->payment_status) : null;
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
        if (! $this->hasStatus(...self::RESERVING) || $this->isSelfPickup()) {
            return false;
        }

        // Pesanan TikTok diatur (pickup / drop off) di TikTok Shop Seller Center.
        if ($this->isTiktok()) {
            return false;
        }

        return ! $this->isShopee() || $this->shopee_order_status === 'READY_TO_SHIP';
    }

    /** Pesanan Shopee yang pengirimannya sudah diatur (di Optima atau Seller Centre). */
    public function shopeeStatusLabel(): ?string
    {
        return $this->shopee_order_status ? (self::SHOPEE_STATUSES[$this->shopee_order_status]['label'] ?? $this->shopee_order_status) : null;
    }

    /** Sudah diserahkan di gudang, tapi Shopee belum melaporkan paket di tangan kurir. */
    public function awaitingCourierScan(): bool
    {
        return $this->isShopee() && $this->status === 'shipped' && $this->shopee_shipped_at === null
            && in_array($this->shopee_order_status, self::SHOPEE_AWAITING_COURIER, true);
    }

    public function courierScanOverdue(): bool
    {
        return $this->awaitingCourierScan() && $this->shipped_at?->lte(now()->subHours((int) config('alerts.courier_scan_hours', 24)));
    }

    /** Shopee sudah mengirim paket, tapi di Optima belum Dikirim (pengiriman otomatis gagal). */
    public function shopeeAhead(): bool
    {
        return $this->isShopee() && in_array($this->status, ['draft', ...self::RESERVING], true)
            && in_array($this->shopee_order_status, self::SHOPEE_LEFT_WAREHOUSE, true);
    }

    /**
     * Filter daftar pesanan berdasarkan status Shopee atau selisih status Shopee vs gudang.
     *
     * @param  Builder<SalesOrder>  $query
     */
    public function scopeShopeeFilter(Builder $query, string $filter): void
    {
        match ($filter) {
            'courier_pending', 'courier_overdue' => $query->whereNotNull('shopee_shop_id')
                ->where('status', 'shipped')
                ->whereNull('shopee_shipped_at')
                ->whereIn('shopee_order_status', self::SHOPEE_AWAITING_COURIER)
                ->when($filter === 'courier_overdue', fn ($q) => $q->where('shipped_at', '<=', now()->subHours((int) config('alerts.courier_scan_hours', 24)))),
            'shopee_ahead' => $query->whereNotNull('shopee_shop_id')
                ->whereIn('status', ['draft', ...self::RESERVING])
                ->whereIn('shopee_order_status', self::SHOPEE_LEFT_WAREHOUSE),
            default => $query->where('shopee_order_status', $filter),
        };
    }

    public function shopeeShipmentArranged(): bool
    {
        return $this->isShopee() && filled($this->shopee_order_status) && ! in_array($this->shopee_order_status, ['UNPAID', 'READY_TO_SHIP', 'IN_CANCEL', 'CANCELLED'], true);
    }

    /** Label resi: Shopee setelah pengiriman diatur, manual setelah ada metode / resi. */
    public function hasShippingLabel(): bool
    {
        if ($this->hasStatus('draft', 'cancelled') || $this->isSelfPickup()) {
            return false;
        }

        // Label resi TikTok dicetak dari TikTok Shop Seller Center.
        if ($this->isTiktok()) {
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

    /** Subtotal barang dikurangi diskon, ditambah ongkir. */
    public function grandTotal(): float
    {
        return max(0, $this->subtotal() - (float) $this->discount) + (float) $this->shipping_fee;
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
