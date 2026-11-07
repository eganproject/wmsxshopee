<?php

namespace App\Http\Controllers\Tiktok;

use App\Http\Controllers\Concerns\ResolvesSkuTarget;
use App\Http\Controllers\Controller;
use App\Jobs\RunMarketplaceTask;
use App\Models\TiktokShop;
use App\Models\TiktokSkuMapping;
use App\Services\Tiktok\TiktokCatalog;
use App\Support\SkuMappingExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TiktokSkuMappingController extends Controller
{
    use ResolvesSkuTarget;

    public function index(Request $request, TiktokShop $shop): View|BinaryFileResponse
    {
        $query = $shop->mappings()
            ->with(['variant.product:id,name', 'bundle:id,sku,name'])
            ->when($request->filled('search'), function (Builder $query) use ($request) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $request->string('search')->trim()).'%';
                $query->where(fn ($q) => $q->where('seller_sku', 'like', $like)->orWhere('product_name', 'like', $like)
                    ->orWhere('sku_name', 'like', $like)->orWhere('product_id', 'like', $like)->orWhere('sku_id', 'like', $like));
            })
            // SKU yang sudah tidak ada di toko hanya tampil di filter "Tidak ada di toko".
            ->when($request->input('status') === 'removed', fn ($q) => $q->whereNotNull('removed_at'), fn ($q) => $q->whereNull('removed_at'))
            ->when($request->input('status') === 'mapped', fn ($q) => $q->where(fn ($q) => $q->whereNotNull('product_variant_id')->orWhereNotNull('bundle_id')))
            ->when($request->input('status') === 'unmapped', fn ($q) => $q->whereNull('product_variant_id')->whereNull('bundle_id'))
            ->when($request->input('status') === 'failed', fn ($q) => $q->whereNotNull('stock_error'))
            ->when($request->input('status') === 'bundle_like', fn ($q) => $q->bundleLike())
            ->orderBy('product_name')->orderBy('sku_name');

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

        return view('tiktok.mappings.index', [
            'shop' => $shop,
            'mappings' => $query->paginate(25)->withQueryString(),
            ...$summary,
        ]);
    }

    /** Tambah baris manual (sebelum produk bisa ditarik dari TikTok, atau untuk koreksi). */
    public function store(Request $request, TiktokShop $shop): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'string', 'max:40', 'regex:/^\d+$/'],
            'sku_id' => ['required', 'string', 'max:40', 'regex:/^\d+$/', Rule::unique('tiktok_sku_mappings')->where('tiktok_shop_id', $shop->id)],
            'seller_sku' => ['nullable', 'string', 'max:100'],
            'product_name' => ['nullable', 'string', 'max:255'],
            'sku_name' => ['nullable', 'string', 'max:255'],
            'target' => ['nullable', 'string'],
        ], ['sku_id.unique' => 'SKU ID ini sudah ada di daftar pemetaan toko.'], [
            'product_id' => 'Product ID', 'sku_id' => 'SKU ID', 'seller_sku' => 'Seller SKU', 'product_name' => 'nama produk', 'sku_name' => 'nama variasi',
        ]);

        $mapping = $shop->mappings()->create([...collect($data)->except('target')->all(), ...$this->target($request->input('target'))]);

        if (! $mapping->isMapped()) {
            app(TiktokCatalog::class)->autoMatch($shop);
        }

        return back()->with('success', 'Baris pemetaan ditambahkan.'.$this->relinkOrders());
    }

    public function update(Request $request, TiktokShop $shop, TiktokSkuMapping $mapping): RedirectResponse
    {
        abort_unless($mapping->tiktok_shop_id === $shop->id, 404);

        $mapping->update($this->target($request->input('target')));

        return back()->with('success', $mapping->isMapped() ? 'Pemetaan SKU disimpan.'.$this->relinkOrders() : 'Pemetaan SKU dikosongkan.');
    }

    public function destroy(TiktokShop $shop, TiktokSkuMapping $mapping): RedirectResponse
    {
        abort_unless($mapping->tiktok_shop_id === $shop->id, 404);

        $mapping->delete();

        return back()->with('success', 'Baris pemetaan dihapus.');
    }

    public function autoMatch(TiktokShop $shop, TiktokCatalog $catalog): RedirectResponse
    {
        $matched = $catalog->autoMatch($shop);

        return back()->with('success', ($matched ? "{$matched} baris cocok otomatis berdasarkan SKU." : 'Tidak ada SKU baru yang cocok. Petakan sisanya secara manual.').$this->relinkOrders());
    }

    /** Tarik daftar produk & SKU dari TikTok lalu cocokkan otomatis. */
    public function pull(Request $request, TiktokShop $shop): RedirectResponse
    {
        if (! $shop->isConnected()) {
            return back()->with('error', 'Toko perlu diotorisasi ulang sebelum produk bisa ditarik.');
        }

        // Lewat antrean: toko dengan ribuan SKU bisa lebih lama dari batas waktu nginx (504).
        return RunMarketplaceTask::start('pull', 'tiktok', $shop, $request->user()->id)
            ? back()->with('success', RunMarketplaceTask::queuedMessage())
            : back()->with('warning', RunMarketplaceTask::busyMessage());
    }
}
