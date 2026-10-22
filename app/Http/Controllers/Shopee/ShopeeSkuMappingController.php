<?php

namespace App\Http\Controllers\Shopee;

use App\Http\Controllers\Concerns\ResolvesSkuTarget;
use App\Http\Controllers\Controller;
use App\Models\Bundle;
use App\Models\ProductVariant;
use App\Models\ShopeeShop;
use App\Models\ShopeeSkuMapping;
use App\Services\Shopee\ShopeeCatalog;
use App\Services\Shopee\ShopeeException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ShopeeSkuMappingController extends Controller
{
    use ResolvesSkuTarget;

    public function index(Request $request, ShopeeShop $shop): View
    {
        $mappings = $shop->mappings()
            ->with(['variant.product:id,name', 'bundle:id,sku,name'])
            ->when($request->filled('search'), function (Builder $query) use ($request) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $request->string('search')->trim()).'%';
                $query->where(fn ($q) => $q->where('shopee_sku', 'like', $like)->orWhere('item_name', 'like', $like)
                    ->orWhere('model_name', 'like', $like)->orWhere('item_id', 'like', $like));
            })
            ->when($request->input('status') === 'mapped', fn ($q) => $q->where(fn ($q) => $q->whereNotNull('product_variant_id')->orWhereNotNull('bundle_id')))
            ->when($request->input('status') === 'unmapped', fn ($q) => $q->whereNull('product_variant_id')->whereNull('bundle_id'))
            ->orderBy('item_name')->orderBy('model_name')
            ->paginate(25)->withQueryString();

        return view('shopee.mappings.index', [
            'shop' => $shop,
            'mappings' => $mappings,
            'total' => $shop->mappings()->count(),
            'unmapped' => $shop->mappings()->whereNull('product_variant_id')->whereNull('bundle_id')->count(),
        ]);
    }

    /** Tambah baris manual (sebelum bisa ditarik dari Shopee, atau untuk koreksi). */
    public function store(Request $request, ShopeeShop $shop): RedirectResponse
    {
        $data = $request->validate([
            'item_id' => ['required', 'integer', 'min:1'],
            'model_id' => ['nullable', 'integer', 'min:0'],
            'shopee_sku' => ['nullable', 'string', 'max:100'],
            'item_name' => ['nullable', 'string', 'max:255'],
            'model_name' => ['nullable', 'string', 'max:255'],
            'target' => ['nullable', 'string'],
        ], [], ['item_id' => 'Item ID', 'model_id' => 'Model ID', 'shopee_sku' => 'SKU Shopee', 'item_name' => 'nama produk', 'model_name' => 'nama variasi']);

        $data['model_id'] = (int) ($data['model_id'] ?? 0);

        $request->validate([
            'item_id' => [Rule::unique('shopee_sku_mappings')->where('shopee_shop_id', $shop->id)->where('model_id', $data['model_id'])],
        ], ['item_id.unique' => 'Item ID & Model ID ini sudah ada di daftar pemetaan toko.']);

        $mapping = $shop->mappings()->create([...collect($data)->except('target')->all(), ...$this->target($request->input('target'))]);

        if (! $mapping->isMapped()) {
            app(ShopeeCatalog::class)->autoMatch($shop);
        }

        return back()->with('success', 'Baris pemetaan ditambahkan.'.$this->relinkOrders());
    }

    public function update(Request $request, ShopeeShop $shop, ShopeeSkuMapping $mapping): RedirectResponse
    {
        abort_unless($mapping->shopee_shop_id === $shop->id, 404);

        $mapping->update($this->target($request->input('target')));

        return back()->with('success', $mapping->isMapped() ? 'Pemetaan SKU disimpan.'.$this->relinkOrders() : 'Pemetaan SKU dikosongkan.');
    }

    public function destroy(ShopeeShop $shop, ShopeeSkuMapping $mapping): RedirectResponse
    {
        abort_unless($mapping->shopee_shop_id === $shop->id, 404);

        $mapping->delete();

        return back()->with('success', 'Baris pemetaan dihapus.');
    }

    public function autoMatch(ShopeeShop $shop, ShopeeCatalog $catalog): RedirectResponse
    {
        $matched = $catalog->autoMatch($shop);

        return back()->with('success', ($matched ? "{$matched} baris cocok otomatis berdasarkan SKU." : 'Tidak ada SKU baru yang cocok. Petakan sisanya secara manual.').$this->relinkOrders());
    }

    /** Tarik daftar produk & variasi dari Shopee lalu cocokkan otomatis. */
    public function pull(ShopeeShop $shop, ShopeeCatalog $catalog): RedirectResponse
    {
        try {
            $result = $catalog->pull($shop);
        } catch (ShopeeException $e) {
            return back()->with('error', 'Gagal menarik produk dari Shopee. '.$e->getMessage());
        }

        return back()->with('success', "{$result['items']} produk ({$result['rows']} baris SKU) ditarik dari Shopee, {$result['matched']} cocok otomatis.".$this->relinkOrders());
    }

    /** Pilihan target pemetaan untuk Tom Select: varian gudang & paket. */
    public function lookup(Request $request): JsonResponse
    {
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($request->string('q')->toString())).'%';

        $variants = ProductVariant::with('product:id,name')
            ->where(fn (Builder $q) => $q->where('sku', 'like', $like)->orWhere('name', 'like', $like)
                ->orWhereHas('product', fn (Builder $q) => $q->where('name', 'like', $like)))
            ->orderBy('sku')->limit(20)->get()
            ->map(fn (ProductVariant $v) => ['value' => 'variant:'.$v->id, 'text' => $v->displayName()]);

        $bundles = Bundle::where(fn (Builder $q) => $q->where('sku', 'like', $like)->orWhere('name', 'like', $like))
            ->orderBy('sku')->limit(10)->get()
            ->map(fn (Bundle $b) => ['value' => 'bundle:'.$b->id, 'text' => 'Paket · '.$b->displayName()]);

        return response()->json($variants->concat($bundles)->values());
    }
}
