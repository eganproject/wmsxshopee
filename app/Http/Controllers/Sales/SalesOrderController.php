<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\SalesOrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SalesOrderController extends Controller
{
    public function index(Request $request): View
    {
        $orders = SalesOrder::query()
            ->with(['warehouse', 'courier'])
            ->withSum('items', 'qty')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->input('status')))
            ->when($request->filled('warehouse'), fn ($query) => $query->where('warehouse_id', $request->integer('warehouse')))
            ->when($request->filled('channel'), fn ($query) => $query->where('channel_name', $request->input('channel')))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('order_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('order_date', '<=', $request->date('to')))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search');
                $query->where(fn ($q) => $q
                    ->where('number', 'like', "%{$search}%")
                    ->orWhere('external_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('tracking_number', 'like', "%{$search}%"));
            })
            ->latest('order_date')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        $counts = SalesOrder::query()->groupBy('status')->selectRaw('status, COUNT(*) as total')->pluck('total', 'status');

        return view('sales.orders.index', [
            'orders' => $orders,
            'counts' => $counts,
            'warehouses' => Warehouse::orderBy('code')->get(),
            'channels' => $this->channels(),
        ]);
    }

    public function create(): View
    {
        return view('sales.orders.create', [
            'order' => new SalesOrder(['order_date' => now()]),
            ...$this->formData(),
        ]);
    }

    public function store(Request $request, SalesOrderService $service): RedirectResponse
    {
        $data = $this->validated($request);

        $order = DB::transaction(function () use ($request, $data) {
            $order = SalesOrder::create([
                ...$data['header'],
                'number' => SalesOrder::nextNumber(),
                'status' => 'draft',
                'created_by' => $request->user()->id,
            ]);
            $order->items()->createMany($data['items']);

            return $order;
        });

        return $this->afterSave($request, $order, $service, 'disimpan sebagai draft');
    }

    public function show(SalesOrder $order, SalesOrderService $service): View
    {
        $order->load(['warehouse', 'courier', 'creator', 'movement', 'items.variant.product.unit', 'items.picks.location']);

        $availability = $order->hasStatus('draft')
            ? $service->availability($order->warehouse_id, $order->items->pluck('product_variant_id')->all(), $order->id)
            : collect();

        return view('sales.orders.show', [
            'order' => $order,
            'availability' => $availability,
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

        $order->load('items');

        return view('sales.orders.edit', ['order' => $order, ...$this->formData($order)]);
    }

    public function update(Request $request, SalesOrder $order, SalesOrderService $service): RedirectResponse
    {
        abort_unless($order->isEditable(), 403, 'Hanya pesanan draft yang bisa diubah.');

        $data = $this->validated($request, $order);

        DB::transaction(function () use ($order, $data) {
            $order->update($data['header']);
            $order->items()->delete();
            $order->items()->createMany($data['items']);
        });

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

        $order->load(['warehouse', 'items.variant.product.unit']);
        $variantIds = $order->items->pluck('product_variant_id')->all();

        return view('sales.orders.picking', [
            'order' => $order,
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

    public function pickList(SalesOrder $order, SalesOrderService $service): View
    {
        abort_unless($order->hasStatus(...SalesOrder::RESERVING), 404);

        $order->load(['warehouse', 'courier', 'items.variant.product.unit', 'items.picks.location']);
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

    public function cancel(Request $request, SalesOrder $order, SalesOrderService $service): RedirectResponse
    {
        $data = $request->validate(['cancel_reason' => ['nullable', 'string', 'max:255']], [], ['cancel_reason' => 'alasan batal']);

        $service->cancel($order, $data['cancel_reason'] ?? null);

        return redirect()->route('sales.orders.show', $order)->with('success', "Pesanan {$order->number} dibatalkan. Cadangan stok dilepas.");
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
                ->map(fn ($variant) => ['id' => $variant->id, 'sku' => $variant->sku, 'barcode' => $variant->barcode, 'label' => $variant->displayName()])
                ->values(),
        ];
    }

    /**
     * @return array{header: array<string, mixed>, items: array<int, array<string, mixed>>}
     */
    private function validated(Request $request, ?SalesOrder $order = null): array
    {
        $active = fn (string $table, ?int $current) => Rule::exists($table, 'id')
            ->where(fn ($q) => $q->where('is_active', true)->when($current, fn ($q) => $q->orWhere('id', $current)));

        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', $active('warehouses', $order?->warehouse_id)],
            'order_date' => ['required', 'date', 'before_or_equal:today'],
            'channel_name' => ['nullable', 'string', 'max:100'],
            'external_number' => ['nullable', 'string', 'max:100'],
            'customer_name' => ['required', 'string', 'max:150'],
            'customer_phone' => ['nullable', 'string', 'max:30'],
            'shipping_address' => ['nullable', 'string', 'max:1000'],
            'courier_id' => ['nullable', 'integer', $active('couriers', $order?->courier_id)],
            'courier_service' => ['nullable', 'string', 'max:50'],
            'shipping_fee' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_variant_id' => ['required', 'integer', Rule::exists('product_variants', 'id')],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.price' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
        ], [
            'items.required' => 'Tambahkan minimal satu barang.',
            'order_date.before_or_equal' => 'Tanggal pesanan tidak boleh di masa depan.',
        ], [
            'warehouse_id' => 'gudang',
            'order_date' => 'tanggal pesanan',
            'channel_name' => 'saluran / toko',
            'external_number' => 'no. pesanan marketplace',
            'customer_name' => 'nama pembeli',
            'customer_phone' => 'telepon',
            'shipping_address' => 'alamat kirim',
            'courier_id' => 'kurir',
            'courier_service' => 'layanan',
            'shipping_fee' => 'ongkir',
            'notes' => 'catatan',
            'items.*.product_variant_id' => 'barang',
            'items.*.qty' => 'jumlah',
            'items.*.price' => 'harga',
            'items.*.notes' => 'keterangan',
        ]);

        $this->ensureUniqueExternal($data['channel_name'] ?? null, $data['external_number'] ?? null, $order?->id);

        return [
            'header' => [
                'warehouse_id' => (int) $data['warehouse_id'],
                'order_date' => $data['order_date'],
                'channel_name' => filled($data['channel_name'] ?? null) ? trim($data['channel_name']) : null,
                'external_number' => filled($data['external_number'] ?? null) ? trim($data['external_number']) : null,
                'customer_name' => trim($data['customer_name']),
                'customer_phone' => $data['customer_phone'] ?? null,
                'shipping_address' => $data['shipping_address'] ?? null,
                'courier_id' => isset($data['courier_id']) ? (int) $data['courier_id'] : null,
                'courier_service' => $data['courier_service'] ?? null,
                'shipping_fee' => $data['shipping_fee'] ?? 0,
                'notes' => $data['notes'] ?? null,
            ],
            'items' => collect($data['items'])->map(fn ($row) => [
                'product_variant_id' => (int) $row['product_variant_id'],
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
