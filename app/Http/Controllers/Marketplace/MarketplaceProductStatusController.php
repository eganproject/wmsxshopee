<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\Bundle;
use App\Models\MarketplaceStockHold;
use App\Models\ProductVariant;
use App\Models\ShopeeShop;
use App\Models\ShopeeSkuMapping;
use App\Models\TiktokShop;
use App\Models\TiktokSkuMapping;
use App\Services\WarehouseSupply;
use App\Support\XlsxExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Status produk Optima di semua toko marketplace (Shopee & TikTok): tiap varian /
 * paket aktif, di toko mana sudah terhubung (dipetakan), stok terakhir yang
 * dikirim, dan yang gagal dikirim. Kolom = toko; filter per platform / toko.
 */
class MarketplaceProductStatusController extends Controller
{
    public const STATUSES = [
        'unlinked' => 'Belum terhubung',
        'linked' => 'Sudah terhubung',
        'error' => 'Gagal kirim stok',
    ];

    /** Relasi pemetaan & kolom toko per platform. */
    private const RELATIONS = [
        'shopee' => ['relation' => 'shopeeMappings', 'shop_column' => 'shopee_shop_id'],
        'tiktok' => ['relation' => 'tiktokMappings', 'shop_column' => 'tiktok_shop_id'],
    ];

    public function __invoke(Request $request): View|BinaryFileResponse
    {
        $user = $request->user();
        $platforms = collect(MarketplaceController::PLATFORMS)->filter(fn ($meta) => $user->can($meta['view']))->keys();
        $platform = $platforms->contains($request->input('platform')) ? $request->input('platform') : null;
        $type = $request->input('type') === 'bundle' ? 'bundle' : 'variant';

        $allShops = $this->shops($platforms);
        $columns = $platform ? $allShops->where('platform', $platform)->values() : $allShops;
        $shop = $columns->firstWhere('key', (string) $request->input('shop'));
        $scope = $shop ? collect([$shop]) : $columns;

        $query = $this->query($type, $request, $scope);

        if ($request->input('export') === 'xlsx') {
            return $this->export($type, $query, $columns);
        }

        $rows = $query->paginate(25)->withQueryString();

        return view('marketplace.products', [
            'type' => $type,
            'rows' => $rows,
            'platforms' => $platforms,
            'platform' => $platform,
            'columns' => $columns,
            'shop' => $shop,
            'mappings' => $this->mappingsFor($type, $rows->getCollection(), $columns),
            'summary' => $this->summary($type, $scope),
            'breakdowns' => $type === 'variant' ? $this->breakdowns($rows->getCollection(), $columns) : collect(),
        ]);
    }

    /**
     * @param  Collection<int, string>  $platforms
     * @return Collection<int, array{key: string, platform: string, shop: ShopeeShop|TiktokShop}>
     */
    private function shops(Collection $platforms): Collection
    {
        $shopee = $platforms->contains('shopee')
            ? ShopeeShop::orderBy('environment')->orderBy('shop_name')->get()->map(fn ($s) => ['key' => 'shopee-'.$s->id, 'platform' => 'shopee', 'shop' => $s])
            : collect();
        $tiktok = $platforms->contains('tiktok')
            ? TiktokShop::orderBy('shop_name')->get()->map(fn ($s) => ['key' => 'tiktok-'.$s->id, 'platform' => 'tiktok', 'shop' => $s])
            : collect();

        return $shopee->concat($tiktok)->values();
    }

    /**
     * @param  Collection<int, array{key: string, platform: string, shop: Model}>  $scope  toko yang dihitung
     */
    private function query(string $type, Request $request, Collection $scope): Builder
    {
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($request->string('search')->toString())).'%';

        $query = $type === 'bundle'
            ? Bundle::query()->where('is_active', true)
                ->when($request->filled('search'), fn ($q) => $q->where(fn ($q) => $q->where('sku', 'like', $like)->orWhere('name', 'like', $like)))
            : ProductVariant::query()->with('product:id,name')->where('is_active', true)
                ->whereHas('product', fn ($q) => $q->where('is_active', true))
                ->when($request->filled('search'), fn ($q) => $q->where(fn ($q) => $q->where('sku', 'like', $like)->orWhere('name', 'like', $like)
                    ->orWhereHas('product', fn ($q) => $q->where('name', 'like', $like))));

