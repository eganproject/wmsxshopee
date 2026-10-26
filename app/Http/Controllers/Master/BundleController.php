<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\Bundle;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Services\BundleImporter;
use App\Services\SalesOrderService;
use App\Support\XlsxExport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Produk paket (bundling). Paket tidak punya stok sendiri: stok tersedianya
 * dihitung dari komponen di gudang yang dipilih.
 */
class BundleController extends Controller
{
    public function index(Request $request, SalesOrderService $service): View|BinaryFileResponse
    {
        $warehouses = Warehouse::where('is_active', true)->orderBy('code')->get();
        $warehouse = $warehouses->firstWhere('id', $request->integer('warehouse')) ?? $warehouses->first();

        $query = Bundle::query()
            ->with('items.variant.product')
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search');
                $query->where(fn ($q) => $q
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', $search)
                    ->orWhereHas('items.variant', fn ($q) => $q->where('sku', 'like', "%{$search}%")));
            })
            ->when($request->filled('status'), fn ($query) => $query->where('is_active', $request->input('status') === 'active'))
            ->orderBy('sku');

        if ($request->input('export') === 'xlsx') {
            // Kolom sama dengan template impor (satu baris per barang isi), jadi file ini bisa diedit lalu diimpor ulang.
            return XlsxExport::download('produk-paket-'.now()->format('Ymd-His').'.xlsx', array_keys(BundleImporter::COLUMNS),
                $query->lazy(200)->flatMap(fn (Bundle $b) => ($b->items->isEmpty() ? collect([null]) : $b->items)->map(fn ($item, $index) => [
                    $b->sku, $b->name, $b->barcode, (float) $b->price, $item?->variant?->sku, $item?->qty,
                    $b->is_active ? 'Ya' : 'Tidak', $b->description,
                ])));
        }

        $bundles = $query->paginate(15)->withQueryString();

        return view('master.bundles.index', [
            'bundles' => $bundles,
            'warehouses' => $warehouses,
            'warehouse' => $warehouse,
            'available' => $warehouse ? $service->bundleAvailability($warehouse->id, $bundles->getCollection()) : collect(),
        ]);
    }

    public function create(): View
    {
        return view('master.bundles.create', $this->formData(new Bundle(['is_active' => true])));
    }

    public function store(Request $request): RedirectResponse
    {
        $bundle = $this->save($request, new Bundle);

        return redirect()->route('master.bundles.index')
            ->with('success', "Paket {$bundle->name} berhasil ditambahkan.");
    }

    public function edit(Bundle $bundle): View
    {
        $bundle->load('items');

        return view('master.bundles.edit', $this->formData($bundle));
    }

    public function update(Request $request, Bundle $bundle): RedirectResponse
    {
        $this->save($request, $bundle);

        return redirect()->route('master.bundles.index')
            ->with('success', "Paket {$bundle->name} berhasil diperbarui.");
    }

    public function destroy(Bundle $bundle): RedirectResponse
    {
        if ($bundle->orderLines()->exists()) {
            return back()->with('error', "Paket {$bundle->name} sudah dipakai di pesanan sehingga tidak bisa dihapus. Nonaktifkan paket ini sebagai gantinya.");
        }

        $bundle->delete();

        return redirect()->route('master.bundles.index')
            ->with('success', "Paket {$bundle->name} berhasil dihapus.");
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Bundle $bundle): array
    {
        $current = $bundle->relationLoaded('items') ? $bundle->items->pluck('product_variant_id')->all() : [];

        return [
            'bundle' => $bundle,
            'variants' => ProductVariant::query()
                ->with('product:id,name,is_active')
                ->where(fn ($q) => $q
                    ->where(fn ($q) => $q->where('is_active', true)->whereHas('product', fn ($q) => $q->where('is_active', true)))
                    ->orWhereIn('id', $current))
                ->orderBy('sku')
                ->get()
                ->map(fn ($variant) => ['id' => $variant->id, 'label' => $variant->displayName()])
                ->values(),
        ];
    }

    private function save(Request $request, Bundle $bundle): Bundle
    {
        $request->merge([
            'sku' => strtoupper(trim((string) $request->input('sku'))),
            'barcode' => trim((string) $request->input('barcode')) ?: null,
            'items' => collect($request->input('items', []))->filter(fn ($row) => filled($row['product_variant_id'] ?? null) || filled($row['qty'] ?? null))->values()->all(),
        ]);

        $data = $request->validate([
            'sku' => ['required', 'string', 'alpha_dash', 'max:50', Rule::unique('bundles')->ignore($bundle), Rule::unique('product_variants', 'sku')],
            'name' => ['required', 'string', 'max:255'],
            'barcode' => ['nullable', 'string', 'alpha_dash', 'max:50', Rule::unique('bundles')->ignore($bundle), Rule::unique('product_variants', 'barcode')],
            'price' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_variant_id' => ['required', 'integer', 'distinct', Rule::exists('product_variants', 'id')],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:10000'],
        ], [
            'sku.unique' => 'SKU :input sudah dipakai paket atau varian produk lain.',
            'barcode.unique' => 'Barcode :input sudah dipakai paket atau varian produk lain.',
            'items.required' => 'Isi paket minimal satu barang.',
            'items.*.product_variant_id.distinct' => 'Barang yang sama tidak boleh dimasukkan dua kali; tambahkan jumlahnya saja.',
        ], [
            'sku' => 'SKU paket',
            'name' => 'nama paket',
            'barcode' => 'barcode',
            'price' => 'harga jual',
            'description' => 'deskripsi',
            'items.*.product_variant_id' => 'barang',
            'items.*.qty' => 'jumlah',
        ]);

        return DB::transaction(function () use ($bundle, $data) {
            $bundle->fill([
                'sku' => $data['sku'],
                'name' => trim($data['name']),
                'barcode' => $data['barcode'] ?? null,
                'price' => $data['price'] ?? 0,
                'description' => $data['description'] ?? null,
                'is_active' => $data['is_active'] ?? false,
            ])->save();

            // Isi paket di pesanan lama tersimpan sendiri, jadi aman diganti seluruhnya.
            $bundle->items()->delete();
            $bundle->items()->createMany(collect($data['items'])->values()->map(fn ($row, $index) => [
                'product_variant_id' => (int) $row['product_variant_id'],
                'qty' => (int) $row['qty'],
                'sort_order' => $index,
            ])->all());

            return $bundle;
        });
    }
}
