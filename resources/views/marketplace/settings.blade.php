<x-layouts.app title="Pengaturan Marketplace">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('settings') }}" class="hover:text-slate-700">Pengaturan</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Pengaturan Marketplace</span>
    </x-slot:breadcrumb>

    <x-page-header title="Pengaturan Marketplace" description="Kredensial app Shopee Open Platform dan TikTok Shop Partner Center untuk toko-toko milik sendiri." icon="heroicon-o-key">
        @can('marketplace.view')
            <a href="{{ route('marketplace.shops', ['platform' => $tab]) }}" class="btn-secondary"><x-heroicon-o-building-storefront class="size-5" /> Toko Marketplace</a>
        @endcan
    </x-page-header>

    <div class="max-w-4xl">
        <div class="mb-6 flex gap-1 border-b border-slate-200">
            @foreach ($tabs as $key => $meta)
                @php($done = $key === 'shopee' ? ($shopee->isConfigured('test') || $shopee->isConfigured('live')) : $tiktok->isConfigured())
                <a href="{{ route('marketplace.settings', ['tab' => $key]) }}"
                   @class(['-mb-px inline-flex items-center gap-2 border-b-2 px-3 py-2 text-sm', 'border-slate-900 font-medium text-slate-900' => $tab === $key, 'border-transparent text-slate-500 hover:text-slate-700' => $tab !== $key])>
                    @if ($key === 'shopee')
                        <x-heroicon-o-shopping-bag class="size-4" />
                    @else
                        <x-heroicon-o-musical-note class="size-4" />
                    @endif
                    <span>{{ $meta['label'] }}</span>
                    @if ($done)
                        <x-heroicon-s-check-circle class="size-4 text-emerald-600" title="Kredensial lengkap" />
                    @else
                        <span class="size-2 rounded-full bg-slate-300" title="Belum diisi"></span>
                    @endif
                    <span class="text-xs text-slate-400">{{ $shopCounts[$key] }} toko</span>
                </a>
            @endforeach
        </div>

        @if ($tab === 'shopee')
            @include('marketplace.settings._shopee')
        @elseif ($tab === 'tiktok')
            @include('marketplace.settings._tiktok')
        @endif
    </div>
</x-layouts.app>
