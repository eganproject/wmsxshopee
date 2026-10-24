<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use App\Models\Concerns\ScopedToWarehouse;
use App\Support\DocumentNumber;
use Carbon\CarbonInterface;
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
        'draft' => ['label' => 'Draft', 'icon' => 'heroicon-o-document', 'color' => 'slate',
            'hint' => 'Pesanan yang belum mencadangkan stok. Pesanan marketplace tertahan di sini bila SKU-nya belum terhubung ke produk atau stok gudang belum cukup.',
            'next' => 'Lengkapi SKU / stok, lalu konfirmasi pesanan.'],
        'confirmed' => ['label' => 'Dikonfirmasi', 'icon' => 'heroicon-o-check-badge', 'color' => 'sky',
            'hint' => 'Stok sudah dicadangkan dan pesanan siap dikerjakan gudang.',
            'next' => 'Atur pickup / drop off, cetak label & picking list, lalu tandai Sedang Diambil.'],
        'picking' => ['label' => 'Sedang Diambil', 'icon' => 'heroicon-o-hand-raised', 'color' => 'violet',
            'hint' => 'Picker sedang mengambil barang dari rak sesuai picking list.',
            'next' => 'Scan barang di QC Kemas, lalu kemas paketnya.'],
        'packed' => ['label' => 'Sudah Dikemas', 'icon' => 'heroicon-o-archive-box', 'color' => 'amber',
            'hint' => 'Barang sudah lolos QC dan paket sudah dikemas, menunggu diserahkan ke kurir.',
            'next' => 'Scan resi di Serah Terima saat kurir mengambil paket.'],
        'shipped' => ['label' => 'Dikirim', 'icon' => 'heroicon-o-truck', 'color' => 'emerald',
            'hint' => 'Paket sudah diserahkan ke kurir (atau diambil pembeli) dan stok gudang sudah berkurang.',
            'next' => 'Pantau kolom Status Marketplace sampai kurir scan dan pesanan selesai.'],
        'cancelled' => ['label' => 'Batal', 'icon' => 'heroicon-o-x-circle', 'color' => 'rose',
            'hint' => 'Pesanan dibatalkan dan cadangan stoknya sudah dilepas, termasuk pembatalan dari Shopee / TikTok.',
            'next' => null],
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

    /**
     * Status marketplace yang disamakan untuk Shopee & TikTok, supaya bisa difilter bersama.
     * Kode asli tetap disimpan di shopee_order_status / tiktok_order_status.
     */
    public const MARKETPLACE_STATUSES = [
        'unpaid' => ['label' => 'Belum Bayar', 'color' => 'slate', 'shopee' => ['UNPAID'], 'tiktok' => ['UNPAID'], 'hint' => 'Pembeli belum membayar di marketplace.'],
        'on_hold' => ['label' => 'Ditahan', 'color' => 'slate', 'shopee' => [], 'tiktok' => ['ON_HOLD'], 'hint' => 'Sudah dibayar, tapi TikTok masih menahan pesanan (masa pembeli bisa batal).'],
        'to_ship' => ['label' => 'Perlu Dikirim', 'color' => 'sky', 'shopee' => ['READY_TO_SHIP'], 'tiktok' => ['AWAITING_SHIPMENT', 'PARTIALLY_SHIPPING'], 'hint' => 'Marketplace menunggu penjual mengatur pengiriman (Shopee READY_TO_SHIP, TikTok AWAITING_SHIPMENT).'],
        'awaiting_courier' => ['label' => 'Menunggu Kurir', 'color' => 'violet', 'shopee' => ['PROCESSED', 'RETRY_SHIP'], 'tiktok' => ['AWAITING_COLLECTION'], 'hint' => 'Pengiriman sudah diatur dan resi terbit, menunggu kurir scan paket.'],
        'in_transit' => ['label' => 'Dalam Pengiriman', 'color' => 'emerald', 'shopee' => ['SHIPPED'], 'tiktok' => ['IN_TRANSIT'], 'hint' => 'Kurir sudah scan paket dan paket dalam perjalanan ke pembeli.'],
        'delivered' => ['label' => 'Sampai di Pembeli', 'color' => 'emerald', 'shopee' => ['TO_CONFIRM_RECEIVE'], 'tiktok' => ['DELIVERED'], 'hint' => 'Paket sudah sampai, menunggu pembeli konfirmasi terima.'],
        'completed' => ['label' => 'Selesai', 'color' => 'emerald', 'shopee' => ['COMPLETED'], 'tiktok' => ['COMPLETED'], 'hint' => 'Pesanan selesai di marketplace.'],
        'cancel_requested' => ['label' => 'Pengajuan Batal', 'color' => 'amber', 'shopee' => ['IN_CANCEL'], 'tiktok' => [], 'hint' => 'Pembeli mengajukan pembatalan. Jangan kemas atau kirim sampai ada keputusan.'],
        'cancelled' => ['label' => 'Batal', 'color' => 'rose', 'shopee' => ['CANCELLED'], 'tiktok' => ['CANCELLED'], 'hint' => 'Pesanan dibatalkan di marketplace.'],
        'returned' => ['label' => 'Retur', 'color' => 'rose', 'shopee' => ['TO_RETURN'], 'tiktok' => [], 'hint' => 'Pembeli mengajukan pengembalian barang.'],
    ];

    /** Status TikTok yang berarti pesanan harus disiapkan gudang. */
    public const TIKTOK_TO_FULFIL = ['AWAITING_SHIPMENT', 'PARTIALLY_SHIPPING', 'AWAITING_COLLECTION'];

    /** Status TikTok yang berarti paket sudah di tangan kurir. */
    public const TIKTOK_LEFT_WAREHOUSE = ['IN_TRANSIT', 'DELIVERED', 'COMPLETED'];

    /** Status yang menahan (mencadangkan) stok di gudang. */
    public const RESERVING = ['confirmed', 'picking', 'packed'];

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
    protected $hidden = ['shopee_income', 'shopee_escrow_amount', 'tiktok_payment', 'marketplace_lines'];

    protected $fillable = [
        'number',
        'status',
        'warehouse_id',
        'order_date',
        'ordered_at',
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
        'tiktok_cancel_requested',
        'tiktok_package_id',
        'tiktok_sync_error',
        'tiktok_synced_at',
        'tiktok_ship_by',
        'tiktok_shipped_at',
        'tiktok_payment',
        'marketplace_lines',
        'unmapped_skus',
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
        'picking_at',
        'packed_at',
        'shipped_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'ordered_at' => 'datetime',
            'shipping_fee' => 'decimal:2',
            'discount' => 'decimal:2',
            'confirmed_at' => 'datetime',
            'picking_at' => 'datetime',
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
            'tiktok_cancel_requested' => 'boolean',
            'tiktok_synced_at' => 'datetime',
            'tiktok_ship_by' => 'datetime',
            'tiktok_shipped_at' => 'datetime',
            'tiktok_payment' => 'array',
            'marketplace_lines' => 'array',
            'unmapped_skus' => 'array',
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
     * Pesanan TikTok selama statusnya AWAITING_SHIPMENT dan paketnya sudah dibuat.
     * Pesanan manual boleh diubah kapan saja sebelum dikirim.
     */
    public function canArrangeShipment(): bool
    {
        if (! $this->hasStatus(...self::RESERVING) || $this->isSelfPickup()) {
            return false;
        }

        if ($this->isTiktok()) {
            return $this->tiktok_order_status === 'AWAITING_SHIPMENT' && filled($this->tiktok_package_id) && ! $this->tiktok_cancel_requested;
        }

        return ! $this->isShopee() || $this->shopee_order_status === 'READY_TO_SHIP';
    }

    /** Pembeli mengajukan / sudah membatalkan di marketplace, tapi di Optima belum dibatalkan. */
    public function marketplaceCancelPending(): bool
    {
        if ($this->isShopee()) {
            return in_array($this->shopee_order_status, ['IN_CANCEL', 'CANCELLED'], true);
        }

        return $this->isTiktok() && ($this->tiktok_cancel_requested || $this->tiktok_order_status === 'CANCELLED');
    }

    /**
     * Bisa ditandai Sedang Diambil lewat aksi massal: status Dikonfirmasi dan tidak sedang
     * dibatalkan di marketplace. Stok per bin dicek ulang saat diproses.
     */
    public function canBulkPick(): bool
    {
        return $this->hasStatus('confirmed') && ! $this->marketplaceCancelPending();
    }

    /** Pengiriman sudah diatur, baik dari Optima maupun langsung di Seller Center marketplace. */
    public function shipmentAlreadyArranged(): bool
    {
        return $this->isShipmentArranged() || $this->shopeeShipmentArranged() || $this->tiktokShipmentArranged();
    }

    /**
     * Bisa diatur lewat tombol Atur Pickup / Drop Off massal di daftar pesanan:
     * hanya pesanan marketplace yang pengirimannya belum diatur sama sekali.
     */
    public function canBulkArrangeShipment(): bool
    {
        return $this->isMarketplace() && $this->canArrangeShipment() && ! $this->shipmentAlreadyArranged();
    }

    /** Batas kirim dari marketplace, selama pesanan belum dikirim / batal. */
    public function shipByDeadline(): ?CarbonInterface
    {
        if (! $this->hasStatus('draft', ...self::RESERVING)) {
            return null;
        }

        return $this->shopee_ship_by ?? $this->tiktok_ship_by;
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

    /**
     * Kunci status marketplace terpadu (lihat MARKETPLACE_STATUSES) dari kode Shopee / TikTok.
     * Pengajuan batal TikTok disimpan sebagai flag terpisah, jadi diutamakan di sini.
     */
    public static function marketplaceStatusKey(?string $shopee, ?string $tiktok, bool $tiktokCancelRequested = false): ?string
    {
        if ($tiktok && $tiktokCancelRequested && $tiktok !== 'CANCELLED') {
            return 'cancel_requested';
        }

        foreach (self::MARKETPLACE_STATUSES as $key => $meta) {
            if (($shopee && in_array($shopee, $meta['shopee'], true)) || ($tiktok && in_array($tiktok, $meta['tiktok'], true))) {
                return $key;
            }
        }

        return null;
    }

    public function marketplaceStatus(): ?string
    {
        return self::marketplaceStatusKey($this->shopee_order_status, $this->tiktok_order_status, (bool) $this->tiktok_cancel_requested);
    }

    public function marketplaceStatusLabel(): ?string
    {
        return ($key = $this->marketplaceStatus()) ? self::MARKETPLACE_STATUSES[$key]['label'] : $this->marketplaceRawStatus();
    }

    /** Kode status asli dari marketplace (READY_TO_SHIP, AWAITING_COLLECTION, ...). */
    public function marketplaceRawStatus(): ?string
    {
        return $this->shopee_order_status ?? $this->tiktok_order_status;
    }

    /** Waktu pesanan dibuat: dari marketplace bila ada, selain itu saat dicatat di Optima. */
    public function createdTime(): CarbonInterface
    {
        return $this->ordered_at ?? $this->created_at;
    }

    /** Waktu selesai proses gudang: saat diserahkan ke kurir / pembeli. Kosong sebelum serah terima. */
    public function completedTime(): ?CarbonInterface
    {
        return $this->hasStatus('shipped') ? $this->shipped_at : null;
    }

    public function marketplacePlatform(): ?string
    {
        return $this->isShopee() ? 'shopee' : ($this->isTiktok() ? 'tiktok' : null);
    }

    public function marketplaceSyncedAt(): ?CarbonInterface
    {
        return $this->isShopee() ? $this->shopee_synced_at : ($this->isTiktok() ? $this->tiktok_synced_at : null);
    }

    /**
     * Filter status marketplace terpadu, berlaku untuk pesanan Shopee dan TikTok sekaligus.
     *
     * @param  Builder<SalesOrder>  $query
     */
    public function scopeWhereMarketplaceStatus(Builder $query, string $key): void
    {
        $meta = self::MARKETPLACE_STATUSES[$key] ?? null;
        if (! $meta) {
            return;
        }

        $query->where(function ($q) use ($key, $meta) {
            $q->whereRaw('1 = 0');
            if ($meta['shopee']) {
                $q->orWhere(fn ($s) => $s->whereNotNull('shopee_shop_id')->whereIn('shopee_order_status', $meta['shopee']));
            }
            if ($key === 'cancel_requested') {
                $q->orWhere(fn ($t) => $t->whereNotNull('tiktok_shop_id')->where('tiktok_cancel_requested', true)->where('tiktok_order_status', '!=', 'CANCELLED'));
            } elseif ($meta['tiktok']) {
                $q->orWhere(fn ($t) => $t->whereNotNull('tiktok_shop_id')->whereIn('tiktok_order_status', $meta['tiktok'])
                    ->when($key !== 'cancelled', fn ($t) => $t->where('tiktok_cancel_requested', false)));
            }
        });
    }

    /** Sudah diserahkan di gudang, tapi kurir TikTok belum scan paket. */
    public function tiktokAwaitingCourierScan(): bool
    {
        return $this->isTiktok() && $this->status === 'shipped' && $this->tiktok_shipped_at === null
            && in_array($this->tiktok_order_status, ['AWAITING_SHIPMENT', 'AWAITING_COLLECTION'], true);
    }

    /**
     * Alasan status gudang dan status marketplace tidak cocok, atau null bila sinkron.
     * Kondisinya sama dengan scopeOutOfSync().
     */
    public function syncIssue(): ?string
    {
        if (! $this->isMarketplace()) {
            return null;
        }

        $platform = $this->isShopee() ? 'Shopee' : 'TikTok';
        $open = in_array($this->status, ['draft', ...self::RESERVING], true);
        $key = $this->marketplaceStatus();

        if ($open && $key === 'cancel_requested') {
            return 'Pembeli minta batal, jangan dikemas';
        }
        if ($open && $key === 'cancelled') {
            return "Batal di {$platform}, belum dibatalkan di gudang";
        }
        if ($this->shopeeAhead() || $this->tiktokAhead()) {
            return "Sudah dikirim di {$platform}, belum di gudang";
        }
        if ($this->status === 'cancelled' && in_array($key, ['to_ship', 'awaiting_courier'], true)) {
            return "Batal di gudang, masih aktif di {$platform}";
        }
        $overdue = now()->subHours((int) config('alerts.courier_scan_hours', 24));
        if (($this->awaitingCourierScan() || $this->tiktokAwaitingCourierScan()) && $this->shipped_at?->lte($overdue)) {
            return 'Sudah serah terima, belum discan kurir > '.config('alerts.courier_scan_hours', 24).' jam';
        }

        return null;
    }

    /**
     * Pesanan yang status gudang dan status marketplace-nya bertentangan.
     *
     * @param  Builder<SalesOrder>  $query
     */
    public function scopeOutOfSync(Builder $query): void
    {
        $open = ['draft', ...self::RESERVING];
        $overdue = now()->subHours((int) config('alerts.courier_scan_hours', 24));
        $active = [...self::MARKETPLACE_STATUSES['to_ship']['shopee'], ...self::MARKETPLACE_STATUSES['awaiting_courier']['shopee']];
        $tiktokActive = [...self::MARKETPLACE_STATUSES['to_ship']['tiktok'], ...self::MARKETPLACE_STATUSES['awaiting_courier']['tiktok']];

        $query->where(fn ($q) => $q
            // Batal / pengajuan batal / sudah dikirim di marketplace, gudang masih memproses.
            ->orWhere(fn ($o) => $o->whereIn('status', $open)->where(fn ($m) => $m
                ->orWhere(fn ($s) => $s->whereNotNull('shopee_shop_id')->whereIn('shopee_order_status', ['IN_CANCEL', 'CANCELLED', ...self::SHOPEE_LEFT_WAREHOUSE]))
                ->orWhere(fn ($t) => $t->whereNotNull('tiktok_shop_id')->where(fn ($x) => $x
                    ->where('tiktok_cancel_requested', true)
                    ->orWhereIn('tiktok_order_status', ['CANCELLED', ...self::TIKTOK_LEFT_WAREHOUSE])))))
            // Dibatalkan di gudang, tapi di marketplace masih harus dikirim.
            ->orWhere(fn ($o) => $o->where('status', 'cancelled')->where(fn ($m) => $m
                ->orWhere(fn ($s) => $s->whereNotNull('shopee_shop_id')->whereIn('shopee_order_status', $active))
                ->orWhere(fn ($t) => $t->whereNotNull('tiktok_shop_id')->whereIn('tiktok_order_status', $tiktokActive)->where('tiktok_cancel_requested', false))))
            // Sudah serah terima, kurir belum scan terlalu lama.
            ->orWhere(fn ($o) => $o->where('status', 'shipped')->where('shipped_at', '<=', $overdue)->where(fn ($m) => $m
                ->orWhere(fn ($s) => $s->whereNotNull('shopee_shop_id')->whereNull('shopee_shipped_at')->whereIn('shopee_order_status', self::SHOPEE_AWAITING_COURIER))
                ->orWhere(fn ($t) => $t->whereNotNull('tiktok_shop_id')->whereNull('tiktok_shipped_at')->whereIn('tiktok_order_status', ['AWAITING_SHIPMENT', 'AWAITING_COLLECTION'])))));
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

        // Label resi TikTok tersedia setelah pengiriman diatur (paket menunggu dijemput / diantar).
        if ($this->isTiktok()) {
            return $this->tiktokShipmentArranged() && filled($this->tiktok_package_id);
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
     * Nomor SO lain (bukan batal) yang sudah memakai nomor resi ini. Resi dipakai
     * untuk scan QC, serah terima & retur, jadi satu resi hanya boleh satu pesanan.
     * Dicek di semua gudang karena resi berlaku lintas gudang.
     */
    public static function trackingUsedBy(string $tracking, ?int $exceptId = null): ?string
    {
        return self::withoutGlobalScope('warehouse_access')
            ->where('tracking_number', trim($tracking))
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

        return DocumentNumber::next(self::withoutGlobalScope('warehouse_access'), $prefix, 3);
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
