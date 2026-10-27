{{--
    Pencarian global (Ctrl+K / Cmd+K, atau "/"): halaman & aksi cepat disaring di browser,
    produk/SKU, pesanan, resi, retur, penerimaan, mutasi, opname, lokasi & supplier dicari
    lewat route('search'). Logika di resources/js/search.js.
--}}
@props(['navigation' => [], 'settings' => []])

@php
    $user = auth()->user();

    $pages = collect($navigation)
        ->flatMap(fn ($section) => collect($section['items'])
            ->filter(fn ($item) => ! $item['can'] || $user->canAny((array) $item['can']))
            ->map(fn ($item) => [
                'kind' => 'page',
                'title' => $item['label'],
                'subtitle' => $section['heading'] ?? 'Menu',
                'url' => route($item['route']),
            ]))
        // Halaman di dalam Pengaturan tetap bisa dicari langsung (Profil Saya termasuk di sini).
        ->concat(collect($settings)->flatMap(fn ($group) => collect($group['items'])->map(fn ($item) => [
            'kind' => 'page',
            'title' => $item['label'],
            'subtitle' => 'Pengaturan · '.$group['heading'],
            'url' => route($item['route']),
            'keywords' => $item['keywords'] ?? '',
        ])));

    $actions = [
        ['Buat Pesanan Baru', 'sales.orders.create', 'sales.create', 'tambah order so'],
        ['Impor Pesanan dari Excel', 'sales.orders.import', 'sales.create', 'upload csv'],
        ['Buat Retur Penjualan', 'sales.returns.create', 'returns.create', 'tambah scan resi'],
        ['Buat Penerimaan Barang', 'inventory.receipts.create', 'receipts.create', 'tambah barang masuk gr'],
        ['Buat Transfer Lokasi', 'inventory.movements.create', 'inventory.create', 'tambah mutasi stok pindah bin'],
        ['Buat Penyesuaian Stok', 'inventory.adjustments.create', 'adjustments.create', 'tambah koreksi selisih adjustment'],
        ['Buat Stock Opname', 'inventory.counts.create', 'opname.create', 'tambah hitung'],
        ['Tambah Produk', 'master.products.create', 'products.create', 'baru sku'],
        ['Impor Produk dari Excel', 'master.products.import', 'products.create', 'upload'],
        ['Tambah Supplier', 'master.suppliers.create', 'suppliers.create', 'baru'],
    ];

    foreach ($actions as [$label, $route, $permission, $keywords]) {
        if ($user->canAny((array) $permission)) {
            $pages->push(['kind' => 'action', 'title' => $label, 'subtitle' => 'Aksi cepat', 'url' => route($route), 'keywords' => $keywords]);
        }
    }

    $icons = [
        'pages' => 'heroicon-o-document-text',
        'products' => 'heroicon-o-cube',
        'bundles' => 'heroicon-o-gift',
        'orders' => 'heroicon-o-shopping-cart',
        'returns' => 'heroicon-o-arrow-uturn-left',
        'receipts' => 'heroicon-o-inbox-arrow-down',
        'movements' => 'heroicon-o-arrows-right-left',
        'adjustments' => 'heroicon-o-adjustments-horizontal',
        'counts' => 'heroicon-o-clipboard-document-check',
        'locations' => 'heroicon-o-map-pin',
        'suppliers' => 'heroicon-o-building-storefront',
    ];
@endphp

