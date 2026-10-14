<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\ShopeeSetting;
use App\Models\ShopeeShop;
use App\Models\TiktokSetting;
use App\Models\TiktokShop;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Satu halaman untuk semua toko marketplace (Shopee & TikTok Shop) dan satu
 * halaman pengaturan kredensial dengan tab per platform. Aksi per toko tetap
 * memakai route masing-masing platform (shopee.* / tiktok.*).
 */
class MarketplaceController extends Controller
{
    public const PLATFORMS = [
        'shopee' => ['label' => 'Shopee', 'view' => 'shopee.view', 'connect' => 'shopee.connect', 'settings' => 'shopee.settings'],
        'tiktok' => ['label' => 'TikTok Shop', 'view' => 'tiktok.view', 'connect' => 'tiktok.connect', 'settings' => 'tiktok.settings'],
    ];

    public const STATUSES = [
        'connected' => 'Terhubung',
        'attention' => 'Perlu perhatian',
        'expired' => 'Perlu otorisasi ulang',
        'inactive' => 'Nonaktif',
    ];

    public function shops(Request $request): View
    {
        $user = $request->user();
        $platforms = collect(self::PLATFORMS)->filter(fn ($meta) => $user->can($meta['view']));
        $platform = $platforms->has($request->input('platform')) ? $request->input('platform') : null;

        $all = $this->allShops($platforms->keys());
        $shops = $all
            ->when($platform, fn ($c) => $c->where('platform', $platform))
            ->when($request->filled('status'), fn ($c) => $c->filter(fn ($row) => $this->matchesStatus($row['shop'], (string) $request->input('status'))))
            ->when($request->filled('search'), function ($c) use ($request) {
                $needle = mb_strtolower(trim((string) $request->input('search')));

                return $c->filter(fn ($row) => str_contains(mb_strtolower($row['shop']->displayName().' '.$row['shop']->shop_id.' '.$row['shop']->region), $needle));
            })
            ->values();

        $shopee = ShopeeSetting::current();
        $tiktok = TiktokSetting::current();

        return view('marketplace.shops', [
            'platforms' => $platforms,
            'platform' => $platform,
            'shops' => $shops,
            'counts' => $all->countBy('platform'),
            'attention' => $all->filter(fn ($row) => $this->matchesStatus($row['shop'], 'attention') || $this->matchesStatus($row['shop'], 'expired'))->count(),
            'configured' => [
                'shopee' => $shopee->isConfigured(),
                'tiktok' => $tiktok->isConfigured(),
            ],
            'shopeeEnvironment' => $shopee->environment,
            'warehouses' => Warehouse::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }

    public function settings(Request $request): View
    {
        $user = $request->user();
        $tabs = collect(self::PLATFORMS)->filter(fn ($meta) => $user->can($meta['settings']));
        $tab = $tabs->has($request->input('tab')) ? $request->input('tab') : $tabs->keys()->first();

        return view('marketplace.settings', [
            'tabs' => $tabs,
            'tab' => $tab,
            'shopee' => ShopeeSetting::current(),
            'tiktok' => TiktokSetting::current(),
            'shopeeHosts' => config('shopee.hosts'),
            'tiktokHosts' => config('tiktok.hosts'),
            'shopCounts' => ['shopee' => ShopeeShop::count(), 'tiktok' => TiktokShop::count()],
        ]);
    }

    /**
     * Toko kedua platform dalam satu daftar: [platform, shop].
     *
     * @param  Collection<int, string>  $platforms
     * @return Collection<int, array{platform: string, shop: ShopeeShop|TiktokShop}>
     */
    private function allShops(Collection $platforms): Collection
    {
        $counts = [
            'mappings',
            'mappings as unmapped_count' => fn ($q) => $q->whereNull('product_variant_id')->whereNull('bundle_id'),
            'mappings as stock_error_count' => fn ($q) => $q->whereNotNull('stock_error'),
        ];

        $shopee = $platforms->contains('shopee')
            ? ShopeeShop::with('warehouse:id,code,name')->withCount($counts)->orderBy('environment')->orderBy('shop_name')->get()
                ->map(fn ($shop) => ['platform' => 'shopee', 'shop' => $shop])
            : collect();

        $tiktok = $platforms->contains('tiktok')
            ? TiktokShop::with('warehouse:id,code,name')->withCount($counts)->orderBy('shop_name')->get()
                ->map(fn ($shop) => ['platform' => 'tiktok', 'shop' => $shop])
            : collect();

        return $shopee->concat($tiktok)->values();
    }

    private function matchesStatus(ShopeeShop|TiktokShop $shop, string $status): bool
    {
        return match ($status) {
            'connected' => $shop->is_active && $shop->status === 'connected',
            'expired' => $shop->status !== 'connected',
            'inactive' => ! $shop->is_active,
            'attention' => $shop->status === 'connected' && (filled($shop->last_error) || ! $shop->warehouse_id || $shop->unmapped_count > 0 || $shop->stock_error_count > 0),
            default => true,
        };
    }
}
