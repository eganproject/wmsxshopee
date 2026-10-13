<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Rules\AccessibleRecord;
use App\Services\StockService;
use App\Support\Activity;
use App\Support\XlsxExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SalesReturnController extends Controller
{
    public function index(Request $request): View|BinaryFileResponse
    {
        $query = SalesReturn::query()
            ->with(['order', 'warehouse'])
            ->withSum('items', 'qty_good')
            ->withSum('items', 'qty_damaged')
            ->withSum('items', 'qty_missing')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->input('status')))
            ->when($request->filled('source'), fn ($query) => $query->where('source', $request->input('source')))
            ->when($request->filled('warehouse'), fn ($query) => $query->where('warehouse_id', $request->integer('warehouse')))
            ->when($request->boolean('damaged'), fn ($query) => $query->whereHas('items', fn ($q) => $q->where('qty_damaged', '>', 0)))
            ->when($request->boolean('missing'), fn ($query) => $query->whereHas('items', fn ($q) => $q->where('qty_missing', '>', 0)))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('return_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('return_date', '<=', $request->date('to')))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search');
                $query->where(fn ($q) => $q
                    ->where('number', 'like', "%{$search}%")
                    ->orWhere('tracking_number', 'like', "%{$search}%")
                    ->orWhere('sender_name', 'like', "%{$search}%")
                    ->orWhere('external_number', 'like', "%{$search}%")
                    ->orWhereHas('order', fn ($o) => $o->where('number', 'like', "%{$search}%")->orWhere('tracking_number', 'like', "%{$search}%")));
            })
            ->latest('return_date')
            ->latest('id');

        if ($request->input('export') === 'xlsx') {
            return XlsxExport::download('retur-'.now()->format('Ymd-His').'.xlsx',
                ['No. Retur', 'Tanggal', 'Status', 'Sumber', 'No. SO', 'No. Resi', 'Pengirim', 'Saluran / Toko', 'No. Pesanan Marketplace', 'Gudang', 'Qty Layak Jual', 'Qty Rusak', 'Qty Hilang', 'Catatan'],
                $query->lazy(500)->map(fn ($r) => [
                    $r->number, $r->return_date->format('d/m/Y'), $r->statusLabel(), $r->sourceLabel(), $r->order?->number, $r->tracking_number, $r->sender_name,
                    $r->channel_name, $r->external_number, $r->warehouse?->code,
                    (int) $r->items_sum_qty_good, (int) $r->items_sum_qty_damaged, (int) $r->items_sum_qty_missing, $r->notes,
                ]));
        }

        $returns = $query->paginate(20)->withQueryString();

        return view('sales.returns.index', [
            'returns' => $returns,
            'warehouses' => Warehouse::orderBy('code')->get(),
        ]);
    }

    public function create(Request $request): View
    {
        // Dari tombol "Buat Retur" di pesanan, atau isian lama setelah validasi gagal.
        $orderId = $request->old('sales_order_id', $request->input('order'));
        $order = filled($orderId) ? SalesOrder::where('status', 'shipped')->find((int) $orderId) : null;

        return view('sales.returns.create', [
            'return' => new SalesReturn([
                'return_date' => now(),
                'source' => $request->input('source') === 'manual' ? 'manual' : 'order',
            ]),
            'orderPayload' => $order ? $this->orderPayload($order) : null,
            ...$this->formData(),
        ]);
    }

    public function store(Request $request, StockService $stock): RedirectResponse
    {
        $data = $this->validated($request);

        $return = DB::transaction(function () use ($request, $data) {
            $return = SalesReturn::create([
                ...$data['header'],
                'number' => SalesReturn::nextNumber(),
                'status' => 'draft',
                'created_by' => $request->user()->id,
            ]);

            $this->fillItems($request, $return, $data['items']);

            return $return;
        });

        return $this->afterSave($request, $return, $stock, 'disimpan sebagai draft');
    }

    public function show(SalesReturn $return): View
    {
        $return->load(['order', 'warehouse', 'creator', 'receiver', 'movement', 'items.variant.product.unit', 'items.location', 'items.orderItem.bundleLine.bundle']);

        return view('sales.returns.show', ['return' => $return]);
    }

    public function edit(Request $request, SalesReturn $return): View|RedirectResponse
    {
        if (! $return->isDraft()) {
            return redirect()->route('sales.returns.show', $return)->with('error', "{$return->number} sudah diterima dan tidak bisa diubah.");
        }

        $return->load(['items', 'order']);
        $orderId = $request->old('sales_order_id', $return->sales_order_id);
        $order = filled($orderId) ? SalesOrder::where('status', 'shipped')->find((int) $orderId) : null;

        return view('sales.returns.edit', [
            'return' => $return,
            'orderPayload' => $order ? $this->orderPayload($order, $return->id) : null,
            ...$this->formData($return),
        ]);
    }

    public function update(Request $request, SalesReturn $return, StockService $stock): RedirectResponse
    {
        abort_unless($return->isDraft(), 403, 'Retur yang sudah diterima tidak bisa diubah.');

        $data = $this->validated($request, $return);

        Activity::track($return, fn () => DB::transaction(function () use ($request, $return, $data) {
            $return->update($data['header']);
            $this->fillItems($request, $return, $data['items']);
        }));

        return $this->afterSave($request, $return, $stock, 'diperbarui');
    }

    public function receive(Request $request, SalesReturn $return, StockService $stock): RedirectResponse
    {
        $this->post($request, $return, $stock);

        return redirect()->route('sales.returns.show', $return)->with('success', $this->receivedMessage($return));
    }

    public function destroy(SalesReturn $return): RedirectResponse
    {
        if (! $return->isDraft()) {
            return back()->with('error', "{$return->number} sudah diterima sehingga tidak bisa dihapus.");
        }

        $photos = $return->items()->whereNotNull('photo_path')->pluck('photo_path');
        $return->delete();
        Storage::disk('public')->delete($photos->all());

        return redirect()->route('sales.returns.index')
            ->with('success', "Draft retur {$return->number} berhasil dihapus.");
    }

    /**
     * Cari pesanan dari scan resi, no. SO atau no. pesanan channel. Hanya pesanan
     * yang sudah dikirim yang bisa diretur.
     */
    public function lookup(Request $request): JsonResponse
    {
        $code = trim((string) $request->input('code'));

        if ($code === '') {
            return response()->json(['message' => 'Scan atau ketik no. resi, no. SO atau no. pesanan dulu.'], 422);
        }

        $matches = SalesOrder::query()
            ->where(fn ($q) => $q->where('tracking_number', $code)->orWhere('number', strtoupper($code))->orWhere('external_number', $code))
            ->latest('id')
            ->limit(10)
            ->get();

        if ($matches->isEmpty()) {
            return response()->json(['message' => "Tidak ada pesanan dengan resi / no. SO / no. pesanan {$code}. Untuk paket tanpa pesanan, pilih Retur tanpa pesanan."], 404);
        }

        $shipped = $matches->where('status', 'shipped')->values();

        if ($shipped->isEmpty()) {
            $order = $matches->first();

            return response()->json(['message' => "Pesanan {$order->number} masih berstatus {$order->statusLabel()}. Hanya pesanan yang sudah dikirim yang bisa diretur."], 422);
        }

        $except = $request->integer('except') ?: null;

        return response()->json([
            'matched_tracking' => $shipped->contains(fn ($order) => $order->tracking_number === $code),
            'orders' => $shipped->map(fn ($order) => $this->orderPayload($order, $except))->all(),
        ]);
    }

    /** Tombol "Simpan & Terima" langsung memposting stok setelah draft tersimpan. */
    private function afterSave(Request $request, SalesReturn $return, StockService $stock, string $verb): RedirectResponse
    {
        if ($request->input('action') === 'receive') {
            abort_unless($request->user()->can('returns.receive'), 403);

            try {
                $this->post($request, $return, $stock);
            } catch (ValidationException $e) {
                // Draft sudah tersimpan; kembalikan ke form edit dengan pesan kenapa belum bisa diterima.
                return redirect()->route('sales.returns.edit', $return)->withErrors($e->errors());
            }

            return redirect()->route('sales.returns.show', $return)->with('success', $this->receivedMessage($return));
        }

        return redirect()->route('sales.returns.show', $return)
            ->with('success', "Retur {$return->number} berhasil {$verb}.");
    }

    private function receivedMessage(SalesReturn $return): string
    {
        return $return->movement
            ? "Retur {$return->number} diterima. Barang layak jual masuk stok lewat {$return->movement->number}."
            : "Retur {$return->number} diterima. Tidak ada barang layak jual, jadi stok tidak bertambah.";
    }

    /**
     * Ubah status jadi Diterima dan catat Barang Masuk untuk qty layak jual.
     * Barang rusak hanya tercatat di dokumen retur.
     *
     * @throws ValidationException
     */
    private function post(Request $request, SalesReturn $return, StockService $stock): void
    {
        DB::transaction(function () use ($request, $return, $stock) {
            $return = SalesReturn::whereKey($return->id)->lockForUpdate()->firstOrFail();

            if (! $return->isDraft()) {
                throw ValidationException::withMessages(['return' => "{$return->number} sudah diterima sebelumnya."]);
            }

            $return->load(['order', 'items.location']);

            if ($return->items->isEmpty()) {
                throw ValidationException::withMessages(['items' => 'Belum ada barang di retur ini.']);
            }

            $lines = $return->items->where('qty_good', '>', 0);

            $inactive = $lines->first(fn ($item) => $item->location && (! $item->location->is_active || $item->location->warehouse_id !== $return->warehouse_id));
            if ($inactive) {
                throw ValidationException::withMessages(['items' => "Lokasi {$inactive->location->code} sudah nonaktif atau bukan di gudang ini. Ubah lokasinya dulu."]);
            }

            $movement = null;

            if ($lines->isNotEmpty()) {
                $movement = $stock->record([
                    'type' => 'in',
                    'warehouse_id' => $return->warehouse_id,
                    'movement_date' => $return->return_date->toDateString(),
                    'reference' => $return->number,
                    'notes' => trim("Retur dari {$return->sender_name}".($return->order ? " · {$return->order->number}" : '').($return->tracking_number ? " · resi {$return->tracking_number}" : '')),
                    'created_by' => $request->user()->id,
                ], $lines->map(fn ($item) => [
                    'product_variant_id' => $item->product_variant_id,
                    'to_location_id' => $item->warehouse_location_id,
                    'qty' => $item->qty_good,
                    'notes' => $item->reasonLabel(),
                ])->values()->all());
            }

            $return->update([
                'status' => 'received',
                'stock_movement_id' => $movement?->id,
                'received_by' => $request->user()->id,
                'received_at' => now(),
            ]);
        });

        $return->refresh()->load('movement');
    }

    /**
     * Ganti semua baris retur. Foto lama yang tetap dipakai dibawa ke baris baru,
     * foto yang dihapus/diganti dibuang dari storage setelah commit.
     *
     * @param  array<int, array<string, mixed>>  $items  indeks = indeks baris di form
     */
    private function fillItems(Request $request, SalesReturn $return, array $items): void
    {
        $oldPhotos = $return->items()->whereNotNull('photo_path')->pluck('photo_path');
        $return->items()->delete();

        $kept = [];

        foreach ($items as $index => $row) {
            if ($request->hasFile("items.{$index}.photo")) {
                $row['photo_path'] = $request->file("items.{$index}.photo")->store("returns/{$return->id}", 'public');
            } elseif ($row['photo_path'] && $oldPhotos->contains($row['photo_path'])) {
                $kept[] = $row['photo_path'];
            } else {
                $row['photo_path'] = null;
            }

            $return->items()->create($row);
        }

        $removed = $oldPhotos->diff($kept)->values()->all();
        if ($removed) {
            DB::afterCommit(fn () => Storage::disk('public')->delete($removed));
        }
    }

    /**
     * Data pesanan untuk form retur: barang yang dikirim beserta sisa yang masih
     * bisa diretur (dikurangi retur lain, draft maupun diterima).
     *
     * @return array<string, mixed>
     */
    private function orderPayload(SalesOrder $order, ?int $exceptReturnId = null): array
    {
        $order->loadMissing(['items.variant.product', 'items.bundleLine.bundle', 'returns']);
        $returned = SalesReturn::returnedQty($order->items->pluck('id')->all(), $exceptReturnId);

        return [
            'id' => $order->id,
            'number' => $order->number,
            'url' => route('sales.orders.show', $order),
            'customer_name' => $order->customer_name,
            'channel_name' => $order->channel_name,
            'external_number' => $order->external_number,
            'tracking_number' => $order->tracking_number,
            'warehouse_id' => $order->warehouse_id,
            'shipped_at' => $order->shipped_at?->format('d/m/Y'),
            'returns' => $order->returns->where('id', '!=', $exceptReturnId)->pluck('number')->values()->all(),
            'items' => $order->items->map(fn ($item) => [
                'id' => $item->id,
                'product_variant_id' => $item->product_variant_id,
                'sku' => $item->variant->sku,
                'barcode' => $item->variant->barcode,
                'label' => $item->variant->displayName(),
                'bundle' => $item->bundleLine ? $item->bundleLine->bundle->sku.' · '.$item->bundleLine->bundle->name : null,
                'shipped' => $item->qty,
                'returned' => $returned[$item->id] ?? 0,
                'remaining' => max($item->qty - ($returned[$item->id] ?? 0), 0),
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(?SalesReturn $return = null): array
    {
        $warehouses = Warehouse::query()
            ->where(fn ($q) => $q->where('is_active', true)->when($return, fn ($q) => $q->orWhere('id', $return->warehouse_id)))
            ->orderBy('code')
            ->get();

        return [
            'warehouses' => $warehouses,
            'reasons' => SalesReturn::REASONS,
            'locations' => WarehouseLocation::query()
                ->whereIn('warehouse_id', $warehouses->pluck('id'))
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'warehouse_id', 'code'])
                ->map(fn ($location) => ['id' => $location->id, 'warehouse_id' => $location->warehouse_id, 'code' => $location->code])
                ->values(),
            'variants' => ProductVariant::query()
                ->with('product:id,name')
                ->where(fn ($q) => $q->where('is_active', true)->when($return, fn ($q) => $q->orWhereIn('id', $return->items()->select('product_variant_id'))))
                ->orderBy('sku')
                ->get()
                ->map(fn ($variant) => ['id' => $variant->id, 'sku' => $variant->sku, 'barcode' => $variant->barcode, 'label' => $variant->displayName()])
                ->values(),
        ];
    }

    /**
     * @return array{header: array<string, mixed>, items: array<int, array<string, mixed>>}
     */
    private function validated(Request $request, ?SalesReturn $return = null): array
    {
        $warehouseId = $request->integer('warehouse_id');
        $fromOrder = $request->input('source', 'order') === 'order';

        $data = $request->validate([
            'source' => ['required', Rule::in(array_keys(SalesReturn::SOURCES))],
            'sales_order_id' => [Rule::requiredIf($fromOrder), 'nullable', 'integer', Rule::exists('sales_orders', 'id')->where('status', 'shipped'), new AccessibleRecord(SalesOrder::class)],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')
                ->where(fn ($q) => $q->where('is_active', true)->when($return, fn ($q) => $q->orWhere('id', $return->warehouse_id))), new AccessibleRecord(Warehouse::class)],
            'return_date' => ['required', 'date', 'before_or_equal:today'],
            'tracking_number' => ['nullable', 'string', 'max:100'],
            'sender_name' => [Rule::requiredIf(! $fromOrder), 'nullable', 'string', 'max:150'],
            'sender_phone' => ['nullable', 'string', 'max:30'],
            'channel_name' => ['nullable', 'string', 'max:100'],
            'external_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.sales_order_item_id' => [Rule::requiredIf($fromOrder), 'nullable', 'integer'],
            'items.*.product_variant_id' => [Rule::requiredIf(! $fromOrder), 'nullable', 'integer', Rule::exists('product_variants', 'id')],
            'items.*.qty_good' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'items.*.qty_damaged' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'items.*.qty_missing' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'items.*.warehouse_location_id' => ['nullable', 'integer', Rule::exists('warehouse_locations', 'id')->where('warehouse_id', $warehouseId)->where('is_active', true)],
            'items.*.reason' => ['nullable', Rule::in(array_keys(SalesReturn::REASONS))],
            'items.*.photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'items.*.photo_path' => ['nullable', 'string', 'max:255'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
        ], [
            'items.required' => 'Tambahkan minimal satu barang.',
            'sales_order_id.required' => 'Scan resi atau no. pesanan dulu untuk memilih pesanannya.',
            'sales_order_id.exists' => 'Pesanan tidak ditemukan atau belum dikirim.',
            'sender_name.required' => 'Isi nama pengirim retur.',
            'return_date.before_or_equal' => 'Tanggal retur tidak boleh di masa depan.',
            'items.*.warehouse_location_id.exists' => 'Lokasi bin harus lokasi aktif di gudang yang dipilih.',
            'items.*.photo.mimes' => 'Foto harus JPG, PNG atau WEBP.',
            'items.*.photo.max' => 'Foto maksimal 5 MB.',
        ], [
            'warehouse_id' => 'gudang',
            'return_date' => 'tanggal retur',
            'tracking_number' => 'no. resi retur',
            'sender_name' => 'nama pengirim',
            'sender_phone' => 'telepon pengirim',
            'channel_name' => 'channel',
            'external_number' => 'no. pesanan / referensi',
            'notes' => 'catatan',
            'items.*.product_variant_id' => 'barang',
            'items.*.qty_good' => 'jumlah layak jual',
            'items.*.qty_damaged' => 'jumlah rusak',
            'items.*.qty_missing' => 'jumlah hilang',
            'items.*.warehouse_location_id' => 'lokasi bin',
            'items.*.reason' => 'alasan',
            'items.*.photo' => 'foto',
            'items.*.notes' => 'keterangan',
        ]);

        $rows = collect($data['items'])->map(fn ($row) => [
            'sales_order_item_id' => filled($row['sales_order_item_id'] ?? null) ? (int) $row['sales_order_item_id'] : null,
            'product_variant_id' => filled($row['product_variant_id'] ?? null) ? (int) $row['product_variant_id'] : null,
            'qty_good' => (int) ($row['qty_good'] ?? 0),
            'qty_damaged' => (int) ($row['qty_damaged'] ?? 0),
            'qty_missing' => (int) ($row['qty_missing'] ?? 0),
            'warehouse_location_id' => filled($row['warehouse_location_id'] ?? null) ? (int) $row['warehouse_location_id'] : null,
            'reason' => $row['reason'] ?? null,
            'photo_path' => $row['photo_path'] ?? null,
            'notes' => $row['notes'] ?? null,
        ]);

        $order = $fromOrder ? SalesOrder::with('items')->findOrFail($data['sales_order_id']) : null;

        if ($order) {
            // Baris pesanan yang tidak diisi jumlahnya berarti barang itu tidak ikut kembali.
            $rows = $rows->filter(fn ($row) => $row['qty_good'] + $row['qty_damaged'] + $row['qty_missing'] > 0);

            if ($rows->isEmpty()) {
                throw ValidationException::withMessages(['items' => 'Isi jumlah layak jual, rusak atau hilang minimal untuk satu barang.']);
            }

            $orderItems = $order->items->keyBy('id');
            $returned = SalesReturn::returnedQty($orderItems->keys()->all(), $return?->id);
            $taken = [];

            foreach ($rows as $index => $row) {
                $orderItem = $orderItems->get($row['sales_order_item_id']);

                if (! $orderItem) {
                    throw ValidationException::withMessages(["items.{$index}.qty_good" => 'Barang ini bukan bagian dari pesanan '.$order->number.'.']);
                }

                $taken[$orderItem->id] = ($taken[$orderItem->id] ?? 0) + $row['qty_good'] + $row['qty_damaged'] + $row['qty_missing'];
                $remaining = $orderItem->qty - ($returned[$orderItem->id] ?? 0);

                if ($taken[$orderItem->id] > $remaining) {
                    throw ValidationException::withMessages(["items.{$index}.qty_good" => "Melebihi jumlah yang bisa diretur: dikirim {$orderItem->qty}".(($returned[$orderItem->id] ?? 0) ? ", sudah diretur {$returned[$orderItem->id]}" : '').'.']);
                }

                $rows[$index] = [...$row, 'product_variant_id' => $orderItem->product_variant_id];
            }
        } else {
            $empty = $rows->search(fn ($row) => $row['qty_good'] + $row['qty_damaged'] + $row['qty_missing'] === 0);
            if ($empty !== false) {
                throw ValidationException::withMessages(["items.{$empty}.qty_good" => 'Isi jumlah layak jual, rusak atau hilang.']);
            }

            $rows = $rows->map(fn ($row) => [...$row, 'sales_order_item_id' => null]);
        }

        // Baris yang isinya hanya barang hilang otomatis beralasan "Tidak ada di paket".
        $rows = $rows->map(fn ($row) => blank($row['reason']) && $row['qty_good'] + $row['qty_damaged'] === 0 ? [...$row, 'reason' => 'missing'] : $row);

        $noReason = $rows->search(fn ($row) => blank($row['reason']));
        if ($noReason !== false) {
            throw ValidationException::withMessages(["items.{$noReason}.reason" => 'Pilih alasan retur.']);
        }

        // Lokasi bin hanya berarti untuk barang layak jual yang masuk stok.
        $rows = $rows->map(fn ($row) => $row['qty_good'] > 0 ? $row : [...$row, 'warehouse_location_id' => null]);

        $header = [
            'source' => $fromOrder ? 'order' : 'manual',
            'sales_order_id' => $order?->id,
            'warehouse_id' => (int) $data['warehouse_id'],
            'return_date' => $data['return_date'],
            'tracking_number' => filled($data['tracking_number'] ?? null) ? trim($data['tracking_number']) : $order?->tracking_number,
            'notes' => $data['notes'] ?? null,
        ];

        // Retur dari pesanan memakai data pembeli dari pesanannya.
        $header += $order ? [
            'sender_name' => $order->customer_name,
            'sender_phone' => $order->customer_phone,
            'channel_name' => $order->channel_name,
            'external_number' => $order->external_number,
        ] : [
            'sender_name' => trim($data['sender_name']),
            'sender_phone' => $data['sender_phone'] ?? null,
            'channel_name' => $data['channel_name'] ?? null,
            'external_number' => $data['external_number'] ?? null,
        ];

        return ['header' => $header, 'items' => $rows->all()];
    }
}