<div x-data="globalSearch({{ Js::from(['endpoint' => route('search'), 'pages' => $pages->values()]) }})" class="contents">
    {{-- Pemicu di topbar --}}
    <button type="button" @click="show()" data-testid="global-search-trigger"
            class="btn-icon sm:hidden" title="Cari (Ctrl+K)" aria-label="Cari">
        <x-heroicon-o-magnifying-glass />
    </button>
    <button type="button" @click="show()"
            class="hidden h-7 w-56 items-center gap-2 rounded-lg bg-slate-100 px-2 text-left text-[13px] text-slate-500 transition hover:bg-slate-200/70 sm:flex lg:w-64"
            title="Cari produk, SKU, pesanan, resi... (Ctrl+K)">
        <x-heroicon-o-magnifying-glass class="size-4 shrink-0" />
        <span class="flex-1 truncate">Cari produk, pesanan, resi...</span>
        <kbd class="rounded border border-slate-300 bg-white px-1 font-sans text-[10px] leading-4 text-slate-500">Ctrl K</kbd>
    </button>

    {{-- Palet pencarian --}}
    <template x-teleport="body">
        <div x-cloak x-show="open" class="fixed inset-0 z-[60]" @keydown.escape.prevent.stop="close()">
            <div x-show="open" x-transition.opacity class="absolute inset-0 bg-black/40" @click="close()"></div>

            <div class="relative mx-auto mt-[8vh] w-full max-w-xl px-3 sm:mt-[12vh]">
                <div x-show="open" x-transition.origin.top role="dialog" aria-modal="true" aria-label="Pencarian global"
                     class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl">
                    <div class="flex items-center gap-2 border-b border-slate-200 px-3">
                        <x-heroicon-o-magnifying-glass class="size-4 shrink-0 text-slate-500" />
                        <input type="text" x-ref="input" x-model="query" autocomplete="off" spellcheck="false"
                               placeholder="Cari produk, SKU, barcode, pesanan, resi, retur, supplier..."
                               @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)"
                               @keydown.enter.prevent="go($event)" @keydown.tab.prevent="move($event.shiftKey ? -1 : 1)"
                               role="combobox" aria-expanded="true" aria-controls="global-search-results"
                               :aria-activedescendant="total ? 'global-search-item-' + active : null"
                               class="h-11 min-w-0 flex-1 border-0 bg-transparent px-0 text-sm text-slate-900 placeholder:text-slate-400 focus:ring-0 focus:outline-none">
                        <svg x-show="loading" class="size-4 shrink-0 animate-spin text-slate-400" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" class="opacity-25" />
                            <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
                        </svg>
                        <kbd class="hidden rounded border border-slate-300 px-1 text-[10px] leading-4 text-slate-500 sm:inline">Esc</kbd>
                    </div>

                    <div x-ref="list" id="global-search-results" role="listbox" class="max-h-[60vh] overflow-y-auto p-1.5 [&_mark]:rounded-sm [&_mark]:bg-brand-100 [&_mark]:text-inherit">
                        <template x-for="section in sections" :key="section.key">
                            <div class="mb-1 last:mb-0">
                                <p class="px-2 pt-2 pb-1 text-xs text-slate-500" x-text="section.label"></p>
                                <template x-for="item in section.items" :key="item.index">
                                    <a :href="item.url" :id="'global-search-item-' + item.index" :data-index="item.index" role="option"
                                       :aria-selected="active === item.index" @mousemove="active = item.index" @click="close()"
                                       :class="active === item.index ? 'bg-slate-100' : ''"
                                       class="flex items-center gap-2.5 rounded-lg px-2 py-1.5">
                                        <span class="flex size-7 shrink-0 items-center justify-center rounded-md border border-slate-200 text-slate-500 [&_svg]:size-4">
                                            @foreach ($icons as $key => $icon)
                                                <x-dynamic-component :component="$icon" x-show="section.key === '{{ $key }}' && item.kind !== 'action'" />
                                            @endforeach
                                            <x-heroicon-o-plus x-show="item.kind === 'action'" />
                                        </span>
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate text-[13px] font-medium text-slate-900" x-html="highlight(item.title)"></span>
                                            <span x-show="item.subtitle" class="block truncate text-xs text-slate-500" x-html="highlight(item.subtitle)"></span>
                                        </span>
                                        <span x-show="item.badge" x-text="item.badge" class="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600"></span>
                                        <x-heroicon-o-arrow-turn-down-left x-show="active === item.index" class="hidden size-3.5 shrink-0 text-slate-400 sm:block" />
                                    </a>
                                </template>
                            </div>
                        </template>

                        <p x-show="tooShort" class="px-2 py-6 text-center text-sm text-slate-500">Ketik minimal 2 karakter untuk mencari data.</p>
                        <div x-show="empty" class="flex flex-col items-center gap-1.5 px-2 py-8 text-center text-sm text-slate-500" data-testid="global-search-empty">
                            <x-heroicon-o-magnifying-glass class="size-6 text-slate-400" />
                            <span>Tidak ada hasil untuk "<span class="text-slate-900" x-text="query.trim()"></span>".</span>
                            <span class="text-xs">Coba SKU, barcode, no. pesanan, resi, atau nama pelanggan.</span>
                        </div>
                        <p x-show="failed" class="px-2 py-6 text-center text-sm text-rose-600">Pencarian gagal. Periksa koneksi lalu coba lagi.</p>
                    </div>

                    <div class="hidden items-center gap-4 border-t border-slate-200 px-3 py-2 text-xs text-slate-500 sm:flex">
                        <span><kbd class="font-sans">↑</kbd> <kbd class="font-sans">↓</kbd> pilih</span>
                        <span><kbd class="font-sans">Enter</kbd> buka</span>
                        <span><kbd class="font-sans">Ctrl+Enter</kbd> tab baru</span>
                        <span class="ml-auto">Hanya data gudang yang Anda akses</span>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>
