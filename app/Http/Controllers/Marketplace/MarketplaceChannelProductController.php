<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\ShopeeShop;
use App\Models\ShopeeSkuMapping;
use App\Models\TiktokShop;
use App\Models\TiktokSkuMapping;
use App\Services\SalesOrderService;
use App\Services\Shopee\ShopeeCatalog;
use App\Services\Tiktok\TiktokCatalog;
use App\Services\WarehouseSupply;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Produk Channel (seperti di Ginee): semua listing yang ditarik dari tiap toko
 * Shopee & TikTok, satu blok per produk marketplace dengan variasinya: SKU channel,
 * harga, stok di toko, status tayang, dan ke SKU Optima mana tiap variasi
 * dipetakan. Nama produk membuka etalase marketplace di tab baru.
 *
 * Datanya tabel pemetaan yang sudah ada (shopee_sku_mappings / tiktok_sku_mappings);
 * halaman ini hanya cara pandang lain, per listing, bukan per SKU Optima
 * (itu halaman Status Produk) atau per toko (halaman Pemetaan SKU).
 */
class MarketplaceChannelProductController extends Controller
{
    public const STATUSES = [
        'unmapped' => 'Ada SKU belum dipetakan',
        'mapped' => 'Semua SKU dipetakan',
        'failed' => 'Gagal kirim stok',
        'removed' => 'Tidak ada lagi di toko',
    ];

    public const LISTING_STATUSES = [
        'live' => 'Tayang',
        'hidden' => 'Tidak tayang',
    ];

    /** Status listing yang berarti tayang di etalase: Shopee NORMAL, TikTok ACTIVATE. */
    public const LIVE_STATUSES = ['NORMAL', 'ACTIVATE'];

    public function index(Request $request): View
    {
        $user = $request->user();
        $platforms = collect(MarketplaceController::PLATFORMS)->filter(fn ($meta) => $user->can($meta['view']))->keys();
        $platform = $platforms->contains($request->input('platform')) ? $request->input('platform') : null;

        $allShops = $this->shops($platforms);
        $options = $platform ? $allShops->where('platform', $platform)->values() : $allShops;
        $shop = $options->firstWhere('key', (string) $request->input('shop'));
        $scope = $shop ? collect([$shop]) : $options;

        $listings = $this->listings($scope, $request)->paginate(20)->withQueryString();

        return view('marketplace.channel-products', [
            'platforms' => $platforms,
            'platform' => $platform,
            'options' => $options,
            'shop' => $shop,
            'listings' => $listings,
            'rows' => $this->rowsFor($listings, $scope, $request->input('status') === 'removed'),
            'summary' => $this->summary($scope),
            'canMap' => $platforms->filter(fn ($p) => $user->can("{$p}.mapping"))->values(),
        ]);
    }

