<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockAdjustment;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Rules\AccessibleRecord;
use App\Services\StockAdjustmentService;
use App\Support\Activity;
use App\Support\XlsxExport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Penyesuaian Stok: draft dibuat & diajukan (adjustments.create), lalu disetujui
 * atau ditolak (adjustments.approve). Stok berubah hanya saat disetujui.
 */
class StockAdjustmentController extends Controller
{
    public function index(Request $request): View|BinaryFileResponse
    {
        $query = StockAdjustment::query()
            ->with(['warehouse', 'creator', 'approver', 'rejecter'])
            ->withCount('items')
            ->withSum(['items as qty_plus' => fn ($q) => $q->where('qty', '>', 0)], 'qty')
            ->withSum(['items as qty_minus' => fn ($q) => $q->where('qty', '<', 0)], 'qty')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->input('status')))
            ->when($request->filled('reason'), fn ($query) => $query->where('reason', $request->input('reason')))
            ->when($request->filled('warehouse'), fn ($query) => $query->where('warehouse_id', $request->integer('warehouse')))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('adjustment_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('adjustment_date', '<=', $request->date('to')))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search');
                $query->where(fn ($q) => $q->where('number', 'like', "%{$search}%")
                    ->orWhere('reference', 'like', "%{$search}%")
                    ->orWhereHas('items.variant', fn ($v) => $v->where('sku', 'like', "%{$search}%")));
            })
            ->latest('adjustment_date')
            ->latest('id');

        if ($request->input('export') === 'xlsx') {
            return XlsxExport::download('penyesuaian-stok-'.now()->format('Ymd-His').'.xlsx',
                ['No. Dokumen', 'Tanggal', 'Status', 'Gudang', 'Alasan', 'Referensi', 'Jumlah Baris', 'Qty Tambah', 'Qty Kurang', 'Dibuat Oleh', 'Diputuskan Oleh', 'Catatan', 'Alasan Ditolak'],
                $query->lazy(500)->map(fn ($a) => [
                    $a->number, $a->adjustment_date->format('d/m/Y'), $a->statusLabel(), $a->warehouse?->code, $a->reasonLabel(), $a->reference,
                    $a->items_count, (int) $a->qty_plus, (int) $a->qty_minus, $a->creator?->name, ($a->approver ?? $a->rejecter)?->name, $a->notes, $a->rejection_reason,
                ]));
        }

        $counts = StockAdjustment::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return view('inventory.adjustments.index', [
            'adjustments' => $query->paginate(20)->withQueryString(),
            'warehouses' => Warehouse::orderBy('code')->get(),
            'counts' => $counts,
        ]);
    }

    public function create(Request $request): View
    {
        return view('inventory.adjustments.form', [
            ...$this->formData(),
            'adjustment' => null,
            'lines' => [],
            'prefill' => ['warehouse' => $request->query('warehouse'), 'variant' => $request->query('variant')],
        ]);
    }

    public function store(Request $request, StockAdjustmentService $service): RedirectResponse
    {
        [$header, $items] = $this->validated($request);
        $submit = $request->input('action') === 'submit';

        $adjustment = $service->draft([...$header, 'created_by' => $request->user()->id], $items, $submit);

        return redirect()->route('inventory.adjustments.show', $adjustment)->with('success', $submit
            ? "Penyesuaian {$adjustment->number} diajukan dan menunggu approval."
            : "Draft penyesuaian {$adjustment->number} disimpan.");
    }

    public function show(StockAdjustment $adjustment, StockAdjustmentService $service): View
    {
        $adjustment->load(['warehouse', 'creator', 'submitter', 'approver', 'rejecter', 'movement', 'items.variant.product.unit', 'items.location']);

        // Saldo saat ini untuk dokumen yang belum diputuskan, supaya penyetuju melihat hasilnya.
        $current = in_array($adjustment->status, ['draft', 'pending'], true)
            ? $adjustment->items->mapWithKeys(fn ($item) => [$item->id => $service->currentQty($adjustment->warehouse_id, $item->product_variant_id, $item->warehouse_location_id)])
            : collect();

        return view('inventory.adjustments.show', ['adjustment' => $adjustment, 'current' => $current]);
    }

    public function edit(StockAdjustment $adjustment): View|RedirectResponse
    {
        if (! $adjustment->isDraft()) {
            return redirect()->route('inventory.adjustments.show', $adjustment)
                ->with('error', "{$adjustment->number} berstatus {$adjustment->statusLabel()} dan tidak bisa diubah.");
        }

        $adjustment->load('items');

        return view('inventory.adjustments.form', [
            ...$this->formData($adjustment),
            'adjustment' => $adjustment,
            'lines' => $adjustment->items->map(fn ($item) => [
                'product_variant_id' => (string) $item->product_variant_id,
                'warehouse_location_id' => (string) ($item->warehouse_location_id ?? ''),
                'qty' => (string) $item->qty,
                'notes' => $item->notes ?? '',
            ])->all(),
            'prefill' => ['warehouse' => $adjustment->warehouse_id, 'variant' => null],
        ]);
    }

    public function update(Request $request, StockAdjustment $adjustment, StockAdjustmentService $service): RedirectResponse
    {
        [$header, $items] = $this->validated($request);

        try {
            Activity::track($adjustment, fn () => $service->updateDraft($adjustment, $header, $items));
            if ($request->input('action') === 'submit') {
                $service->submit($adjustment, $request->user()->id);
            }
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('inventory.adjustments.show', $adjustment));
        }

        return redirect()->route('inventory.adjustments.show', $adjustment)->with('success', $request->input('action') === 'submit'
            ? "Penyesuaian {$adjustment->number} diajukan dan menunggu approval."
            : "Draft penyesuaian {$adjustment->number} diperbarui.");
    }

    public function submit(Request $request, StockAdjustment $adjustment, StockAdjustmentService $service): RedirectResponse
    {
        $this->attempt($adjustment, fn () => $service->submit($adjustment, $request->user()->id));

        return redirect()->route('inventory.adjustments.show', $adjustment)->with('success', "Penyesuaian {$adjustment->number} diajukan dan menunggu approval.");
    }

    public function recall(StockAdjustment $adjustment, StockAdjustmentService $service): RedirectResponse
    {
        $this->attempt($adjustment, fn () => $service->recall($adjustment));

        return redirect()->route('inventory.adjustments.show', $adjustment)->with('success', "Pengajuan {$adjustment->number} ditarik kembali ke draft.");
    }

    public function approve(Request $request, StockAdjustment $adjustment, StockAdjustmentService $service): RedirectResponse
    {
        $this->attempt($adjustment, fn () => $service->approve($adjustment, $request->user()->id));
        $adjustment->refresh()->load('movement');

        return redirect()->route('inventory.adjustments.show', $adjustment)
            ->with('success', "Penyesuaian {$adjustment->number} disetujui. Stok sudah diperbarui (mutasi {$adjustment->movement?->number}).");
    }

    public function reject(Request $request, StockAdjustment $adjustment, StockAdjustmentService $service): RedirectResponse
    {
        $data = $request->validate(
            ['rejection_reason' => ['required', 'string', 'max:500']],
            ['rejection_reason.required' => 'Isi alasan penolakan.'],
            ['rejection_reason' => 'alasan penolakan'],
        );

        $this->attempt($adjustment, fn () => $service->reject($adjustment, $request->user()->id, $data['rejection_reason']));

        return redirect()->route('inventory.adjustments.show', $adjustment)->with('success', "Penyesuaian {$adjustment->number} ditolak. Stok tidak berubah.");
    }

    public function destroy(StockAdjustment $adjustment): RedirectResponse
    {
        if (! $adjustment->isDraft()) {
            return back()->with('error', "{$adjustment->number} berstatus {$adjustment->statusLabel()} sehingga tidak bisa dihapus.");
        }

        $adjustment->delete();

        return redirect()->route('inventory.adjustments.index')->with('success', "Draft {$adjustment->number} dihapus.");
    }

    /** Jalankan aksi status; pesan gagal (status berubah / stok kurang) kembali ke halaman detail. */
    private function attempt(StockAdjustment $adjustment, callable $action): void
    {
        try {
            $action();
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('inventory.adjustments.show', $adjustment));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(?StockAdjustment $adjustment = null): array
    {
        $warehouses = Warehouse::query()
            ->where(fn ($q) => $q->where('is_active', true)->when($adjustment, fn ($q) => $q->orWhere('id', $adjustment->warehouse_id)))
            ->orderBy('code')
            ->get();

        return [
            'warehouses' => $warehouses,
            'reasons' => StockAdjustment::REASONS,
            'locations' => WarehouseLocation::query()
                ->whereIn('warehouse_id', $warehouses->pluck('id'))
                ->orderBy('code')
                ->get(['id', 'warehouse_id', 'code', 'is_active'])
                ->map(fn ($location) => ['id' => $location->id, 'warehouse_id' => $location->warehouse_id, 'code' => $location->code, 'active' => $location->is_active])
                ->values(),
            'variants' => ProductVariant::query()
                ->with('product:id,name')
                ->where('is_active', true)
                ->whereHas('product', fn ($q) => $q->where('is_active', true))
                ->orderBy('sku')
                ->get()
                ->map(fn ($variant) => ['id' => $variant->id, 'sku' => $variant->sku, 'barcode' => $variant->barcode, 'label' => $variant->displayName()])
                ->values(),
            // Saldo per gudang|varian|lokasi, untuk info "stok sistem" di form.
            'balances' => Stock::query()
                ->whereIn('warehouse_id', $warehouses->pluck('id'))
                ->where('qty', '!=', 0)
                ->get(['warehouse_id', 'product_variant_id', 'warehouse_location_id', 'qty'])
                ->mapWithKeys(fn ($s) => ["{$s->warehouse_id}|{$s->product_variant_id}|".($s->warehouse_location_id ?? '') => (int) $s->qty]),
        ];
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<int, array<string, mixed>>} [header, baris]
     */
    private function validated(Request $request): array
    {
        $warehouseId = $request->integer('warehouse_id');
        $location = Rule::exists('warehouse_locations', 'id')->where('warehouse_id', $warehouseId);

        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true), new AccessibleRecord(Warehouse::class)],
            'adjustment_date' => ['required', 'date', 'before_or_equal:today'],
            'reason' => ['required', Rule::in(array_keys(StockAdjustment::REASONS))],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000', 'required_if:reason,other'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_variant_id' => ['required', 'integer', Rule::exists('product_variants', 'id')],
            // Penyesuaian minus boleh di lokasi nonaktif (mengosongkan); yang plus dicek di bawah.
            'items.*.warehouse_location_id' => ['nullable', 'integer', $location],
            'items.*.qty' => ['required', 'integer', 'between:-1000000,1000000', 'not_in:0'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
        ], [
            'items.required' => 'Tambahkan minimal satu barang.',
            'adjustment_date.before_or_equal' => 'Tanggal penyesuaian tidak boleh di masa depan.',
            'notes.required_if' => 'Jelaskan alasannya di catatan bila memilih Lainnya.',
            'items.*.warehouse_location_id.exists' => 'Lokasi harus berada di gudang yang dipilih.',
            'items.*.qty.not_in' => 'Jumlah penyesuaian tidak boleh 0.',
        ], [
            'warehouse_id' => 'gudang',
            'adjustment_date' => 'tanggal',
            'reason' => 'alasan',
            'reference' => 'referensi',
            'notes' => 'catatan',
            'items.*.product_variant_id' => 'barang',
            'items.*.warehouse_location_id' => 'lokasi',
            'items.*.qty' => 'jumlah',
            'items.*.notes' => 'keterangan',
        ]);

        $items = collect($data['items'])->values();
        $inactive = WarehouseLocation::whereIn('id', $items->pluck('warehouse_location_id')->filter())->where('is_active', false)->pluck('id')->all();
        $seen = [];

        foreach ($items as $index => $row) {
            $locationId = filled($row['warehouse_location_id'] ?? null) ? (int) $row['warehouse_location_id'] : null;

            if ((int) $row['qty'] > 0 && in_array($locationId, $inactive, true)) {
                throw ValidationException::withMessages(["items.{$index}.warehouse_location_id" => 'Penyesuaian plus harus ke lokasi aktif. Lokasi nonaktif hanya bisa dikurangi.']);
            }

            $key = $row['product_variant_id'].':'.($locationId ?? 'none');
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(["items.{$index}.product_variant_id" => 'Barang & lokasi ini sudah ada di baris '.($seen[$key] + 1).'. Gabungkan jumlahnya.']);
            }
            $seen[$key] = $index;
        }

        $header = [
            'warehouse_id' => (int) $data['warehouse_id'],
            'adjustment_date' => $data['adjustment_date'],
            'reason' => $data['reason'],
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];

        return [$header, $items->map(fn ($row) => [
            'product_variant_id' => (int) $row['product_variant_id'],
            'warehouse_location_id' => filled($row['warehouse_location_id'] ?? null) ? (int) $row['warehouse_location_id'] : null,
            'qty' => (int) $row['qty'],
            'notes' => $row['notes'] ?? null,
        ])->all()];
    }
}
