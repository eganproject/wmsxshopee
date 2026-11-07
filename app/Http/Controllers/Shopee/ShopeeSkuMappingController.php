<?php

namespace App\Http\Controllers\Shopee;

use App\Http\Controllers\Concerns\ResolvesSkuTarget;
use App\Http\Controllers\Controller;
use App\Jobs\RunMarketplaceTask;
use App\Models\Bundle;
use App\Models\ProductVariant;
use App\Models\ShopeeShop;
use App\Models\ShopeeSkuMapping;
use App\Services\Shopee\ShopeeCatalog;
use App\Support\SkuMappingExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ShopeeSkuMappingController extends Controller
{
    use ResolvesSkuTarget;

    public function index(Request $request, ShopeeShop $shop): View|BinaryFileResponse
    {
        $query = $shop->mappings()
            ->with(['variant.product:id,name', 'bundle:id,sku,name'])
            ->when($request->filled('search'), function (Builder $query) use ($request) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $request->string('search')->trim()).'%';
                $query->where(fn ($q) => $q->where('shopee_sku', 'like', $like)->orWhere('item_name', 'like', $like)
                    ->orWhere('model_name', 'like', $like)->orWhere('item_id', 'like', $like));
            })
            // SKU yang sudah tidak ada di toko hanya tampil di filter "Tidak ada di toko".
            ->when($request->input('status') === 'removed', fn ($q) => $q->whereNotNull('removed_at'), fn ($q) => $q->whereNull('removed_at'))
            ->when($request->input('status') === 'mapped', fn ($q) => $q->where(fn ($q) => $q->whereNotNull('product_variant_id')->orWhereNotNull('bundle_id')))
            ->when($request->input('status') === 'unmapped', fn ($q) => $q->whereNull('product_variant_id')->whereNull('bundle_id'))
            ->when($request->input('status') === 'failed', fn ($q) => $q->whereNotNull('stock_error'))
            ->when($request->input('status') === 'bundle_like', fn ($q) => $q->bundleLike())
            ->orderBy('item_name')->orderBy('model_name');

        $summary = [
            'total' => $shop->mappings()->whereNull('removed_at')->count(),
            'unmapped' => $shop->mappings()->whereNull('removed_at')->whereNull('product_variant_id')->whereNull('bundle_id')->count(),
            'failed' => $shop->mappings()->whereNull('removed_at')->whereNotNull('stock_error')->count(),
            'removed' => $shop->mappings()->whereNotNull('removed_at')->count(),
            'bundleLike' => $shop->mappings()->whereNull('removed_at')->bundleLike()->count(),
        ];

        // Ekspor Excel mengikuti pencarian & filter status yang sedang dipakai.
        if ($request->input('export') === 'xlsx') {
            return SkuMappingExport::download($request, $shop, $query, $summary);
        }

        return view('shopee.mappings.index', [
            'shop' => $shop,
            'mappings' => $query->paginate(25)->withQueryString(),
            ...$summary,
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
    public function pull(Request $request, ShopeeShop $shop): RedirectResponse
    {
        if (! $shop->isConnected()) {
            return back()->with('error', 'Toko perlu diotorisasi ulang sebelum produk bisa ditarik.');
        }

        // Lewat antrean: toko dengan ribuan SKU bisa lebih lama dari batas waktu nginx (504).
        return RunMarketplaceTask::start('pull', 'shopee', $shop, $request->user()->id)
            ? back()->with('success', RunMarketplaceTask::queuedMessage())
            : back()->with('warning', RunMarketplaceTask::busyMessage());
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