    /**
     * Tombol "Cocokkan Otomatis": SKU channel yang sama persis dengan SKU varian /
     * paket dipetakan, di semua toko platform yang boleh dipetakan user ini.
     */
    public function autoMatch(Request $request): RedirectResponse
    {
        $user = $request->user();
        $platforms = collect(MarketplaceController::PLATFORMS)->keys()->filter(fn ($p) => $user->can("{$p}.mapping"));
        abort_if($platforms->isEmpty(), 403, 'Anda tidak punya izin memetakan produk marketplace.');

        $matched = 0;

        foreach ($this->shops($platforms) as $row) {
            $matched += $row['platform'] === 'shopee'
                ? app(ShopeeCatalog::class)->autoMatch($row['shop'])
                : app(TiktokCatalog::class)->autoMatch($row['shop']);
        }

        return back()->with('success', $matched
            ? "{$matched} SKU channel cocok otomatis berdasarkan SKU yang sama dengan SKU Optima."
            : 'Tidak ada SKU channel baru yang cocok. Petakan sisanya lewat kolom SKU Optima.');
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
     * Satu baris per listing (produk marketplace per toko), gabungan Shopee &
     * TikTok, dengan ringkasan variasinya. Filter status pemetaan diterapkan pada
     * hasil gabungan; pencarian menyaring listing yang salah satu variasinya cocok.
     *
     * @param  Collection<int, array{key: string, platform: string, shop: ShopeeShop|TiktokShop}>  $scope
     */
    private function listings(Collection $scope, Request $request): Builder
    {
        $removed = $request->input('status') === 'removed';
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($request->string('search')->toString())).'%';
        $union = null;

        foreach ($scope->groupBy('platform') as $platform => $shops) {
            $ids = $shops->pluck('shop.id')->all();
            [$table, $shopColumn, $listingColumn, $nameColumn, $skuColumn, $variantColumn] = $platform === 'shopee'
                ? ['shopee_sku_mappings', 'shopee_shop_id', 'item_id', 'item_name', 'shopee_sku', 'model_name']
                : ['tiktok_sku_mappings', 'tiktok_shop_id', 'product_id', 'product_name', 'seller_sku', 'sku_name'];

            $query = DB::table($table)
                ->selectRaw("'{$platform}' as platform")
                ->selectRaw("{$shopColumn} as shop_id")
                ->selectRaw("{$listingColumn} as listing_id")
                ->selectRaw("MIN({$nameColumn}) as name")
                ->selectRaw('MIN(image_url) as image_url')
                ->selectRaw('MIN(channel_status) as channel_status')
                ->selectRaw('COUNT(*) as skus')
                ->selectRaw('SUM(CASE WHEN product_variant_id IS NULL AND bundle_id IS NULL THEN 1 ELSE 0 END) as unmapped')
                ->selectRaw('SUM(CASE WHEN stock_error IS NOT NULL THEN 1 ELSE 0 END) as failed')
                ->whereIn($shopColumn, $ids)
                ->when($removed, fn ($q) => $q->whereNotNull('removed_at'), fn ($q) => $q->whereNull('removed_at'))
                ->when($request->filled('search'), fn ($q) => $q->whereIn($listingColumn, fn ($sub) => $sub->select($listingColumn)->from($table)
                    ->whereIn($shopColumn, $ids)
                    ->where(fn ($w) => $w->where($nameColumn, 'like', $like)->orWhere($skuColumn, 'like', $like)
                        ->orWhere($variantColumn, 'like', $like)->orWhere($listingColumn, 'like', $like))))
                ->when($request->input('listing') === 'live', fn ($q) => $q->whereIn('channel_status', self::LIVE_STATUSES))
                ->when($request->input('listing') === 'hidden', fn ($q) => $q->whereNotNull('channel_status')->whereNotIn('channel_status', self::LIVE_STATUSES))
                ->groupBy($shopColumn, $listingColumn);

            $union = $union ? $union->unionAll($query) : $query;
        }

        // Tanpa toko dalam cakupan: hasil kosong dengan bentuk kolom yang sama.
        $union ??= DB::table('shopee_sku_mappings')
            ->selectRaw("'shopee' as platform, shopee_shop_id as shop_id, item_id as listing_id, item_name as name, image_url, channel_status, 0 as skus, 0 as unmapped, 0 as failed")
            ->whereRaw('1 = 0');

        return DB::query()->fromSub($union, 'listings')
            ->when($request->input('status') === 'unmapped', fn ($q) => $q->where('unmapped', '>', 0))
            ->when($request->input('status') === 'mapped', fn ($q) => $q->where('unmapped', 0))
            ->when($request->input('status') === 'failed', fn ($q) => $q->where('failed', '>', 0))
            ->orderBy('name')->orderBy('listing_id');
    }

