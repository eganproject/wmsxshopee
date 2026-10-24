@props(['title' => null])

@php
    $navigation = [
        [
            'heading' => null,
            'items' => [
                ['label' => 'Dashboard', 'route' => 'dashboard', 'active' => 'dashboard', 'icon' => 'heroicon-o-squares-2x2', 'can' => null],
                ['label' => 'Notifikasi', 'route' => 'notifications.index', 'active' => 'notifications.*', 'icon' => 'heroicon-o-bell', 'can' => null],
            ],
        ],
        [
            'heading' => 'Master Data',
            'items' => [
                ['label' => 'Gudang & Lokasi', 'route' => 'master.warehouses.index', 'active' => ['master.warehouses.*', 'master.zones.*', 'master.racks.*', 'master.locations.*'], 'icon' => 'heroicon-o-building-office-2', 'can' => 'warehouses.view'],
                ['label' => 'Produk', 'route' => 'master.products.index', 'active' => ['master.products.*', 'master.bundles.*', 'master.units.*'], 'icon' => 'heroicon-o-cube', 'can' => 'products.view'],
                ['label' => 'Kurir', 'route' => 'master.couriers.index', 'active' => 'master.couriers.*', 'icon' => 'heroicon-o-truck', 'can' => 'couriers.view'],
                ['label' => 'Supplier', 'route' => 'master.suppliers.index', 'active' => 'master.suppliers.*', 'icon' => 'heroicon-o-building-storefront', 'can' => 'suppliers.view'],
            ],
        ],
        [
            'heading' => 'Penjualan',
            'items' => [
                ['label' => 'Pesanan', 'route' => 'sales.orders.index', 'active' => 'sales.orders.*', 'icon' => 'heroicon-o-shopping-cart', 'can' => 'sales.view'],
                ['label' => 'QC Kemas', 'route' => 'sales.qc.index', 'active' => 'sales.qc.*', 'icon' => 'heroicon-o-viewfinder-circle', 'can' => 'sales.fulfill'],
                ['label' => 'Serah Terima', 'route' => 'sales.handovers.index', 'active' => 'sales.handovers.*', 'icon' => 'heroicon-o-paper-airplane', 'can' => 'sales.fulfill'],
                ['label' => 'Retur Penjualan', 'route' => 'sales.returns.index', 'active' => 'sales.returns.*', 'icon' => 'heroicon-o-arrow-uturn-left', 'can' => 'returns.view'],
            ],
        ],
        [
            'heading' => 'Inventori',
            'items' => [
                ['label' => 'Penerimaan Barang', 'route' => 'inventory.receipts.index', 'active' => 'inventory.receipts.*', 'icon' => 'heroicon-o-inbox-arrow-down', 'can' => 'receipts.view'],
                // Kartu Stok & Mutasi Stok adalah tab di halaman Stok, jadi menu Stok tetap aktif di sana.
                ['label' => 'Stok', 'route' => 'inventory.stocks.index', 'active' => ['inventory.stocks.*', 'inventory.stock-card.*', 'inventory.movements.*'], 'icon' => 'heroicon-o-archive-box', 'can' => 'inventory.view'],
                ['label' => 'Transfer Gudang', 'route' => 'inventory.transfers.index', 'active' => 'inventory.transfers.*', 'icon' => 'heroicon-o-truck', 'can' => 'inventory.view'],
                ['label' => 'Stok Rusak', 'route' => 'inventory.damaged.index', 'active' => 'inventory.damaged.*', 'icon' => 'heroicon-o-exclamation-triangle', 'can' => 'damaged.view'],
                ['label' => 'Stock Opname', 'route' => 'inventory.counts.index', 'active' => 'inventory.counts.*', 'icon' => 'heroicon-o-clipboard-document-check', 'can' => 'opname.view'],
                // Label produk butuh hak lihat produk, label lokasi butuh hak lihat gudang.
                ['label' => 'Cetak Label', 'route' => auth()->user()?->can('products.view') ? 'labels.products' : 'labels.locations', 'active' => 'labels.*', 'icon' => 'heroicon-o-qr-code', 'can' => ['products.view', 'warehouses.view']],
            ],
        ],
        [
            'heading' => 'Laporan',
            'items' => [
                ['label' => 'Stok per Lokasi', 'route' => 'reports.stock-locations', 'active' => 'reports.stock-locations', 'icon' => 'heroicon-o-map-pin', 'can' => 'reports.view'],
                ['label' => 'Stok Minimum', 'route' => 'reports.min-stock', 'active' => 'reports.min-stock', 'icon' => 'heroicon-o-bell-alert', 'can' => 'reports.view'],
                ['label' => 'Saran Pembelian', 'route' => 'reports.reorder', 'active' => 'reports.reorder', 'icon' => 'heroicon-o-shopping-bag', 'can' => 'reports.view'],
                ['label' => 'Pergerakan Barang', 'route' => 'reports.movements', 'active' => 'reports.movements', 'icon' => 'heroicon-o-presentation-chart-line', 'can' => 'reports.view'],
                ['label' => 'Barang Hilang', 'route' => 'reports.lost-items', 'active' => 'reports.lost-items', 'icon' => 'heroicon-o-question-mark-circle', 'can' => 'reports.view'],
            ],
        ],
        [
            'heading' => 'Integrasi',
            'items' => [
                ['label' => 'Toko Marketplace', 'route' => 'marketplace.shops', 'active' => ['marketplace.shops', 'shopee.mappings.*', 'tiktok.mappings.*'], 'icon' => 'heroicon-o-building-storefront', 'can' => ['shopee.view', 'tiktok.view']],
                ['label' => 'Status Produk', 'route' => 'marketplace.products', 'active' => 'marketplace.products', 'icon' => 'heroicon-o-check-badge', 'can' => ['shopee.view', 'tiktok.view']],
                ['label' => 'Riwayat Sinkron', 'route' => 'marketplace.sync-logs', 'active' => 'marketplace.sync-logs', 'icon' => 'heroicon-o-arrow-path', 'can' => ['shopee.view', 'tiktok.view']],
            ],
        ],
        [
            'heading' => 'Administrasi',
            'items' => [
                // Semua menu pengaturan dikumpulkan di halaman Pengaturan (App\Support\SettingsMenu).
                ['label' => 'Pengaturan', 'route' => 'settings', 'active' => \App\Support\SettingsMenu::activePatterns(), 'icon' => 'heroicon-o-cog-6-tooth', 'can' => null],
            ],
        ],
    ];

    $user = auth()->user();
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-white">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>

    <meta name="application-name" content="{{ config('app.name') }}">
    <meta name="description" content="{{ config('app.name') }} · {{ config('app.tagline') }}">
    <meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#1c1c1c" media="(prefers-color-scheme: dark)">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />

    <x-theme-script />
    {{-- Sidebar diciutkan (khusus layar lebar) dipasang sebelum tampil agar tidak bergeser. --}}
    <script>
        try { if (localStorage.getItem('sidebar') === 'collapsed') document.documentElement.classList.add('sidebar-collapsed'); } catch (e) {}
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full font-sans text-sm text-slate-900 antialiased" x-data="{ sidebarOpen: false }" @keydown.escape.window="sidebarOpen = false">
    <div id="page-progress" aria-hidden="true"></div>

    {{-- Backdrop mobile --}}
    <div x-cloak x-show="sidebarOpen" x-transition.opacity @click="sidebarOpen = false"
         class="fixed inset-0 z-40 bg-black/40 lg:hidden"></div>

    {{-- Sidebar --}}
    <aside class="fixed inset-y-0 left-0 z-50 flex w-60 -translate-x-full flex-col border-r border-slate-200 bg-slate-50 p-2 transition-[transform,width] duration-200 lg:translate-x-0 lg:collapsed:w-14"
           :class="sidebarOpen && 'translate-x-0'" x-data="sidebarTips">
        <div class="flex items-center justify-between gap-2 px-1.5 pt-1 pb-2 lg:collapsed:justify-center lg:collapsed:px-0">
            <a href="{{ route('dashboard') }}" class="flex min-w-0 items-center gap-2 rounded-lg p-1 hover:bg-slate-100" data-tip="Dashboard">
                <x-app-logo class="size-7 shrink-0" />
                <span class="min-w-0 leading-tight lg:collapsed:hidden">
                    <span class="block truncate text-sm font-medium text-slate-900">{{ config('app.name') }}</span>
                    <span class="block truncate text-xs text-slate-500">{{ config('app.tagline') }}</span>
                </span>
            </a>
            <button type="button" class="btn-icon lg:hidden" @click="sidebarOpen = false" aria-label="Tutup menu">
                <x-heroicon-o-x-mark />
            </button>
        </div>

        <nav id="sidebar-nav" class="sidebar-scroll flex-1 overflow-x-hidden overflow-y-auto">
            @foreach ($navigation as $section)
                @php($items = collect($section['items'])->filter(fn ($item) => ! $item['can'] || $user->canAny((array) $item['can'])))
                @if ($items->isNotEmpty())
                    <div class="mt-1">
                        @if ($section['heading'])
                            <p class="px-2 pt-3 pb-1 text-xs whitespace-nowrap text-slate-500 lg:collapsed:hidden">{{ $section['heading'] }}</p>
                            <hr class="mx-2 my-2 hidden border-slate-200 lg:collapsed:block">
                        @endif
                        <ul class="space-y-px">
                            @foreach ($items as $item)
                                @php($active = request()->routeIs($item['active']))
                                <li>
                                    <a href="{{ route($item['route']) }}" data-tip="{{ $item['label'] }}" @if ($active) aria-current="page" @endif
                                       @class([
                                           'flex h-7 items-center gap-2 rounded-lg px-2 text-[13px] whitespace-nowrap transition lg:collapsed:justify-center lg:collapsed:px-0',
                                           'bg-white font-medium text-slate-900 shadow-sm ring-1 ring-slate-200' => $active,
                                           'text-slate-700 hover:bg-slate-100' => ! $active,
                                       ])>
                                        <x-dynamic-component :component="$item['icon']" @class(['size-4 shrink-0', 'text-slate-900' => $active, 'text-slate-500' => ! $active]) />
                                        <span class="truncate lg:collapsed:sr-only">{{ $item['label'] }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            @endforeach
        </nav>
        {{-- Pulihkan posisi scroll menu sebelum tampil agar tidak melompat ke atas tiap pindah halaman. --}}
        <script>
            (() => {
                const nav = document.getElementById('sidebar-nav');
                let saved = null;
                try { saved = sessionStorage.getItem('sidebar-scroll'); } catch (e) {}
                if (saved !== null) nav.scrollTop = +saved;
                const current = nav.querySelector('[aria-current="page"]');
                if (current) {
                    const box = nav.getBoundingClientRect(), item = current.getBoundingClientRect();
                    if (item.top < box.top || item.bottom > box.bottom) nav.scrollTop += item.top - box.top - (box.height - item.height) / 2;
                }
                addEventListener('pagehide', () => {
                    try { sessionStorage.setItem('sidebar-scroll', String(Math.round(nav.scrollTop))); } catch (e) {}
                });
            })();
        </script>

        <a href="{{ route('profile.edit') }}" class="flex items-center gap-2 rounded-lg p-1.5 hover:bg-slate-100 lg:collapsed:justify-center lg:collapsed:px-0" data-tip="{{ $user->name }}">
            <x-avatar :user="$user" class="size-7 shrink-0 text-xs" />
            <span class="min-w-0 flex-1 leading-tight lg:collapsed:hidden">
                <span class="block truncate text-[13px] font-medium text-slate-900">{{ $user->name }}</span>
                <span class="block truncate text-xs text-slate-500">{{ $user->roles->pluck('name')->join(', ') ?: 'Tanpa role' }}</span>
            </span>
        </a>
    </aside>

    {{-- Tooltip nama menu saat sidebar diciutkan --}}
    <div id="sidebar-tip" role="tooltip" class="pointer-events-none fixed z-[90] hidden -translate-y-1/2 rounded-md bg-slate-900 px-2 py-1 text-xs whitespace-nowrap text-white shadow-lg"></div>

    {{-- Tooltip penjelasan untuk elemen [data-hint] (Alpine hintTips) --}}
    <div id="hint-tip" role="tooltip" class="pointer-events-none fixed z-[90] hidden w-72 max-w-[calc(100vw-1.5rem)] rounded-lg border border-slate-200 bg-white p-3 text-left opacity-0 shadow-lg shadow-slate-900/10 transition-opacity duration-150">
        <span data-part="arrow" class="absolute size-2.5 rotate-45 border-t border-l border-slate-200 bg-white"></span>
        <p data-part="title" class="relative text-[13px] font-semibold text-slate-900"></p>
        <p data-part="body" class="relative mt-1 text-xs leading-relaxed text-slate-600"></p>
        <p data-part="next" class="relative mt-2 flex gap-1.5 border-t border-slate-100 pt-2 text-xs leading-relaxed text-slate-700">
            <x-heroicon-o-arrow-right-circle class="mt-px size-4 shrink-0 text-brand-600" />
            <span data-part="next-text"></span>
        </p>
    </div>

    <div class="transition-[padding] duration-200 lg:pl-60 lg:collapsed:pl-14">
        {{-- Topbar --}}
        <header class="sticky top-0 z-30 flex h-12 items-center gap-3 border-b border-slate-200 bg-white px-4 sm:px-5">
            <button type="button" class="btn-icon -ml-1.5 lg:hidden" @click="sidebarOpen = true" aria-label="Buka menu">
                <x-heroicon-o-bars-3 />
            </button>
            <button type="button" class="btn-icon -ml-1.5 hidden lg:inline-flex" x-data="sidebarToggle" @click="toggle()"
                    @keydown.ctrl.b.window.prevent="toggle()" :title="(collapsed ? 'Lebarkan' : 'Ciutkan') + ' sidebar (Ctrl+B)'" :aria-label="collapsed ? 'Lebarkan sidebar' : 'Ciutkan sidebar'" :aria-pressed="collapsed">
                <x-heroicon-o-chevron-double-left x-show="! collapsed" />
                <x-heroicon-o-chevron-double-right x-show="collapsed" x-cloak />
            </button>

            <div class="min-w-0 flex-1">
                @isset($breadcrumb)
                    <nav class="flex items-center gap-1.5 text-sm text-slate-500 [&_svg]:size-3.5 [&_svg]:shrink-0">{{ $breadcrumb }}</nav>
                @endisset
            </div>

            <x-global-search :navigation="$navigation" :settings="\App\Support\SettingsMenu::visibleGroups($user)" />

            @can('marketplace.view')
                <x-marketplace-shops-menu />
            @endcan

            <x-notification-bell />

            <button type="button" class="btn-icon" x-data="themeToggle" @click="cycle()" :title="labels[theme]" :aria-label="labels[theme]">
                <x-heroicon-o-sun x-show="theme === 'light'" x-cloak />
                <x-heroicon-o-moon x-show="theme === 'dark'" x-cloak />
                <x-heroicon-o-computer-desktop x-show="theme === 'system'" x-cloak />
            </button>

            <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                <button type="button" @click="open = !open" class="flex items-center gap-1.5 rounded-lg p-1 hover:bg-slate-100" aria-label="Menu akun">
                    <x-avatar :user="$user" class="size-7 text-xs" />
                    <x-heroicon-o-chevron-down class="hidden size-3.5 text-slate-500 sm:block" />
                </button>

                <div x-cloak x-show="open" x-transition.origin.top.right
                     class="absolute right-0 mt-1.5 w-56 overflow-hidden rounded-xl border border-slate-200 bg-white p-1 shadow-lg">
                    <div class="px-2.5 py-2">
                        <p class="truncate text-sm font-medium text-slate-900">{{ $user->name }}</p>
                        <p class="truncate text-xs text-slate-500">{{ $user->email }}</p>
                    </div>
                    <div class="my-1 border-t border-slate-100"></div>
                    <a href="{{ route('profile.edit') }}" class="flex h-7 items-center gap-2 rounded-lg px-2.5 text-sm text-slate-700 hover:bg-slate-100">
                        <x-heroicon-o-user-circle class="size-4 text-slate-500" /> Profil Saya
                    </a>
                    <a href="{{ route('settings') }}" class="flex h-7 items-center gap-2 rounded-lg px-2.5 text-sm text-slate-700 hover:bg-slate-100">
                        <x-heroicon-o-cog-6-tooth class="size-4 text-slate-500" /> Pengaturan
                    </a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="flex h-7 w-full items-center gap-2 rounded-lg px-2.5 text-sm text-rose-600 hover:bg-rose-50">
                            <x-heroicon-o-arrow-right-start-on-rectangle class="size-4" /> Keluar
                        </button>
                    </form>
                </div>
            </div>
        </header>

        <main class="px-4 py-5 sm:px-6">
            <div class="mx-auto max-w-6xl">
                {{ $slot }}
            </div>
        </main>

        <footer class="px-4 pb-5 text-xs text-slate-400 sm:px-6">
            <div class="mx-auto max-w-6xl border-t border-slate-100 pt-3">
                &copy; {{ date('Y') }} {{ config('app.name') }} &middot; {{ config('app.tagline') }}
            </div>
        </footer>
    </div>

    <x-flash />
    <x-confirm-dialog />
</body>
</html>