        // Ada pemetaan di salah satu toko dalam cakupan (opsional: yang gagal kirim stok).
        $hasMapping = function (Builder $q, bool $errorsOnly = false) use ($scope) {
            $q->where(function (Builder $q) use ($scope, $errorsOnly) {
                $q->whereRaw('1 = 0');

                foreach ($scope->groupBy('platform') as $platform => $shops) {
                    $meta = self::RELATIONS[$platform];
                    $q->orWhereHas($meta['relation'], fn ($m) => $m->whereIn($meta['shop_column'], $shops->pluck('shop.id'))
                        ->when($errorsOnly, fn ($m) => $m->whereNotNull('stock_error')));
                }
            });

            return $q;
        };

        return (match ($request->input('status')) {
            'unlinked' => $query->whereNot(fn ($q) => $hasMapping($q)),
            'linked' => $hasMapping($query),
            'error' => $hasMapping($query, true),
            default => $query,
        })->orderBy('sku');
    }

    /**
     * Baris pemetaan per SKU Optima, dikelompokkan per kunci toko (shopee-1, tiktok-2).
     *
     * @return Collection<int, Collection<string, Collection<int, Model>>>
     */
    private function mappingsFor(string $type, Collection $rows, Collection $columns): Collection
    {
        $column = $type === 'bundle' ? 'bundle_id' : 'product_variant_id';
        $ids = $rows->pluck('id');
        $grouped = [];

        foreach ($columns->pluck('platform')->unique() as $platform) {
            $shopColumn = self::RELATIONS[$platform]['shop_column'];
            $model = $platform === 'shopee' ? ShopeeSkuMapping::class : TiktokSkuMapping::class;

            foreach ($model::whereIn($column, $ids)->whereNull('removed_at')->get() as $mapping) {
                $grouped[$mapping->{$column}][$platform.'-'.$mapping->{$shopColumn}][] = $mapping;
            }
        }

        return collect($grouped)->map(fn ($byShop) => collect($byShop)->map(fn ($list) => collect($list)));
    }

    /**
     * Rincian stok jual saat ini untuk toko yang gudangnya punya gudang cadangan
     * atau stok yang ditahan pesanan belum bayar,
     * per gudang toko lalu per varian (lihat WarehouseSupply::sellable).
     *
     * @return Collection<int, Collection<int, array<string, int>>>
     */
    private function breakdowns(Collection $rows, Collection $columns): Collection
    {
        $supply = app(WarehouseSupply::class);

        return $columns->pluck('shop.warehouse_id')->filter()->unique()
            // Gudang dengan gudang cadangan, atau yang stoknya sedang ditahan pesanan belum bayar.
            ->filter(fn ($warehouseId) => $supply->supplyOf($warehouseId) || MarketplaceStockHold::active()->where('warehouse_id', $warehouseId)->exists())
            ->mapWithKeys(fn ($warehouseId) => [$warehouseId => $supply->sellable($warehouseId, $rows->pluck('id')->all())
                ->map(fn ($split) => [...$split, 'has_supply' => (bool) $supply->supplyOf($warehouseId)])]);
    }

    /**
     * @return array{total: int, linked: int, unlinked: int, error: int}
     */
    private function summary(string $type, Collection $scope): array
    {
        $count = fn (?string $status) => $this->query($type, new Request(['status' => $status]), $scope)->count();

        return [
            'total' => $count(null),
            'linked' => $count('linked'),
            'unlinked' => $count('unlinked'),
            'error' => $count('error'),
        ];
    }

    private function export(string $type, Builder $query, Collection $columns): BinaryFileResponse
    {
        $relations = $columns->pluck('platform')->unique()->map(fn ($p) => self::RELATIONS[$p]['relation'])->values()->all();

        return XlsxExport::download('status-produk-marketplace-'.now()->format('Ymd-His').'.xlsx',
            ['SKU', 'Nama', ...$columns->map(fn ($c) => ($c['platform'] === 'shopee' ? 'Shopee · ' : 'TikTok · ').$c['shop']->displayName())->all()],
            $query->with($relations)->lazy(500)->map(function ($row) use ($columns, $type) {
                return [
                    $row->sku,
                    $type === 'bundle' ? $row->name : trim(($row->product?->name ?? '').' '.$row->name),
                    ...$columns->map(function ($column) use ($row) {
                        $meta = self::RELATIONS[$column['platform']];
                        $mappings = $row->{$meta['relation']}->where($meta['shop_column'], $column['shop']->id);

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