    /**
     * Baris pemetaan (variasi) untuk listing di halaman ini, dikelompokkan per
     * kunci listing "platform-shop-listing", masing-masing dilengkapi SKU Optima,
     * stok jual Optima di gudang toko, dan alamat etalase.
     *
     * @param  Collection<int, array{key: string, platform: string, shop: ShopeeShop|TiktokShop}>  $scope
     * @return Collection<string, Collection<int, ShopeeSkuMapping|TiktokSkuMapping>>
     */
    private function rowsFor(LengthAwarePaginator $listings, Collection $scope, bool $removed): Collection
    {
        $page = collect($listings->items());
        $shopsByKey = $scope->keyBy('key');
        $rows = [];

        foreach ($page->groupBy('platform') as $platform => $items) {
            $model = $platform === 'shopee' ? ShopeeSkuMapping::class : TiktokSkuMapping::class;
            [$shopColumn, $listingColumn, $variantColumn] = $platform === 'shopee' ? ['shopee_shop_id', 'item_id', 'model_name'] : ['tiktok_shop_id', 'product_id', 'sku_name'];

            $mappings = $model::query()
                ->with(['variant.product:id,name', 'bundle:id,sku,name', 'bundle.items:id,bundle_id,product_variant_id,qty'])
                ->whereIn($shopColumn, $items->pluck('shop_id')->unique()->all())
                ->whereIn($listingColumn, $items->pluck('listing_id')->unique()->all())
                ->when($removed, fn ($q) => $q->whereNotNull('removed_at'), fn ($q) => $q->whereNull('removed_at'))
                ->orderBy($variantColumn)->orderBy('id')
                ->get();

            foreach ($mappings as $mapping) {
                // Relasi toko diisi dari daftar yang sudah dimuat (hemat query, tetap dalam akses user).
                $mapping->setRelation('shop', $shopsByKey["{$platform}-{$mapping->{$shopColumn}}"]['shop']);
                $rows["{$platform}-{$mapping->{$shopColumn}}-{$mapping->{$listingColumn}}"][] = $mapping;
            }

            $this->attachOptimaStock($mappings);
        }

        return collect($rows)->map(fn ($list) => collect($list));
    }

    /**
     * Stok jual Optima sekarang untuk tiap baris (angka yang akan dikirim ke toko):
     * varian lewat WarehouseSupply::sellable, paket lewat ketersediaan komponennya.
     *
     * @param  Collection<int, ShopeeSkuMapping|TiktokSkuMapping>  $mappings
     */
    private function attachOptimaStock(Collection $mappings): void
    {
        $supply = app(WarehouseSupply::class);
        $orders = app(SalesOrderService::class);

        foreach ($mappings->groupBy(fn ($m) => (int) $m->shop->warehouse_id) as $warehouseId => $rows) {
            if (! $warehouseId) {
                continue;
            }

            $variantIds = $rows->pluck('product_variant_id')->filter()->unique()->values()->all();
            $sellable = $variantIds ? $supply->sellable($warehouseId, $variantIds) : collect();
            $bundles = $rows->pluck('bundle')->filter()->unique('id');
            $bundleStock = $bundles->isNotEmpty() ? $orders->bundleAvailability($warehouseId, $bundles) : collect();

            foreach ($rows as $row) {
                $row->optima_stock = match (true) {
                    $row->product_variant_id !== null => $sellable[$row->product_variant_id]['pushable'] ?? null,
                    $row->bundle_id !== null => $bundleStock[$row->bundle_id] ?? null,
                    default => null,
                };
            }
        }
    }

    /**
     * @param  Collection<int, array{key: string, platform: string, shop: ShopeeShop|TiktokShop}>  $scope
     * @return array{listings: int, skus: int, unmapped: int, failed: int, removed: int}
     */
    private function summary(Collection $scope): array
    {
        $summary = ['listings' => $this->listings($scope, new Request)->count(), 'skus' => 0, 'unmapped' => 0, 'failed' => 0, 'removed' => 0];

        foreach ($scope->groupBy('platform') as $platform => $shops) {
            $model = $platform === 'shopee' ? ShopeeSkuMapping::class : TiktokSkuMapping::class;
            $active = $model::whereIn($platform === 'shopee' ? 'shopee_shop_id' : 'tiktok_shop_id', $shops->pluck('shop.id'));

            $summary['removed'] += (clone $active)->whereNotNull('removed_at')->count();
            $active->whereNull('removed_at');
            $summary['skus'] += (clone $active)->count();
            $summary['unmapped'] += (clone $active)->whereNull('product_variant_id')->whereNull('bundle_id')->count();
            $summary['failed'] += (clone $active)->whereNotNull('stock_error')->count();
        }

        return $summary;
    }
}
