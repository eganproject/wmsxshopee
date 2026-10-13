@props(['title' => null])

@php
    $navigation = [
        [
            'heading' => null,
            'items' => [
                ['label' => 'Dashboard', 'route' => 'dashboard', 'active' => 'dashboard', 'icon' => 'heroicon-o-squares-2x2', 'can' => null],
            ],
        ],
        [
            'heading' => 'Master Data',
            'items' => [
                ['label' => 'Gudang & Lokasi', 'route' => 'master.warehouses.index', 'active' => ['master.warehouses.*', 'master.zones.*', 'master.racks.*', 'master.locations.*'], 'icon' => 'heroicon-o-building-office-2', 'can' => 'warehouses.view'],
                ['label' => 'Produk', 'route' => 'master.products.index', 'active' => ['master.products.*', 'master.units.*'], 'icon' => 'heroicon-o-cube', 'can' => 'products.view'],
                ['label' => 'Kurir', 'route' => 'master.couriers.index', 'active' => 'master.couriers.*', 'icon' => 'heroicon-o-truck', 'can' => 'couriers.view'],
                ['label' => 'Supplier', 'route' => 'master.suppliers.index', 'active' => 'master.suppliers.*', 'icon' => 'heroicon-o-building-storefront', 'can' => 'suppliers.view'],
            ],
        ],
        [
            'heading' => 'Penjualan',
            'items' => [
                ['label' => 'Pesanan', 'route' => 'sales.orders.index', 'active' => 'sales.orders.*', 'icon' => 'heroicon-o-shopping-cart', 'can' => 'sales.view'],
            ],
        ],
        [
            'heading' => 'Inventori',
            'items' => [
                ['label' => 'Penerimaan Barang', 'route' => 'inventory.receipts.index', 'active' => 'inventory.receipts.*', 'icon' => 'heroicon-o-inbox-arrow-down', 'can' => 'receipts.view'],
                ['label' => 'Stok', 'route' => 'inventory.stocks.index', 'active' => 'inventory.stocks.*', 'icon' => 'heroicon-o-archive-box', 'can' => 'inventory.view'],
                ['label' => 'Kartu Stok', 'route' => 'inventory.stock-card.index', 'active' => 'inventory.stock-card.*', 'icon' => 'heroicon-o-document-chart-bar', 'can' => 'inventory.view'],
                ['label' => 'Mutasi Stok', 'route' => 'inventory.movements.index', 'active' => 'inventory.movements.*', 'icon' => 'heroicon-o-arrows-right-left', 'can' => 'inventory.view'],
            ],
        ],
        [
            'heading' => 'Administrasi',
            'items' => [
                ['label' => 'Manajemen User', 'route' => 'admin.users.index', 'active' => 'admin.users.*', 'icon' => 'heroicon-o-users', 'can' => 'users.view'],
                ['label' => 'Role & Permission', 'route' => 'admin.roles.index', 'active' => 'admin.roles.*', 'icon' => 'heroicon-o-shield-check', 'can' => 'roles.view'],
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
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full font-sans text-sm text-slate-900 antialiased" x-data="{ sidebarOpen: false }" @keydown.escape.window="sidebarOpen = false">

    {{-- Backdrop mobile --}}
    <div x-cloak x-show="sidebarOpen" x-transition.opacity @click="sidebarOpen = false"
         class="fixed inset-0 z-40 bg-black/40 lg:hidden"></div>

    {{-- Sidebar --}}
    <aside class="fixed inset-y-0 left-0 z-50 flex w-60 -translate-x-full flex-col border-r border-slate-200 bg-slate-50 p-2 transition-transform duration-200 lg:translate-x-0"
           :class="sidebarOpen && 'translate-x-0'">
        <div class="flex items-center justify-between gap-2 px-1.5 pt-1 pb-2">
            <a href="{{ route('dashboard') }}" class="flex min-w-0 items-center gap-2 rounded-lg p-1 hover:bg-slate-100">
                <x-app-logo class="size-7" />
                <span class="min-w-0 leading-tight">
                    <span class="block truncate text-sm font-medium text-slate-900">{{ config('app.name') }}</span>
                    <span class="block truncate text-xs text-slate-500">{{ config('app.tagline') }}</span>
                </span>
            </a>
            <button type="button" class="btn-icon lg:hidden" @click="sidebarOpen = false" aria-label="Tutup menu">
                <x-heroicon-o-x-mark />
            </button>
        </div>

        <nav class="flex-1 overflow-y-auto">
            @foreach ($navigation as $section)
                @php($items = collect($section['items'])->filter(fn ($item) => ! $item['can'] || $user->can($item['can'])))
                @if ($items->isNotEmpty())
                    <div class="mt-1">
                        @if ($section['heading'])
                            <p class="px-2 pt-3 pb-1 text-xs text-slate-500">{{ $section['heading'] }}</p>
                        @endif
                        <ul class="space-y-px">
                            @foreach ($items as $item)
                                @php($active = request()->routeIs($item['active']))
                                <li>
                                    <a href="{{ route($item['route']) }}"
                                       @class([
                                           'flex h-7 items-center gap-2 rounded-lg px-2 text-[13px] transition',
                                           'bg-white font-medium text-slate-900 shadow-sm ring-1 ring-slate-200' => $active,
                                           'text-slate-700 hover:bg-slate-100' => ! $active,
                                       ])>
                                        <x-dynamic-component :component="$item['icon']" @class(['size-4 shrink-0', 'text-slate-900' => $active, 'text-slate-500' => ! $active]) />
                                        {{ $item['label'] }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            @endforeach
        </nav>

        <a href="{{ route('profile.edit') }}" class="flex items-center gap-2 rounded-lg p-1.5 hover:bg-slate-100">
            <x-avatar :user="$user" class="size-7 text-xs" />
            <span class="min-w-0 flex-1 leading-tight">
                <span class="block truncate text-[13px] font-medium text-slate-900">{{ $user->name }}</span>
                <span class="block truncate text-xs text-slate-500">{{ $user->roles->pluck('name')->join(', ') ?: 'Tanpa role' }}</span>
            </span>
        </a>
    </aside>

    <div class="lg:pl-60">
        {{-- Topbar --}}
        <header class="sticky top-0 z-30 flex h-12 items-center gap-3 border-b border-slate-200 bg-white px-4 sm:px-5">
            <button type="button" class="btn-icon -ml-1.5 lg:hidden" @click="sidebarOpen = true" aria-label="Buka menu">
                <x-heroicon-o-bars-3 />
            </button>

            <div class="min-w-0 flex-1">
                @isset($breadcrumb)
                    <nav class="flex items-center gap-1.5 text-sm text-slate-500 [&_svg]:size-3.5 [&_svg]:shrink-0">{{ $breadcrumb }}</nav>
                @endisset
            </div>

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
