@php
    use App\Http\Controllers\Marketplace\MarketplaceChannelProductController as Channel;
    use App\Support\StockPushError;

    $money = fn ($value) => 'Rp '.number_format((float) $value, 0, ',', '.');
    $num = fn ($value) => number_format((int) $value, 0, ',', '.');
    $route = fn (array $overrides = [], array $except = []) => route('marketplace.channel-products', array_filter([...request()->except([...$except, 'page']), ...$overrides], fn ($v) => $v !== null && $v !== ''));
@endphp

<x-layouts.app title="Produk Channel">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span>Integrasi</span>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Produk Channel</span>
    </x-slot:breadcrumb>

    <x-page-header title="Produk Channel"
                   description="Semua produk yang tayang di toko Shopee dan TikTok beserta variasinya: SKU channel, harga, stok di toko, dan SKU Optima yang terhubung. Klik nama produk untuk membuka etalasenya di marketplace."
                   icon="heroicon-o-squares-2x2">
        @if ($canMap->isNotEmpty())
            <form method="POST" action="{{ route('marketplace.channel-products.auto-match') }}">
                @csrf
                <button type="submit" class="btn-secondary" data-hint-title="Cocokkan Otomatis"
                        data-hint="SKU channel yang belum terhubung dan sama persis dengan SKU varian / paket di Optima langsung dipetakan, di semua toko.">
                    <x-heroicon-o-sparkles class="size-5" /> Cocokkan Otomatis
                </button>
            </form>
        @endif
        <x-marketplace.sync-products-button />
    </x-page-header>

    @if ($platforms->count() > 1)
        <div class="mb-4 flex gap-1 border-b border-slate-200">
            @foreach (['' => 'Semua platform', 'shopee' => 'Shopee', 'tiktok' => 'TikTok Shop'] as $value => $label)
                <a href="{{ $route(['platform' => $value ?: null], ['platform', 'shop']) }}"
                   @class(['-mb-px border-b-2 px-3 py-2 text-sm', 'border-slate-900 font-medium text-slate-900' => ($platform ?? '') === $value, 'border-transparent text-slate-500 hover:text-slate-700' => ($platform ?? '') !== $value])>{{ $label }}</a>
            @endforeach
        </div>
    @endif

    <div class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            [null, 'Produk di toko', 'Jumlah listing (produk marketplace) di toko yang dipilih. Satu produk bisa punya beberapa variasi.', $summary['listings'], 'text-slate-900'],
            ['mapped', 'SKU channel', 'Semua SKU / variasi yang ditarik dari toko. Klik untuk melihat produk yang semua SKU-nya sudah terhubung.', $summary['skus'], 'text-slate-900'],
            ['unmapped', 'SKU belum terhubung', 'SKU channel yang belum dipetakan ke SKU Optima. Stoknya tidak dikirim dan pesanannya tertahan di Draft.', $summary['unmapped'], $summary['unmapped'] ? 'text-amber-600' : 'text-slate-900'],
            ['failed', 'Gagal kirim stok', 'SKU yang stoknya ditolak marketplace saat terakhir dikirim.', $summary['failed'], $summary['failed'] ? 'text-rose-600' : 'text-slate-900'],
        ] as [$status, $label, $hint, $value, $tone])
            <a href="{{ $route(['status' => $status], ['status']) }}"
               @class(['card px-4 py-3 hover:bg-slate-50/60', 'ring-1 ring-slate-900' => request('status') === $status && ($status !== null || ! request()->filled('status'))])>
                <p class="text-xs text-slate-500">{{ $label }} <x-hint :text="$hint" /></p>
                <p class="text-xl font-semibold tabular-nums {{ $tone }}">{{ $num($value) }}</p>
            </a>
        @endforeach
    </div>

    @if ($summary['removed'] && request('status') !== 'removed')
        <div class="mb-6 flex items-start gap-2 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
            <x-heroicon-o-archive-box-x-mark class="mt-0.5 size-5 shrink-0 text-slate-400" />
            <p>
                {{ $num($summary['removed']) }} SKU sudah tidak ada lagi di toko (produk / variasi dihapus di marketplace). Barisnya disembunyikan dan stoknya tidak dikirim.
                <a href="{{ $route(['status' => 'removed'], ['status']) }}" class="font-medium text-slate-900 underline underline-offset-2">Lihat</a>
            </p>
        </div>
    @endif

    <div class="card">
        <form method="GET" class="flex flex-col gap-3 border-b border-slate-100 p-4 lg:flex-row lg:items-center">
            @if ($platform)
                <input type="hidden" name="platform" value="{{ $platform }}">
            @endif
            <div class="relative flex-1">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-slate-400" />
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari nama produk, variasi, SKU channel atau ID produk..." class="form-input pl-8">
            </div>
            <select name="shop" class="form-input lg:w-56" onchange="this.form.submit()">
                <option value="">Semua toko</option>
                @foreach ($options as $option)
                    <option value="{{ $option['key'] }}" @selected(($shop['key'] ?? null) === $option['key'])>{{ $option['platform'] === 'shopee' ? 'Shopee' : 'TikTok' }} · {{ $option['shop']->displayName() }}{{ ($option['shop']->environment ?? null) === 'test' ? ' (test)' : '' }}</option>
                @endforeach
            </select>
            <select name="status" class="form-input lg:w-52" onchange="this.form.submit()">
                <option value="">Semua pemetaan</option>
                @foreach (Channel::STATUSES as $value => $label)
                    <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <select name="listing" class="form-input lg:w-40" onchange="this.form.submit()">
                <option value="">Semua status toko</option>
                @foreach (Channel::LISTING_STATUSES as $value => $label)
                    <option value="{{ $value }}" @selected(request('listing') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <div class="flex gap-2">
                <button type="submit" class="btn-secondary"><x-heroicon-o-funnel class="size-5" /> Filter</button>
                @if (request()->hasAny(['search', 'status', 'shop', 'listing']))
                    <a href="{{ route('marketplace.channel-products', array_filter(['platform' => $platform])) }}" class="btn-icon" title="Reset filter"><x-heroicon-o-x-mark class="size-5" /></a>
                @endif
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                    <tr>
                        <th class="h-8 px-4 py-0 font-normal">Produk & <span class="whitespace-nowrap">variasi <x-hint text="Produk seperti tampil di toko marketplace. Klik namanya untuk membuka etalase di tab baru. Baris di bawahnya adalah variasi / SKU channel produk itu." /></span></th>
                        <th class="h-8 px-4 py-0 font-normal">SKU <span class="whitespace-nowrap">channel <x-hint text="SKU yang diisi penjual di marketplace untuk variasi ini. Bila sama dengan SKU Optima, otomatis terhubung saat Sinkron Produk / Cocokkan Otomatis." /></span></th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Harga</th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Stok di <span class="whitespace-nowrap">toko <x-hint text="Stok variasi ini di marketplace: dari tarik produk terakhir, atau sama dengan stok terakhir yang berhasil dikirim Optima." /></span></th>
                        <th class="h-8 px-4 py-0 font-normal">SKU <span class="whitespace-nowrap">Optima <x-hint text="Varian atau paket di Optima yang terhubung ke variasi ini. Kosong = belum terhubung: stoknya tidak dikirim dan pesanannya tertahan di Draft." /></span></th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Stok <span class="whitespace-nowrap">Optima <x-hint text="Stok jual sekarang di gudang toko (termasuk jatah gudang cadangan, dikurangi pesanan belum bayar). Angka inilah yang dikirim ke toko saat sinkron stok." /></span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($listings as $listing)
                        @php
                            $key = "{$listing->platform}-{$listing->shop_id}-{$listing->listing_id}";
                            $variants = $rows[$key] ?? collect();
                            $first = $variants->first();
                            $shopModel = $first?->shop;
                            $url = $first?->storefrontUrl();
                            $live = in_array($listing->channel_status, Channel::LIVE_STATUSES, true);
                            $isShopee = $listing->platform === 'shopee';
                        @endphp
                        <tr class="border-t border-slate-200 bg-slate-50/60" data-testid="channel-listing">
                            <td colspan="6" class="px-4 py-2.5">
                                <div class="flex items-start gap-3">
                                    @if ($url)
                                        <a href="{{ $url }}" target="_blank" rel="noopener" class="flex size-12 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-slate-200 bg-white text-slate-300" title="Buka etalase di {{ $isShopee ? 'Shopee' : 'TikTok Shop' }}">
                                            @if ($listing->image_url)
                                                <img src="{{ $listing->image_url }}" alt="" loading="lazy" class="size-full object-cover">
                                            @else
                                                <x-marketplace-logo :platform="$listing->platform" class="size-6 opacity-60" />
                                            @endif
                                        </a>
                                    @endif
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                            @if ($url)
                                                <a href="{{ $url }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 font-medium text-slate-900 hover:underline"
                                                   data-hint-title="Buka etalase" data-hint="Membuka halaman produk ini di {{ $isShopee ? 'Shopee' : 'TikTok Shop' }} (tab baru), seperti yang dilihat pembeli.">
                                                    {{ $listing->name ?? ($isShopee ? 'Item ' : 'Produk ').$listing->listing_id }}
                                                    <x-heroicon-o-arrow-top-right-on-square class="size-4 text-slate-400" />
                                                </a>
                                            @else
                                                <span class="font-medium text-slate-900">{{ $listing->name ?? 'Produk '.$listing->listing_id }}</span>
                                            @endif
                                            @if ($listing->channel_status)
                                                <span @class(['rounded-full px-2 py-0.5 text-[11px] font-medium', 'bg-emerald-50 text-emerald-700' => $live, 'bg-slate-200 text-slate-600' => ! $live])
                                                      data-hint-title="Status di toko" data-hint="Status produk di marketplace saat terakhir ditarik: {{ $listing->channel_status }}.">{{ $live ? 'Tayang' : 'Tidak tayang' }}</span>
                                            @endif
                                            @if ($listing->unmapped > 0 && request('status') !== 'removed')
                                                <span class="rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-700">{{ $num($listing->unmapped) }} belum terhubung</span>
                                            @endif
                                            @if ($listing->failed > 0)
                                                <span class="rounded-full bg-rose-50 px-2 py-0.5 text-[11px] font-medium text-rose-700">{{ $num($listing->failed) }} gagal kirim stok</span>
                                            @endif
                                        </div>
                                        <p class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-slate-500">
                                            <x-marketplace.platform-badge :platform="$listing->platform" />
                                            @if ($shopModel)
                                                <a href="{{ route($listing->platform.'.mappings.index', $shopModel) }}" class="hover:text-slate-700">{{ $shopModel->displayName() }}</a>
                                                @if (($shopModel->environment ?? null) === 'test')<span class="text-amber-600">test</span>@endif
                                            @endif
                                            <span>· ID <span class="font-mono">{{ $listing->listing_id }}</span></span>
                                            <span>· {{ $num($listing->skus) }} variasi</span>
                                        </p>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        @foreach ($variants as $mapping)
                            @php
                                $sku = $isShopee ? $mapping->shopee_sku : $mapping->seller_sku;
                                $variantName = $isShopee ? $mapping->model_name : $mapping->sku_name;
                                $variantId = $isShopee ? ($mapping->model_id ?: null) : $mapping->sku_id;
                                $shopKey = $isShopee ? $mapping->shopee_shop_id : $mapping->tiktok_shop_id;
                            @endphp
                            <tr class="border-t border-slate-100 hover:bg-slate-50/60" data-testid="channel-sku">
                                <td class="py-2 pr-4 pl-10 lg:pl-[4.75rem]">
                                    <p class="text-slate-800">{{ $variantName ?? ($listing->skus > 1 ? 'Variasi '.$variantId : 'Tanpa variasi') }}</p>
                                    @if ($variantId)
                                        <p class="font-mono text-xs text-slate-400">{{ $variantId }}</p>
                                    @endif
                                    @if ($mapping->isRemoved())
                                        <span class="mt-1 inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">Tidak ada di toko sejak {{ $mapping->removed_at->format('d/m/Y') }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 font-mono text-[13px] text-slate-700">
                                    {{ $sku ?? '—' }}
                                    @if ($mapping->looksLikeBundle())
                                        <span class="ml-1 inline-flex cursor-help rounded-full bg-amber-50 px-2 py-0.5 font-sans text-xs text-amber-700" data-hint-title="Mirip paket" data-hint="SKU ini terlihat seperti paket, tapi dipetakan ke produk biasa. Bila memang paket, pilih Produk Paket di kolom SKU Optima.">Mirip paket</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums whitespace-nowrap text-slate-700">{{ $mapping->channel_price !== null ? $money($mapping->channel_price) : '—' }}</td>
                                <td class="px-4 py-2 text-right tabular-nums whitespace-nowrap">
                                    @if ($mapping->channel_stock !== null)
                                        <span class="{{ $mapping->channel_stock > 0 ? 'text-slate-900' : 'text-rose-600' }}">{{ $num($mapping->channel_stock) }}</span>
                                    @else
                                        <span class="text-slate-400" data-hint-title="Belum diketahui" data-hint="Stok di toko baru terisi setelah Sinkron Produk berikutnya.">—</span>
                                    @endif
                                    @if ($mapping->stock_error)
                                        @php($why = StockPushError::explain($listing->platform, $mapping->stock_error))
                                        <p class="mt-0.5 max-w-48 cursor-help text-left text-xs text-rose-600" data-hint-title="{{ $why['title'] }}" data-hint="{{ $mapping->stock_error }}" data-hint-next="{{ $why['fix'] }}">Gagal kirim stok</p>
                                    @endif
                                </td>
                                <td class="px-4 py-2">
                                    @if ($canMap->contains($listing->platform) && ! $mapping->isRemoved())
                                        <form method="POST" action="{{ route($listing->platform.'.mappings.update', [$shopKey, $mapping]) }}" class="flex items-center gap-2">
                                            @csrf @method('PUT')
                                            <select name="target" class="form-input w-64" onchange="this.form.submit()" aria-label="SKU Optima"
                                                    data-remote="{{ route($listing->platform.'.mappings.lookup') }}" data-placeholder="Cari SKU / nama varian / paket">
                                                <option value="">Belum terhubung</option>
                                                @if ($mapping->variant)
                                                    <option value="{{ $mapping->targetKey() }}" selected>{{ $mapping->variant->displayName() }}</option>
                                                @elseif ($mapping->bundle)
                                                    <option value="{{ $mapping->targetKey() }}" selected>Paket · {{ $mapping->bundle->displayName() }}</option>
                                                @endif
                                            </select>
                                            @if ($mapping->match_type === 'auto')
                                                <span class="cursor-help rounded-full bg-sky-50 px-2 py-0.5 text-xs text-sky-700" data-hint-title="Otomatis" data-hint="Dicocokkan otomatis karena SKU channel sama dengan SKU varian / paket di Optima.">Otomatis</span>
                                            @endif
                                        </form>
                                    @elseif ($mapping->isMapped())
                                        <span class="inline-flex items-center gap-1 text-slate-800"><x-heroicon-o-check-circle class="size-4 text-emerald-600" /> {{ $mapping->variant?->displayName() ?? 'Paket · '.$mapping->bundle?->displayName() }}</span>
                                    @else
                                        <span class="inline-flex items-center gap-1 text-xs text-amber-700"><x-heroicon-o-minus-circle class="size-4" /> Belum terhubung</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums whitespace-nowrap">
                                    @if (($mapping->optima_stock ?? null) !== null)
                                        <span class="{{ $mapping->optima_stock > 0 ? 'text-slate-900' : 'text-rose-600' }}">{{ $num($mapping->optima_stock) }}</span>
                                        @if ($mapping->channel_stock !== null && $mapping->channel_stock !== (int) $mapping->optima_stock)
                                            <p class="text-xs text-amber-600" data-hint-title="Belum sama" data-hint="Stok di toko berbeda dengan stok Optima; akan disamakan pada sinkron stok berikutnya.">beda {{ $mapping->optima_stock - $mapping->channel_stock > 0 ? '+' : '' }}{{ $num($mapping->optima_stock - $mapping->channel_stock) }}</p>
                                        @endif
                                    @else
                                        <span class="text-slate-400">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    @empty
                        <x-empty-state colspan="6" icon="heroicon-o-squares-2x2" title="Belum ada produk channel"
                                       description="Klik Sinkron Produk untuk menarik produk dari toko Shopee dan TikTok yang terhubung." />
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($listings->hasPages())
            <div class="border-t border-slate-100 px-5 py-4">{{ $listings->links() }}</div>
        @endif
    </div>
</x-layouts.app>
