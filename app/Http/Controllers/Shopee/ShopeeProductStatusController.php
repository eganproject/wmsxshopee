<?php

namespace App\Http\Controllers\Shopee;

use App\Http\Controllers\Controller;
use App\Models\Bundle;
use App\Models\ProductVariant;
use App\Models\ShopeeShop;
use App\Models\ShopeeSkuMapping;
use App\Support\XlsxExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Status produk Optima di Shopee: tiap varian / paket aktif, di toko mana sudah
 * terhubung (dipetakan), stok terakhir yang dikirim, dan yang gagal dikirim.
 */
class ShopeeProductStatusController extends Controller
{
    public const STATUSES = [
        'unlinked' => 'Belum terhubung',
        'linked' => 'Sudah terhubung',
        'error' => 'Gagal kirim stok',
    ];

    public function __invoke(Request $request): View|BinaryFileResponse
    {
        $type = $request->input('type') === 'bundle' ? 'bundle' : 'variant';
        $shops = ShopeeShop::orderBy('environment')->orderBy('shop_name')->get();
        $shop = $shops->firstWhere('id', (int) $request->input('shop'));

        $query = $this->query($type, $request, $shop);

        if ($request->input('export') === 'xlsx') {
            return $this->export($type, $query, $shops);
        }

        $rows = $query->paginate(25)->withQueryString();

        return view('shopee.products.index', [
            'type' => $type,
            'rows' => $rows,
            'shops' => $shops,
            'shop' => $shop,
            'mappings' => $this->mappingsFor($type, $rows->getCollection()),
            'summary' => $this->summary($type, $shop),
        ]);
    }

    private function query(string $type, Request $request, ?ShopeeShop $shop): Builder
    {
        $inShop = fn ($q) => $shop ? $q->where('shopee_shop_id', $shop->id) : $q;
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($request->string('search')->toString())).'%';

        $query = $type === 'bundle'
            ? Bundle::query()->where('is_active', true)
                ->when($request->filled('search'), fn ($q) => $q->where(fn ($q) => $q->where('sku', 'like', $like)->orWhere('name', 'like', $like)))
            : ProductVariant::query()->with('product:id,name')->where('is_active', true)
                ->whereHas('product', fn ($q) => $q->where('is_active', true))
                ->when($request->filled('search'), fn ($q) => $q->where(fn ($q) => $q->where('sku', 'like', $like)->orWhere('name', 'like', $like)
                    ->orWhereHas('product', fn ($q) => $q->where('name', 'like', $like))));

        return (match ($request->input('status')) {
            'unlinked' => $query->whereDoesntHave('shopeeMappings', $inShop),
            'linked' => $query->whereHas('shopeeMappings', $inShop),
            'error' => $query->whereHas('shopeeMappings', fn ($q) => $inShop($q)->whereNotNull('stock_error')),
            default => $query,
        })->orderBy('sku');
    }

    /**
     * Baris pemetaan per SKU Optima, dikelompokkan per toko.
     *
     * @return Collection<int, Collection<int, Collection<int, ShopeeSkuMapping>>>
     */
    private function mappingsFor(string $type, Collection $rows): Collection
    {
        $column = $type === 'bundle' ? 'bundle_id' : 'product_variant_id';

        return ShopeeSkuMapping::whereIn($column, $rows->pluck('id'))->get()
            ->groupBy($column)
            ->map(fn ($group) => $group->groupBy('shopee_shop_id'));
    }

    /**
     * @return array{total: int, linked: int, unlinked: int, error: int}
     */
    private function summary(string $type, ?ShopeeShop $shop): array
    {
        $count = fn (?string $status) => $this->query($type, new Request(['status' => $status]), $shop)->count();

        return [
            'total' => $count(null),
            'linked' => $count('linked'),
            'unlinked' => $count('unlinked'),
            'error' => $count('error'),
        ];
    }

    private function export(string $type, Builder $query, Collection $shops): BinaryFileResponse
    {
        $column = $type === 'bundle' ? 'bundle_id' : 'product_variant_id';

        return XlsxExport::download('status-produk-shopee-'.now()->format('Ymd-His').'.xlsx',
            ['SKU', 'Nama', ...$shops->map(fn (ShopeeShop $s) => $s->displayName())->all()],
            $query->with('shopeeMappings')->lazy(500)->map(function ($row) use ($shops, $type) {
                $byShop = $row->shopeeMappings->groupBy('shopee_shop_id');

                return [
                    $row->sku,
                    $type === 'bundle' ? $row->name : trim(($row->product?->name ?? '').' '.$row->name),
                    ...$shops->map(function (ShopeeShop $shop) use ($byShop) {
                        $mappings = $byShop[$shop->id] ?? collect();

                        return match (true) {
                            $mappings->isEmpty() => 'Belum terhubung',
                            $mappings->whereNotNull('stock_error')->isNotEmpty() => 'Gagal: '.$mappings->whereNotNull('stock_error')->first()->stock_error,
                            default => 'Terhubung'.($mappings->first()->stock_pushed_at ? ' · stok '.$mappings->first()->stock_pushed : ''),
                        };
                    })->all(),
                ];
            }));
    }
}
