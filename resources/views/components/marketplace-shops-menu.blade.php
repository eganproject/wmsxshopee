{{--
    Ikon "Toko Terhubung" di topbar: daftar toko Shopee & TikTok yang terhubung beserta
    statusnya, dan tombol sinkron (produk & SKU, pesanan, retur, stok) per toko atau semua.
--}}
@php
    use App\Services\MarketplaceShopSync;

    $user = auth()->user();
    $rows = MarketplaceShopSync::shopsFor($user)->map(fn ($row) => [...$row, 'status' => MarketplaceShopSync::status($row['shop'])]);
    $problems = $rows->filter(fn ($row) => in_array($row['status'][0], ['expired', 'attention'], true))->count();
    $syncable = $rows->filter(fn ($row) => $row['shop']->is_active && $row['shop']->isConnected() && MarketplaceShopSync::canSync($user, $row['platform'], $row['shop']));
    $dots = ['connected' => 'bg-emerald-500', 'attention' => 'bg-amber-500', 'expired' => 'bg-rose-500', 'inactive' => 'bg-slate-400'];
@endphp

<div class="relative" x-data="{ open: false, busy: null }" @click.outside="open = false" @keydown.escape="open = false" data-testid="marketplace-shops-menu">
    <button type="button" class="btn-icon relative" @click="open = !open"
            title="Toko terhubung" aria-label="Toko terhubung ({{ $rows->count() }}){{ $problems ? ', '.$problems.' perlu perhatian' : '' }}">
        <x-heroicon-o-building-storefront />
        @if ($problems)
            <span class="absolute -top-0.5 -right-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-amber-500 px-1 text-[10px] leading-none font-semibold text-white tabular-nums">{{ $problems }}</span>
        @endif
    </button>

    <div x-cloak x-show="open" x-transition.origin.top.right
         class="absolute right-0 mt-1.5 w-80 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-lg sm:w-96">
        <div class="flex items-start justify-between gap-3 border-b border-slate-100 px-3.5 py-3">
            <div class="min-w-0">
                <p class="text-sm font-medium text-slate-900">Toko Terhubung</p>
                <p class="text-xs text-slate-500">{{ $rows->count() }} toko · sinkron otomatis tiap 5 menit</p>
            </div>
            @if ($syncable->isNotEmpty())
                <form method="POST" action="{{ route('marketplace.sync-all') }}" @submit="busy = 'all'">
                    @csrf
                    <button type="submit" class="btn-primary h-7 px-2.5 text-xs whitespace-nowrap" :disabled="busy !== null"
                            title="Sinkron produk & SKU, pesanan, retur dan stok untuk {{ $syncable->count() }} toko">
                        <span class="inline-flex" :class="busy === 'all' && 'animate-spin'"><x-heroicon-o-arrow-path class="size-4" /></span>
                        <span x-text="busy === 'all' ? 'Menyinkron...' : 'Sinkron Semua'">Sinkron Semua</span>
                    </button>
                </form>
            @endif
        </div>

        <div class="max-h-96 overflow-y-auto p-1">
            @forelse ($rows as ['platform' => $p, 'shop' => $shop, 'status' => [$statusKey, $statusLabel]])
                @php
                    $key = $p.'-'.$shop->id;
                    $canSync = $shop->is_active && $shop->isConnected() && MarketplaceShopSync::canSync($user, $p, $shop);
                @endphp
                <div class="flex items-center gap-2.5 rounded-lg px-2.5 py-2 hover:bg-slate-50" data-testid="marketplace-shop-row">
                    <span @class([
                        'flex size-8 shrink-0 items-center justify-center rounded-lg',
                        'bg-brand-50 text-brand-700' => $p === 'shopee',
                        'bg-slate-900 text-white' => $p === 'tiktok',
                    ]) title="{{ $p === 'shopee' ? 'Shopee' : 'TikTok Shop' }}">
                        @if ($p === 'shopee')
                            <x-heroicon-s-shopping-bag class="size-4" />
                        @else
                            <x-heroicon-s-musical-note class="size-4" />
                        @endif
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm text-slate-900">
                            {{ $shop->displayName() }}
                            @if ($p === 'shopee' && $shop->environment === 'test')
                                <span class="text-[11px] text-amber-600">· Test</span>
                            @endif
                        </p>
                        <p class="flex items-center gap-1.5 truncate text-xs text-slate-500" title="{{ $shop->last_error ?: $statusLabel }}">
                            <span @class(['size-1.5 shrink-0 rounded-full', $dots[$statusKey]])></span>
                            <span class="truncate">
                                {{ $statusLabel }}
                                · {{ $shop->last_synced_at ? 'sinkron '.$shop->last_synced_at->diffForHumans() : 'belum pernah sinkron' }}
                                @if ($shop->warehouse)
                                    · {{ $shop->warehouse->code }}
                                @endif
                            </span>
                        </p>
                    </div>
                    @if ($canSync)
                        <form method="POST" action="{{ route('marketplace.shops.sync-all', ['platform' => $p, 'shop' => $shop->id]) }}" @submit="busy = @js($key)">
                            @csrf
                            <button type="submit" class="btn-icon size-7" :disabled="busy !== null"
                                    title="Sinkron {{ $shop->displayName() }}: produk & SKU, pesanan, retur, stok" aria-label="Sinkron {{ $shop->displayName() }}">
                                <span class="inline-flex" :class="busy === @js($key) && 'animate-spin'"><x-heroicon-o-arrow-path class="size-4" /></span>
                            </button>
                        </form>
                    @endif
                </div>
            @empty
                <div class="flex flex-col items-center gap-1.5 px-2.5 py-6 text-center text-sm text-slate-500">
                    <x-heroicon-o-building-storefront class="size-6 text-slate-400" />
                    Belum ada toko marketplace yang terhubung.
                </div>
            @endforelse
        </div>

        <div class="border-t border-slate-100 p-1">
            <p class="px-2.5 pt-1 pb-1.5 text-[11px] text-slate-500">Sinkron: produk & SKU → pesanan & resi → retur → stok.</p>
            <a href="{{ route('marketplace.shops') }}" class="flex h-7 items-center justify-center rounded-lg text-sm text-slate-600 hover:bg-slate-100">
                {{ $rows->isEmpty() ? 'Hubungkan toko' : 'Kelola toko marketplace' }}
            </a>
        </div>
    </div>
</div>
