<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\BundleItem;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\StockLedger;
use App\Models\Unit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProductController extends Controller
{
    /** Batas gambar per produk, mengikuti batas foto produk di Shopee. */
    public const MAX_IMAGES = 9;

    public function index(Request $request): View
    {
        $products = Product::query()
            ->with(['unit', 'primaryImage'])
            ->withCount('variants')
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search');
                $query->where(fn ($q) => $q
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%")
                    // Cari juga SKU & barcode varian, supaya bisa langsung scan barcode.
                    ->orWhereHas('variants', fn ($v) => $v->where(fn ($w) => $w
                        ->where('sku', 'like', "%{$search}%")
                        ->orWhere('barcode', $search->toString()))));
            })
            ->when($request->filled('category'), fn ($query) => $query->where('category', $request->input('category')))
            ->when($request->filled('status'), fn ($query) => $query->where('is_active', $request->input('status') === 'active'))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('master.products.index', [
            'products' => $products,
            'categories' => $this->categories(),
        ]);
    }

    public function create(): View
    {
        $product = new Product(['is_active' => true, 'has_variants' => false]);
        $product->setRelation('variants', collect());
        $product->setRelation('images', collect());

        return view('master.products.create', $this->formData($product));
    }

    public function store(Request $request): RedirectResponse
    {
        $product = $this->save($request, new Product);

        return redirect()->route('master.products.index')
            ->with('success', "Produk {$product->name} berhasil ditambahkan.");
    }

    public function edit(Product $product): View
    {
        $product->load(['variants', 'images']);

        return view('master.products.edit', $this->formData($product));
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $this->save($request, $product);

        return redirect()->route('master.products.index')
            ->with('success', "Produk {$product->name} berhasil diperbarui.");
    }

    public function destroy(Product $product): RedirectResponse
    {
        if (StockLedger::whereIn('product_variant_id', $product->variants()->select('id'))->exists()) {
            return back()->with('error', "Produk {$product->name} sudah punya riwayat stok sehingga tidak bisa dihapus. Nonaktifkan produk ini sebagai gantinya.");
        }

        if (BundleItem::whereIn('product_variant_id', $product->variants()->select('id'))->exists()) {
            return back()->with('error', "Produk {$product->name} masih menjadi isi produk paket. Keluarkan dari paket dulu atau nonaktifkan produk ini.");
        }

        $paths = $product->images()->pluck('path')->all();

        $product->delete();
        Storage::disk('public')->delete($paths);

        return redirect()->route('master.products.index')
            ->with('success', "Produk {$product->name} berhasil dihapus.");
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Product $product): array
    {
        return [
            'product' => $product,
            'units' => Unit::query()
                ->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $product->unit_id))
                ->orderBy('code')
                ->get(),
            'categories' => $this->categories(),
            'maxImages' => self::MAX_IMAGES,
        ];
    }

    /**
     * @return Collection<int, string>
     */
    private function categories()
    {
        return Product::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category');
    }

    private function save(Request $request, Product $product): Product
    {
        $data = $this->validated($request, $product);

        return DB::transaction(function () use ($request, $product, $data) {
            $product->fill($data['product'])->save();

            $this->syncVariants($product, $data['variants']);
            $this->syncImages($request, $product, $data);

            return $product;
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function syncVariants(Product $product, array $rows): void
    {
        $keep = collect($rows)->pluck('id')->filter()->all();

        // Hapus varian yang dibuang dulu, supaya SKU/barcode-nya bisa dipakai ulang.
        ProductVariant::where('product_id', $product->id)->whereNotIn('id', $keep)->delete();

        foreach ($rows as $index => $row) {
            $attributes = [...collect($row)->except('id')->all(), 'sort_order' => $index];

            if ($row['id']) {
                ProductVariant::whereKey($row['id'])->update($attributes);
            } else {
                $product->variants()->create($attributes);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncImages(Request $request, Product $product, array $data): void
    {
        $removed = ProductImage::where('product_id', $product->id)->whereIn('id', $data['remove_images'])->get();
        $removed->each->delete();

        $primaryId = null;
        $nextOrder = (int) ProductImage::where('product_id', $product->id)->max('sort_order') + 1;

        foreach ($request->file('images', []) as $index => $file) {
            $image = $product->images()->create([
                'path' => $file->store("products/{$product->id}", 'public'),
                'sort_order' => $nextOrder++,
            ]);

            if ($data['primary_image'] === "new-{$index}") {
                $primaryId = $image->id;
            }
        }

        $images = $product->images()->get();

        if ($primaryId === null && is_numeric($data['primary_image'])) {
            $primaryId = $images->firstWhere('id', (int) $data['primary_image'])?->id;
        }

        $primaryId ??= $images->firstWhere('is_primary', true)?->id ?? $images->sortBy('sort_order')->first()?->id;

        if ($primaryId !== null) {
            ProductImage::where('product_id', $product->id)->update(['is_primary' => DB::raw("CASE WHEN id = {$primaryId} THEN 1 ELSE 0 END")]);
        }

        // File dihapus terakhir: kalau transaksi gagal di atas, gambar lama masih utuh.
        DB::afterCommit(fn () => Storage::disk('public')->delete($removed->pluck('path')->all()));
    }

    /**
     * @return array{product: array<string, mixed>, variants: array<int, array<string, mixed>>, remove_images: array<int, int>, primary_image: ?string}
     */
    private function validated(Request $request, Product $product): array
    {
        $sku = strtoupper(trim((string) $request->input('sku')));
        $hasVariants = $request->boolean('has_variants');

        $variants = collect($request->input('variants', []))->values();

        // Produk tanpa varian disimpan sebagai satu varian default ber-SKU sama.
        if (! $hasVariants) {
            $variants = $variants->take(1)->map(fn ($row) => [...$row, 'sku' => $sku, 'name' => null]);
        }

        $request->merge([
            'sku' => $sku,
            'variants' => $variants
                ->map(fn ($row) => [
                    ...$row,
                    'sku' => strtoupper(trim((string) ($row['sku'] ?? ''))),
                    'barcode' => trim((string) ($row['barcode'] ?? '')) ?: null,
                ])
                ->all(),
        ]);

        // SKU & barcode varian unik secara global, kecuali milik produk ini sendiri
        // (varian bisa ditukar urutan/diganti dalam satu kali simpan).
        $notThisProduct = fn ($column) => Rule::unique('product_variants', $column)
            ->where(fn ($q) => $q->where('product_id', '!=', $product->id ?? 0));

        $data = $request->validate([
            'sku' => ['required', 'string', 'alpha_dash', 'max:50', Rule::unique('products')->ignore($product)],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'brand' => ['nullable', 'string', 'max:100'],
            'unit_id' => ['required', Rule::exists('units', 'id')],
            'has_variants' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],

            'variants' => ['required', 'array', 'min:1', 'max:100'],
            'variants.*.id' => ['nullable', 'integer', Rule::exists('product_variants', 'id')->where('product_id', $product->id ?? 0)],
            'variants.*.sku' => ['required', 'string', 'alpha_dash', 'max:50', 'distinct', $notThisProduct('sku'), Rule::unique('bundles', 'sku')],
            'variants.*.name' => [$hasVariants ? 'required' : 'nullable', 'string', 'max:100', 'distinct'],
            'variants.*.barcode' => ['nullable', 'string', 'alpha_dash', 'max:50', 'distinct', $notThisProduct('barcode'), Rule::unique('bundles', 'barcode')],
            'variants.*.weight_gram' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'variants.*.length_cm' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'variants.*.width_cm' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'variants.*.height_cm' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'variants.*.is_active' => ['nullable', 'boolean'],

            'images' => ['array'],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_images' => ['array'],
            'remove_images.*' => ['integer'],
            'primary_image' => ['nullable', 'string', 'max:20'],
        ], [
            'variants.required' => 'Isi minimal satu varian.',
            'variants.*.sku.distinct' => 'SKU varian tidak boleh duplikat.',
            'variants.*.sku.unique' => 'SKU varian :input sudah dipakai produk atau paket lain.',
            'variants.*.name.distinct' => 'Nama varian tidak boleh duplikat.',
            'variants.*.barcode.distinct' => 'Barcode tidak boleh duplikat.',
            'variants.*.barcode.unique' => 'Barcode :input sudah dipakai produk atau paket lain.',
            'images.*.max' => 'Ukuran gambar maksimal 2 MB.',
        ], [
            'sku' => 'SKU induk',
            'name' => 'nama produk',
            'category' => 'kategori',
            'brand' => 'merek',
            'unit_id' => 'satuan',
            'description' => 'deskripsi',
            'notes' => 'catatan',
            'variants.*.sku' => 'SKU varian',
            'variants.*.name' => 'nama varian',
            'variants.*.barcode' => 'barcode',
            'variants.*.weight_gram' => 'berat',
            'variants.*.length_cm' => 'panjang',
            'variants.*.width_cm' => 'lebar',
            'variants.*.height_cm' => 'tinggi',
            'images.*' => 'gambar',
        ]);

        // Varian yang sudah punya riwayat stok tidak boleh dibuang dari produk.
        if ($product->exists) {
            $keepIds = collect($data['variants'])->pluck('id')->filter()->map(fn ($id) => (int) $id);
            $locked = $product->variants()->whereNotIn('id', $keepIds)->whereHas('ledgers')->pluck('sku');

            if ($locked->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'variants' => 'Varian '.$locked->join(', ').' sudah punya riwayat stok sehingga tidak bisa dihapus. Nonaktifkan saja.',
                ]);
            }

            $bundled = $product->variants()->whereNotIn('id', $keepIds)->whereHas('bundleItems')->pluck('sku');

            if ($bundled->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'variants' => 'Varian '.$bundled->join(', ').' masih menjadi isi produk paket. Keluarkan dari paket dulu atau nonaktifkan saja.',
                ]);
            }
        }

        $removeIds = collect($data['remove_images'] ?? [])->map(fn ($id) => (int) $id)->all();
        $remaining = $product->exists ? $product->images()->whereNotIn('id', $removeIds)->count() : 0;

        if ($remaining + count($request->file('images', [])) > self::MAX_IMAGES) {
            throw ValidationException::withMessages([
                'images' => 'Maksimal '.self::MAX_IMAGES.' gambar per produk.',
            ]);
        }

        return [
            'product' => [
                ...collect($data)->only(['sku', 'name', 'category', 'brand', 'unit_id', 'description', 'notes'])->all(),
                'has_variants' => $hasVariants,
                'is_active' => $data['is_active'] ?? false,
            ],
            'variants' => collect($data['variants'])
                ->map(fn ($row) => [
                    'id' => $row['id'] ?? null,
                    'sku' => $row['sku'],
                    'name' => $row['name'] ?? null,
                    'barcode' => $row['barcode'] ?? null,
                    'weight_gram' => $row['weight_gram'] ?? null,
                    'length_cm' => $row['length_cm'] ?? null,
                    'width_cm' => $row['width_cm'] ?? null,
                    'height_cm' => $row['height_cm'] ?? null,
                    // Varian produk tanpa varian selalu aktif; status ikut produk.
                    'is_active' => $hasVariants ? (bool) ($row['is_active'] ?? false) : true,
                ])
                ->all(),
            'remove_images' => $removeIds,
            'primary_image' => $data['primary_image'] ?? null,
        ];
    }
}
