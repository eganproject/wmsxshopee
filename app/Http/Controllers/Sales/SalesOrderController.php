<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Bundle;
use App\Models\Courier;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SavedFilter;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Rules\AccessibleRecord;
use App\Services\MarketplaceOrderRelink;
use App\Services\OrderSkuResolver;
use App\Services\SalesOrderService;
use App\Services\WarehouseSupply;
use App\Support\Activity;
use App\Support\ShopeeIncome;
use App\Support\XlsxExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SalesOrderController extends Controller
{
    /** Pilihan jumlah baris per halaman di daftar pesanan. */
    public const PER_PAGE = [20, 50, 100];

    /** Kolom pencarian daftar pesanan (seperti pilihan "No. Pesanan / Resi / SKU" di Ginee). */
    public const SEARCH_FIELDS = [
        '' => 'Semua kolom',
        'number' => 'No. pesanan',
        'tracking' => 'No. resi',
        'sku' => 'SKU',
        'customer' => 'Pembeli',
    ];

    /** Jenis waktu untuk filter tanggal. */
    public const DATE_FIELDS = [
        '' => 'Tanggal pesanan',
        'deadline' => 'Batas kirim',
        'shipped' => 'Tanggal dikirim',
    ];

    /** Urutan daftar pesanan. */
    public const SORTS = [
        '' => 'Terbaru',
        'oldest' => 'Terlama',
        'deadline' => 'Batas kirim terdekat',
    ];

    /** Batas jumlah nomor yang ditempel sekaligus di kotak cari. */
    private const MAX_SEARCH_TERMS = 500;

    public function index(Request $request): View|BinaryFileResponse|RedirectResponse
    {
        // Tautan lama sebelum status "Sudah Diambil" berganti nama jadi "Sedang Diambil".
        if ($request->input('status') === 'picked') {
            return redirect()->route('sales.orders.index', ['status' => 'picking', ...$request->except('status')]);
        }

        $query = $this->filtered($request)
            ->with(['warehouse', 'courier', 'looseItems.variant.product', 'bundles.bundle'])
            ->withSum('items', 'qty')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->input('status')));
        $this->sorted($query, (string) $request->input('sort'));

        if ($request->input('export') === 'xlsx') {
            // Kolom pendapatan Shopee hanya untuk user dengan hak sales.income.
            $income = $request->user()->can('sales.income');

            return XlsxExport::download('pesanan-'.now()->format('Ymd-His').'.xlsx',
                ['No. SO', 'Tanggal', 'Status Gudang', 'Status Marketplace', 'Kode Status Marketplace', 'Saluran / Toko', 'No. Referensi', 'Pembeli', 'Telepon', 'Pengiriman', 'Alamat', 'Gudang', 'Kurir', 'Layanan', 'No. Resi', 'Total Qty', 'Diskon', 'Ongkir', 'Metode Bayar', 'Status Bayar',
                    ...($income ? ['Biaya Shopee', 'Dana Diterima Shopee'] : []), 'Catatan'],
                $query->lazy(500)->map(fn ($o) => [
                    $o->number, $o->order_date->format('d/m/Y'), $o->statusLabel(), $o->marketplaceStatusLabel(), $o->marketplaceRawStatus(), $o->channel_name, $o->external_number, $o->customer_name, $o->customer_phone,
                    $o->deliveryTypeLabel(), $o->shipping_address, $o->warehouse?->code, $o->courier?->name, $o->courier_service, $o->tracking_number,
                    (int) $o->items_sum_qty, (float) $o->discount, (float) $o->shipping_fee, $o->paymentMethodLabel(), $o->paymentStatusLabel(),
                    ...($income ? [$o->shopee_income ? ShopeeIncome::fees($o->shopee_income) : null, $o->shopee_escrow_amount !== null ? (float) $o->shopee_escrow_amount : null] : []),
                    $o->notes,
                ]));
        }

        $perPage = in_array($request->integer('per_page'), self::PER_PAGE, true) ? $request->integer('per_page') : self::PER_PAGE[0];
        $orders = $query->paginate($perPage)->withQueryString();

        // Jumlah per tab mengikuti filter yang sedang dipakai (selain tab status itu sendiri).
        $counts = $this->filtered($request)->toBase()->groupBy('status')->selectRaw('status, COUNT(*) as total')->pluck('total', 'status');

        // Jumlah per chip status marketplace: ikut tab status gudang & filter lain, kecuali chip itu sendiri.
        $marketplaceBase = fn () => $this->filtered($request, false)
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->input('status')));
        $marketplaceCounts = collect();
        $marketplaceBase()->toBase()
            ->where(fn ($q) => $q->whereNotNull('shopee_order_status')->orWhereNotNull('tiktok_order_status'))
            ->groupBy('shopee_order_status', 'tiktok_order_status', 'tiktok_cancel_requested')
            ->selectRaw('shopee_order_status, tiktok_order_status, tiktok_cancel_requested, COUNT(*) as total')
            ->get()
            ->each(function ($row) use ($marketplaceCounts) {
                $key = SalesOrder::marketplaceStatusKey($row->shopee_order_status, $row->tiktok_order_status, (bool) $row->tiktok_cancel_requested);
                if ($key) {
                    $marketplaceCounts[$key] = ($marketplaceCounts[$key] ?? 0) + $row->total;
                }
            });

        return view('sales.orders.index', [
            'orders' => $orders,
            'counts' => $counts,
            'marketplaceCounts' => $marketplaceCounts,
            'outOfSyncCount' => $marketplaceBase()->outOfSync()->count(),
            // Data per baris untuk aksi massal: tombol hanya muncul untuk pesanan yang bisa diproses.
            'bulkRows' => $orders->getCollection()->mapWithKeys(fn (SalesOrder $order) => [$order->id => [
                'arrange' => $order->canBulkArrangeShipment(),
                'arranged' => $order->shipmentAlreadyArranged(),
                'manual' => ! $order->isMarketplace() && $order->canArrangeShipment(),
                'label' => $order->hasShippingLabel(),
                'pick' => $order->canBulkPick(),
            ]]),
            'warehouses' => Warehouse::orderBy('code')->get(),
            'shops' => $this->shopChips($request),
            'couriers' => $this->courierChips($request),
            'savedFilters' => SavedFilter::where('user_id', $request->user()->id)->where('page', OrderSavedFilterController::PAGE)->orderBy('name')->get(),
            'searchTerms' => count($this->searchTerms($request)),
        ]);
    }

    public function create(): View
    {
        return view('sales.orders.create', [
            'order' => new SalesOrder(['order_date' => now(), 'delivery_type' => 'courier', 'payment_status' => 'unpaid']),
            ...$this->formData(),
        ]);
    }

    public function store(Request $request, SalesOrderService $service): RedirectResponse
    {
        $data = $this->validated($request);

        $order = DB::transaction(function () use ($request, $data, $service) {
            $order = SalesOrder::create([
                ...$data['header'],
                'number' => SalesOrder::nextNumber(),
                'status' => 'draft',
                'created_by' => $request->user()->id,
            ]);
            $service->fillLines($order, $data['items'], $data['bundles']);

            return $order;
        });

        return $this->afterSave($request, $order, $service, 'disimpan sebagai draft');
    }

    public function show(SalesOrder $order, SalesOrderService $service, WarehouseSupply $supply, OrderSkuResolver $skus): View
    {
        $order->load(['warehouse', 'courier', 'creator', 'movement', 'arranger', 'qcChecker', 'handover', 'items.variant.product.unit', 'items.picks.location', 'bundles.bundle.items', 'returns.items']);

        $draft = $order->hasStatus('draft');

        return view('sales.orders.show', [
            'order' => $order,
            // Stok yang bisa dipesan: gudang ini + gudang cadangannya (bila ada).
            'availability' => $draft ? $supply->sellable($order->warehouse_id, $order->items->pluck('product_variant_id')->all(), $order->id)->map(fn ($row) => ['available' => $row['sellable']]) : collect(),
            'bundleAvailability' => $draft ? $supply->bundleSellable($order->warehouse_id, $order->bundles->pluck('bundle')->unique('id')) : collect(),
            // Pesanan yang diterima dari stok gudang cadangan: menunggu transfer sebelum picking.
            'shortage' => $order->hasStatus('confirmed') ? $service->shortage($order) : collect(),
            // SKU marketplace yang belum terhubung, dengan saran produk mirip.
            'unmappedSkus' => $draft ? $skus->unmapped($order) : collect(),
            'couriers' => Courier::query()
                ->where(fn ($q) => $q->where('is_active', true)->when($order->courier_id, fn ($q) => $q->orWhere('id', $order->courier_id)))
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function edit(SalesOrder $order): View|RedirectResponse
    {
        if (! $order->isEditable()) {
            return redirect()->route('sales.orders.show', $order)->with('error', "{$order->number} sudah {$order->statusLabel()} dan tidak bisa diubah.");
        }

        $order->load(['looseItems', 'bundles']);

        return view('sales.orders.edit', ['order' => $order, ...$this->formData($order)]);
    }

    public function update(Request $request, SalesOrder $order, SalesOrderService $service): RedirectResponse
    {
        abort_unless($order->isEditable(), 403, 'Hanya pesanan draft yang bisa diubah.');

        $data = $this->validated($request, $order);

        Activity::track($order, fn () => DB::transaction(function () use ($order, $data, $service) {
            // Diisi manual: tidak lagi dihubungkan ulang otomatis dari item marketplace.
            $order->update([...$data['header'], 'unmapped_skus' => null]);
            $service->fillLines($order, $data['items'], $data['bundles']);
        }));

        return $this->afterSave($request, $order, $service, 'diperbarui');
    }

    public function destroy(SalesOrder $order): RedirectResponse
    {
        if (! $order->isEditable()) {
            return back()->with('error', "{$order->number} bukan draft sehingga tidak bisa dihapus. Batalkan saja pesanannya.");
        }

        $order->delete();

        return redirect()->route('sales.orders.index')->with('success', "Draft pesanan {$order->number} berhasil dihapus.");
    }

    public function confirm(SalesOrder $order, SalesOrderService $service): RedirectResponse
    {
        $service->confirm($order);

        return redirect()->route('sales.orders.show', $order)->with('success', "Pesanan {$order->number} dikonfirmasi. Stok sudah dicadangkan.");
    }

    public function picking(SalesOrder $order, SalesOrderService $service): View|RedirectResponse
    {
        if (! $order->hasStatus('confirmed')) {
            return redirect()->route('sales.orders.show', $order)->with('error', "{$order->number} tidak dalam status Dikonfirmasi.");
        }

        $order->load(['warehouse', 'items.variant.product.unit', 'items.bundleLine.bundle']);
        $variantIds = $order->items->pluck('product_variant_id')->all();

        return view('sales.orders.picking', [
            'order' => $order,
            'shortage' => $service->shortage($order),
            'suggestion' => $service->suggestPicks($order),
            'slots' => $service->freeByLocation($order->warehouse_id, $variantIds, $order->id),
        ]);
    }

    public function pick(Request $request, SalesOrder $order, SalesOrderService $service): RedirectResponse
    {
        $data = $request->validate([
            'picks' => ['required', 'array'],
            'picks.*' => ['array'],
            'picks.*.*.warehouse_location_id' => ['nullable', 'integer', Rule::exists('warehouse_locations', 'id')->where('warehouse_id', $order->warehouse_id)],
            'picks.*.*.qty' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ], [], [
            'picks.*.*.warehouse_location_id' => 'lokasi bin',
            'picks.*.*.qty' => 'jumlah diambil',
        ]);

        $service->pick($order, collect($data['picks'])->map(fn ($rows) => collect($rows)->map(fn ($row) => [
            'warehouse_location_id' => $row['warehouse_location_id'] ?? null,
            'qty' => (int) ($row['qty'] ?? 0),
        ])->values()->all())->all());

        return redirect()->route('sales.orders.show', $order)->with('success', "Picking {$order->number} selesai. Lanjut kemas barangnya.");
    }

    /**
     * Tandai banyak pesanan Sedang Diambil dari daftar pesanan, memakai bin usulan
     * otomatis seperti di halaman Picking. Tiap pesanan diproses dalam transaksinya
     * sendiri: yang gagal (stok bin kurang, status berubah, dibatalkan di marketplace)
     * dilewati dan dilaporkan tanpa membatalkan pesanan lain.
     */
    public function bulkPick(Request $request, SalesOrderService $service): RedirectResponse
    {
        $data = $request->validate([
            'orders' => ['required', 'array', 'max:100'],
            'orders.*' => ['integer', 'distinct'],
        ], ['orders.required' => 'Pilih pesanan yang akan ditandai Sedang Diambil.', 'orders.max' => 'Maksimal :max pesanan sekali proses.']);

        // Scope gudang: pesanan di gudang yang tidak bisa diakses user tidak ikut terambil.
        $orders = SalesOrder::whereKey($data['orders'])->orderBy('id')->get();
        $picked = [];
        $skipped = [];
        $failed = [];

        foreach ($orders as $order) {
            if (! $order->hasStatus('confirmed')) {
                $skipped[] = $order->number;

                continue;
            }

            if ($order->marketplaceCancelPending()) {
                $failed[$order->number] = 'Pembeli mengajukan pembatalan di marketplace. Cek pesanan sebelum picking.';

                continue;
            }

            try {
                $service->pickSuggested($order);
                $picked[] = $order->number;
            } catch (ValidationException $e) {
                $failed[$order->number] = collect($e->errors())->flatten()->first();
            }
        }

        $messages = [];
        if ($picked) {
            $messages[] = count($picked).' pesanan ditandai Sedang Diambil. Lanjut kemas barangnya.';
        }
        if ($skipped) {
            $messages[] = count($skipped).' pesanan dilewati karena tidak berstatus Dikonfirmasi ('.implode(', ', $skipped).').';
        }
        if ($missing = count($data['orders']) - $orders->count()) {
            $messages[] = "{$missing} pesanan tidak ditemukan.";
        }

        $type = match (true) {
            (bool) $picked => 'success',
            ! $failed => 'info',
            default => 'error',
        };
        $redirect = back()->with($type, implode(' ', $messages) ?: count($failed).' pesanan belum bisa ditandai Sedang Diambil.');

        if ($failed) {
            $redirect->with('pick_failures', $failed);
        }

        return $redirect;
    }

    public function pickList(SalesOrder $order, SalesOrderService $service): View
    {
        abort_unless($order->hasStatus(...SalesOrder::RESERVING), 404);

        $order->load(['warehouse', 'courier', 'items.variant.product.unit', 'items.picks.location', 'items.bundleLine.bundle']);
        $suggestion = $order->hasStatus('confirmed') ? $service->suggestPicks($order) : null;
        $codes = $suggestion
            ? WarehouseLocation::whereIn('id', collect($suggestion)->flatten(1)->pluck('warehouse_location_id')->filter())->pluck('code', 'id')
            : collect();

        // Satu baris per (barang, bin), diurutkan per kode bin supaya jalur ambil efisien.
        $lines = $order->items->flatMap(function ($item) use ($suggestion, $codes) {
            $rows = $suggestion
                ? collect($suggestion[$item->id])->map(fn ($row) => ['code' => $codes[$row['warehouse_location_id']] ?? null, 'qty' => $row['qty']])
                : $item->picks->map(fn ($pick) => ['code' => $pick->location?->code, 'qty' => $pick->qty]);

            return $rows->map(fn ($row) => [...$row, 'item' => $item]);
        })->sortBy(fn ($line) => ($line['code'] === null ? 'ZZZZ' : $line['code']).'|'.$line['item']->variant->sku)->values();

        return view('sales.orders.pick-list', ['order' => $order, 'lines' => $lines]);
    }

    public function pack(SalesOrder $order, SalesOrderService $service): RedirectResponse
    {
        $service->pack($order);

        return redirect()->route('sales.orders.show', $order)->with('success', "Pesanan {$order->number} sudah dikemas. Isi kurir & resi untuk mengirim.");
    }

    public function ship(Request $request, SalesOrder $order, SalesOrderService $service): RedirectResponse
    {
        $data = $request->validate([
            'courier_id' => ['required', 'integer', Rule::exists('couriers', 'id')->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $order->courier_id))],
            'courier_service' => ['nullable', 'string', 'max:50'],
            'tracking_number' => ['required', 'string', 'max:100'],
        ], [], [
            'courier_id' => 'kurir',
            'courier_service' => 'layanan',
            'tracking_number' => 'nomor resi',
        ]);

        $service->ship($order, [
            'courier_id' => (int) $data['courier_id'],
            'courier_service' => $data['courier_service'] ?? null,
            'tracking_number' => trim($data['tracking_number']),
        ], $request->user()->id);

        $order->refresh()->load('movement');

        return redirect()->route('sales.orders.show', $order)
            ->with('success', "Pesanan {$order->number} dikirim. Stok keluar lewat {$order->movement->number}.");
    }

    /** Pesanan diambil pembeli: setelah dikemas langsung diserahkan, tanpa kurir dan resi. */
    public function handToCustomer(Request $request, SalesOrder $order, SalesOrderService $service): RedirectResponse
    {
        abort_unless($order->isSelfPickup(), 403, 'Hanya pesanan yang diambil pembeli.');

        $service->ship($order, [], $request->user()->id);

        $order->refresh()->load('movement');

        return redirect()->route('sales.orders.show', $order)
            ->with('success', "Pesanan {$order->number} diserahkan ke {$order->customer_name}. Stok keluar lewat {$order->movement->number}.");
    }

    public function cancel(Request $request, SalesOrder $order, SalesOrderService $service): RedirectResponse
    {
        $data = $request->validate(['cancel_reason' => ['nullable', 'string', 'max:255']], [], ['cancel_reason' => 'alasan batal']);

        $service->cancel($order, $data['cancel_reason'] ?? null);

        return redirect()->route('sales.orders.show', $order)->with('success', "Pesanan {$order->number} dibatalkan. Cadangan stok dilepas.");
    }

    /** Tombol "Sinkronkan ulang" untuk pesanan Shopee / TikTok. */
    public function resync(Request $request, SalesOrder $order, MarketplaceOrderRelink $relink): RedirectResponse
    {
        abort_unless($order->shopee_shop_id || $order->tiktok_shop_id, 404);

        // Dari tombol "Sinkronkan" di daftar: kembali ke daftar dengan filter yang sama.
        $back = fn () => $request->boolean('from_list') ? redirect()->back() : redirect()->route('sales.orders.show', $order);

        try {
            $relink->resync($order);
        } catch (ValidationException $e) {
            return $back()->with('error', collect($e->errors())->flatten()->first());
        }

        $order->refresh();
        $problem = $order->shopee_sync_error ?? $order->tiktok_sync_error;

        if ($order->hasStatus('draft')) {
            return $back()
                ->with('error', "{$order->number} sudah disinkron ulang, tapi masih draft".($problem ? ': '.$problem : '.'));
        }

        return $back()->with('success', "{$order->number} sudah disinkron ulang".($problem ? '. '.$problem : ' dan siap diproses gudang.'));
    }

    /** Riwayat operasi satu pesanan, dimuat ke panel samping di daftar pesanan. */
    public function history(SalesOrder $order): View
    {
        return view('sales.orders._history', ['order' => $order]);
    }

    public function reopen(SalesOrder $order, SalesOrderService $service): RedirectResponse
    {
        $service->reopen($order);

        return redirect()->route('sales.orders.edit', $order)->with('success', "{$order->number} dikembalikan ke draft. Cadangan stok dilepas sampai dikonfirmasi lagi.");
    }

    /** Tombol "Simpan & Konfirmasi" langsung mencadangkan stok setelah draft tersimpan. */
    private function afterSave(Request $request, SalesOrder $order, SalesOrderService $service, string $verb): RedirectResponse
    {
        if ($request->input('action') === 'confirm') {
            abort_unless($request->user()->can('sales.confirm'), 403);

            try {
                $service->confirm($order);
            } catch (ValidationException $e) {
                return redirect()->route('sales.orders.show', $order)
                    ->withErrors($e->errors())
                    ->with('error', "Pesanan {$order->number} tersimpan sebagai draft, tapi belum bisa dikonfirmasi.");
            }

            return redirect()->route('sales.orders.show', $order)->with('success', "Pesanan {$order->number} disimpan dan dikonfirmasi. Stok sudah dicadangkan.");
        }

        return redirect()->route('sales.orders.show', $order)->with('success', "Pesanan {$order->number} berhasil {$verb}.");
    }

    /** @return Collection<int, string> */
    /**
     * Filter daftar pesanan selain tab status: dipakai untuk daftar dan jumlah per tab.
     *
     * @return Builder<SalesOrder>
     */
    private function filtered(Request $request, bool $withMarketplaceStatus = true): Builder
    {
        $marketplace = (string) $request->input('mp');

        return SalesOrder::query()
            ->when($request->filled('warehouse'), fn ($query) => $query->where('warehouse_id', $request->integer('warehouse')))
            ->when($request->filled('channel'), fn ($query) => $query->where('channel_name', $request->input('channel')))
            ->when($request->input('platform') === 'shopee', fn ($query) => $query->whereNotNull('shopee_shop_id'))
            ->when($request->input('platform') === 'tiktok', fn ($query) => $query->whereNotNull('tiktok_shop_id'))
            ->when($request->input('platform') === 'manual', fn ($query) => $query->whereNull('shopee_shop_id')->whereNull('tiktok_shop_id'))
            ->when($request->input('courier') === 'none', fn ($query) => $query->whereNull('courier_id'))
            ->when(ctype_digit((string) $request->input('courier')), fn ($query) => $query->where('courier_id', $request->integer('courier')))
            ->tap(fn ($query) => $this->dateFilter($query, $request))
            ->when($request->input('shipment') === 'unarranged', fn ($query) => $query
                ->whereIn('status', SalesOrder::RESERVING)
                ->where('delivery_type', 'courier')
                ->whereNull('shipment_arranged_at')
                ->where(fn ($q) => $q->whereNull('shopee_shop_id')->orWhere('shopee_order_status', 'READY_TO_SHIP'))
                ->where(fn ($q) => $q->whereNull('tiktok_shop_id')->orWhere('tiktok_order_status', 'AWAITING_SHIPMENT')))
            ->when($request->filled('shopee'), fn ($query) => $query->shopeeFilter((string) $request->input('shopee')))
            ->when($withMarketplaceStatus && $marketplace === 'out_of_sync', fn ($query) => $query->outOfSync())
            ->when($withMarketplaceStatus && isset(SalesOrder::MARKETPLACE_STATUSES[$marketplace]), fn ($query) => $query->whereMarketplaceStatus($marketplace))
            ->when(in_array($request->input('shipment'), array_keys(SalesOrder::SHIPPING_METHODS), true), fn ($query) => $query->where('shipping_method', $request->input('shipment')))
            ->tap(fn ($query) => $this->searchFilter($query, $request));
    }

    /**
     * Isi kotak cari dipecah per baris / koma, supaya banyak nomor pesanan atau resi bisa ditempel sekaligus.
     * Pencarian pembeli hanya dipecah per baris dan koma karena nama memakai spasi.
     *
     * @return list<string>
     */
    private function searchTerms(Request $request): array
    {
        $raw = (string) $request->input('search');
        $field = (string) $request->input('search_by');
        $pattern = in_array($field, ['number', 'tracking', 'sku'], true) ? '/[\s,;]+/' : '/[\r\n,;]+/';

        return collect(preg_split($pattern, $raw) ?: [])
            ->map(fn ($term) => trim($term))
            ->filter(fn ($term) => $term !== '')
            ->unique()
            ->take(self::MAX_SEARCH_TERMS)
            ->values()
            ->all();
    }

    /** @param  Builder<SalesOrder>  $query */
    private function searchFilter(Builder $query, Request $request): void
    {
        $terms = $this->searchTerms($request);
        if ($terms === []) {
            return;
        }

        $field = (string) $request->input('search_by');
        $multiple = count($terms) > 1;

        // Banyak nomor sekaligus dicocokkan persis supaya hasilnya tepat nomor yang ditempel.
        match ($field) {
            'number' => $query->where(fn ($q) => $multiple
                ? $q->whereIn('number', $terms)->orWhereIn('external_number', $terms)
                : $q->where('number', 'like', "%{$terms[0]}%")->orWhere('external_number', 'like', "%{$terms[0]}%")),
            'tracking' => $multiple
                ? $query->whereIn('tracking_number', $terms)
                : $query->where('tracking_number', 'like', "%{$terms[0]}%"),
            'sku' => $query->where(fn ($q) => $q
                ->whereHas('items.variant', fn ($v) => $multiple ? $v->whereIn('sku', $terms) : $v->where('sku', 'like', "%{$terms[0]}%"))
                ->orWhereHas('bundles.bundle', fn ($b) => $multiple ? $b->whereIn('sku', $terms) : $b->where('sku', 'like', "%{$terms[0]}%"))),
            'customer' => $query->where(function ($q) use ($terms) {
                foreach ($terms as $term) {
                    $q->orWhere('customer_name', 'like', "%{$term}%");
                }
            }),
            default => $query->where(function ($q) use ($terms, $multiple) {
                if ($multiple) {
                    $q->whereIn('number', $terms)
                        ->orWhereIn('external_number', $terms)
                        ->orWhereIn('tracking_number', $terms)
                        ->orWhereHas('items.variant', fn ($v) => $v->whereIn('sku', $terms))
                        ->orWhereHas('bundles.bundle', fn ($b) => $b->whereIn('sku', $terms));

                    return;
                }

                $search = $terms[0];
                $q->where('number', 'like', "%{$search}%")
                    ->orWhere('external_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('tracking_number', 'like', "%{$search}%")
                    ->orWhereHas('items.variant', fn ($v) => $v->where('sku', 'like', "%{$search}%"))
                    ->orWhereHas('bundles.bundle', fn ($b) => $b->where('sku', 'like', "%{$search}%"));
            }),
        };
    }

    /** @param  Builder<SalesOrder>  $query */
    private function dateFilter(Builder $query, Request $request): void
    {
        $from = $request->filled('from') ? $request->date('from')?->toDateString() : null;
        $to = $request->filled('to') ? $request->date('to')?->toDateString() : null;
        if (! $from && ! $to) {
            return;
        }

        match ((string) $request->input('date_by')) {
            'deadline' => $query
                ->when($from, fn ($q) => $q->whereRaw('DATE(COALESCE(shopee_ship_by, tiktok_ship_by)) >= ?', [$from]))
                ->when($to, fn ($q) => $q->whereRaw('DATE(COALESCE(shopee_ship_by, tiktok_ship_by)) <= ?', [$to])),
            'shipped' => $query
                ->when($from, fn ($q) => $q->whereDate('shipped_at', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('shipped_at', '<=', $to)),
            default => $query
                ->when($from, fn ($q) => $q->whereDate('order_date', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('order_date', '<=', $to)),
        };
    }

    /** @param  Builder<SalesOrder>  $query */
    private function sorted(Builder $query, string $sort): void
    {
        match ($sort) {
            'oldest' => $query->oldest('order_date')->oldest('id'),
            // Yang paling mendesak di atas; pesanan tanpa batas kirim (manual / sudah lewat proses) di bawah.
            'deadline' => $query
                ->orderByRaw('COALESCE(shopee_ship_by, tiktok_ship_by) IS NULL')
                ->orderByRaw('COALESCE(shopee_ship_by, tiktok_ship_by)')
                ->latest('order_date')
                ->latest('id'),
            default => $query->latest('order_date')->latest('id'),
        };
    }

    /**
     * Chip toko di atas daftar: nama saluran beserta platformnya, mengikuti filter platform.
     *
     * @return Collection<int, array{name: string, platform: ?string}>
     */
    private function courierChips(Request $request): Collection
    {
        // Jumlah per kurir mengikuti semua filter lain (termasuk tab status), kecuali filter kurir itu sendiri.
        $others = $request->duplicate(collect($request->query())->except(['courier', 'page'])->all());
        $counts = $this->filtered($others)
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->input('status')))
            ->toBase()
            ->groupBy('courier_id')
            ->selectRaw('courier_id, COUNT(*) as total')
            ->pluck('total', 'courier_id');

        $selected = $request->integer('courier');
        $chips = Courier::query()
            ->whereIn('id', $counts->keys()->filter()->push($selected)->all())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Courier $courier) => ['value' => (string) $courier->id, 'name' => $courier->name, 'count' => (int) ($counts[$courier->id] ?? 0)]);

        // Pesanan tanpa kurir: ambil sendiri / belum diatur pengirimannya.
        if (($counts[''] ?? 0) || $request->input('courier') === 'none') {
            $chips->push(['value' => 'none', 'name' => 'Tanpa kurir', 'count' => (int) ($counts[''] ?? 0)]);
        }

        return $chips->values();
    }

    /**
     * @return Collection<int, array{name: string, platform: ?string}>
     */
    private function shopChips(Request $request): Collection
    {
        return SalesOrder::query()->toBase()
            ->whereNotNull('channel_name')
            ->when($request->input('platform') === 'shopee', fn ($query) => $query->whereNotNull('shopee_shop_id'))
            ->when($request->input('platform') === 'tiktok', fn ($query) => $query->whereNotNull('tiktok_shop_id'))
            ->when($request->input('platform') === 'manual', fn ($query) => $query->whereNull('shopee_shop_id')->whereNull('tiktok_shop_id'))
            ->groupBy('channel_name')
            ->selectRaw('channel_name, MAX(CASE WHEN shopee_shop_id IS NOT NULL THEN 1 ELSE 0 END) as shopee, MAX(CASE WHEN tiktok_shop_id IS NOT NULL THEN 1 ELSE 0 END) as tiktok')
            ->orderBy('channel_name')
            ->get()
            ->map(fn ($row) => ['name' => $row->channel_name, 'platform' => $row->shopee ? 'shopee' : ($row->tiktok ? 'tiktok' : null)]);
    }

    private function channels(): Collection
    {
        return SalesOrder::query()->whereNotNull('channel_name')->distinct()->orderBy('channel_name')->pluck('channel_name');
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(?SalesOrder $order = null): array
    {
        return [
            'warehouses' => Warehouse::query()
                ->where(fn ($q) => $q->where('is_active', true)->when($order, fn ($q) => $q->orWhere('id', $order->warehouse_id)))
                ->orderBy('code')
                ->get(),
            'couriers' => Courier::query()
                ->where(fn ($q) => $q->where('is_active', true)->when($order?->courier_id, fn ($q) => $q->orWhere('id', $order->courier_id)))
                ->orderBy('name')
                ->get(),
            'channels' => $this->channels(),
            'variants' => ProductVariant::query()
                ->with('product:id,name')
                ->where('is_active', true)
                ->whereHas('product', fn ($q) => $q->where('is_active', true))
                ->orderBy('sku')
                ->get()
                ->map(fn ($variant) => ['ref' => "v:{$variant->id}", 'sku' => $variant->sku, 'barcode' => $variant->barcode, 'label' => $variant->displayName(), 'price' => null])
                ->concat(Bundle::query()
                    ->where(fn ($q) => $q->where('is_active', true)->when($order, fn ($q) => $q->orWhereIn('id', $order->bundles()->select('bundle_id'))))
                    ->whereHas('items')
                    ->orderBy('sku')
                    ->get()
                    ->map(fn ($bundle) => ['ref' => "b:{$bundle->id}", 'sku' => $bundle->sku, 'barcode' => $bundle->barcode, 'label' => '[Paket] '.$bundle->displayName(), 'price' => (float) $bundle->price]))
                ->values(),
        ];
    }

    /**
     * Baris berisi product_variant_id (barang biasa) atau bundle_id (produk paket).
     *
     * @return array{header: array<string, mixed>, items: array<int, array<string, mixed>>, bundles: array<int, array<string, mixed>>}
     */
    private function validated(Request $request, ?SalesOrder $order = null): array
    {
        $active = fn (string $table, ?int $current) => Rule::exists($table, 'id')
            ->where(fn ($q) => $q->where('is_active', true)->when($current, fn ($q) => $q->orWhere('id', $current)));

        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', $active('warehouses', $order?->warehouse_id), new AccessibleRecord(Warehouse::class)],
            'order_date' => ['required', 'date', 'before_or_equal:today'],
            'channel_name' => ['nullable', 'string', 'max:100'],
            'external_number' => ['nullable', 'string', 'max:100'],
            'customer_name' => ['required', 'string', 'max:150'],
            'customer_phone' => ['nullable', 'string', 'max:30'],
            'delivery_type' => ['nullable', Rule::in(array_keys(SalesOrder::DELIVERY_TYPES))],
            'shipping_address' => ['nullable', 'string', 'max:1000'],
            'courier_id' => ['nullable', 'integer', $active('couriers', $order?->courier_id)],
            'courier_service' => ['nullable', 'string', 'max:50'],
            'shipping_fee' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'payment_method' => ['nullable', Rule::in(array_keys(SalesOrder::PAYMENT_METHODS))],
            'payment_status' => ['nullable', Rule::in(array_keys(SalesOrder::PAYMENT_STATUSES))],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_variant_id' => ['nullable', 'required_without:items.*.bundle_id', 'integer', Rule::exists('product_variants', 'id')],
            'items.*.bundle_id' => ['nullable', 'integer', Rule::exists('bundles', 'id')->where(fn ($q) => $q
                ->where('is_active', true)
                ->when($order, fn ($q) => $q->orWhereIn('id', $order->bundles()->select('bundle_id'))))],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.price' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
        ], [
            'items.required' => 'Tambahkan minimal satu barang.',
            'items.*.product_variant_id.required_without' => 'Pilih barang atau paket.',
            'order_date.before_or_equal' => 'Tanggal pesanan tidak boleh di masa depan.',
        ], [
            'warehouse_id' => 'gudang',
            'order_date' => 'tanggal pesanan',
            'channel_name' => 'saluran / toko',
            'external_number' => 'no. referensi',
            'delivery_type' => 'pengiriman',
            'discount' => 'diskon',
            'payment_method' => 'metode bayar',
            'payment_status' => 'status bayar',
            'customer_name' => 'nama pembeli',
            'customer_phone' => 'telepon',
            'shipping_address' => 'alamat kirim',
            'courier_id' => 'kurir',
            'courier_service' => 'layanan',
            'shipping_fee' => 'ongkir',
            'notes' => 'catatan',
            'items.*.product_variant_id' => 'barang',
            'items.*.bundle_id' => 'paket',
            'items.*.qty' => 'jumlah',
            'items.*.price' => 'harga',
            'items.*.notes' => 'keterangan',
        ]);

        $this->ensureUniqueExternal($data['channel_name'] ?? null, $data['external_number'] ?? null, $order?->id);

        // Diambil pembeli: tidak ada kurir maupun ongkir.
        $delivery = $data['delivery_type'] ?? 'courier';
        $pickup = $delivery === 'self_pickup';

        return [
            'header' => [
                'warehouse_id' => (int) $data['warehouse_id'],
                'order_date' => $data['order_date'],
                'channel_name' => filled($data['channel_name'] ?? null) ? trim($data['channel_name']) : null,
                'external_number' => filled($data['external_number'] ?? null) ? trim($data['external_number']) : null,
                'customer_name' => trim($data['customer_name']),
                'customer_phone' => $data['customer_phone'] ?? null,
                'delivery_type' => $delivery,
                'shipping_address' => $data['shipping_address'] ?? null,
                'courier_id' => ! $pickup && isset($data['courier_id']) ? (int) $data['courier_id'] : null,
                'courier_service' => $pickup ? null : ($data['courier_service'] ?? null),
                'shipping_fee' => $pickup ? 0 : ($data['shipping_fee'] ?? 0),
                'discount' => $data['discount'] ?? 0,
                'payment_method' => $data['payment_method'] ?? null,
                'payment_status' => $data['payment_status'] ?? null,
                'notes' => $data['notes'] ?? null,
            ],
            'items' => collect($data['items'])->filter(fn ($row) => blank($row['bundle_id'] ?? null))->map(fn ($row) => [
                'product_variant_id' => (int) $row['product_variant_id'],
                'qty' => (int) $row['qty'],
                'price' => $row['price'] ?? 0,
                'notes' => $row['notes'] ?? null,
            ])->values()->all(),
            'bundles' => collect($data['items'])->filter(fn ($row) => filled($row['bundle_id'] ?? null))->map(fn ($row) => [
                'bundle_id' => (int) $row['bundle_id'],
                'qty' => (int) $row['qty'],
                'price' => $row['price'] ?? 0,
                'notes' => $row['notes'] ?? null,
            ])->values()->all(),
        ];
    }

    /**
     * @throws ValidationException
     */
    private function ensureUniqueExternal(?string $channel, ?string $external, ?int $exceptId): void
    {
        if (blank($external)) {
            return;
        }

        $duplicate = SalesOrder::duplicateOf($channel, $external, $exceptId);

        if ($duplicate) {
            throw ValidationException::withMessages(['external_number' => "No. pesanan ini sudah dipakai di {$duplicate}."]);
        }
    }
}
