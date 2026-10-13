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
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-slate-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full font-sans text-slate-800 antialiased" x-data="{ sidebarOpen: false }" @keydown.escape.window="sidebarOpen = false">

    {{-- Backdrop mobile --}}
    <div x-cloak x-show="sidebarOpen" x-transition.opacity @click="sidebarOpen = false"
         class="fixed inset-0 z-40 bg-slate-900/50 lg:hidden"></div>

    {{-- Sidebar --}}
    <aside class="fixed inset-y-0 left-0 z-50 flex w-64 -translate-x-full flex-col bg-slate-900 transition-transform duration-200 lg:translate-x-0"
           :class="sidebarOpen && 'translate-x-0'">
        <div class="flex h-16 shrink-0 items-center justify-between px-5">
            <a href="{{ route('dashboard') }}" class="flex items-center gap-2.5">
                <span class="flex size-9 items-center justify-center rounded-lg bg-brand-500 text-white shadow">
                    <x-heroicon-o-cube class="size-5" />
                </span>
                <span class="leading-tight">
                    <span class="block text-sm font-semibold text-white">{{ config('app.name') }}</span>
                    <span class="block text-xs text-slate-400">Warehouse Management</span>
                </span>
            </a>
            <button type="button" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-800 hover:text-white lg:hidden" @click="sidebarOpen = false">
                <x-heroicon-o-x-mark class="size-5" />
            </button>
        </div>

        <nav class="flex-1 space-y-6 overflow-y-auto px-3 py-4">
            @foreach ($navigation as $section)
                @php($items = collect($section['items'])->filter(fn ($item) => ! $item['can'] || $user->can($item['can'])))
                @if ($items->isNotEmpty())
                    <div>
                        @if ($section['heading'])
                            <p class="mb-2 px-3 text-xs font-semibold tracking-wider text-slate-500 uppercase">{{ $section['heading'] }}</p>
                        @endif
                        <ul class="space-y-1">
                            @foreach ($items as $item)
                                @php($active = request()->routeIs($item['active']))
                                <li>
                                    <a href="{{ route($item['route']) }}"
                                       @class([
                                           'group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition',
                                           'bg-slate-800 text-white' => $active,
                                           'text-slate-300 hover:bg-slate-800/60 hover:text-white' => ! $active,
                                       ])>
                                        <x-dynamic-component :component="$item['icon']" @class(['size-5 shrink-0', 'text-brand-400' => $active, 'text-slate-400 group-hover:text-slate-200' => ! $active]) />
                                        {{ $item['label'] }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            @endforeach
        </nav>

        <div class="border-t border-slate-800 p-3">
            <a href="{{ route('profile.edit') }}" class="flex items-center gap-3 rounded-lg px-2 py-2 hover:bg-slate-800/60">
                <x-avatar :user="$user" class="size-9 text-sm" />
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-medium text-white">{{ $user->name }}</span>
                    <span class="block truncate text-xs text-slate-400">{{ $user->roles->pluck('name')->join(', ') ?: 'Tanpa role' }}</span>
                </span>
            </a>
        </div>
    </aside>

    <div class="lg:pl-64">
        {{-- Topbar --}}
        <header class="sticky top-0 z-30 flex h-16 items-center gap-3 border-b border-slate-200 bg-white/90 px-4 backdrop-blur sm:px-6 lg:px-8">
            <button type="button" class="btn-icon -ml-2 lg:hidden" @click="sidebarOpen = true" aria-label="Buka menu">
                <x-heroicon-o-bars-3 class="size-6" />
            </button>

            <div class="min-w-0 flex-1">
                @isset($breadcrumb)
                    <nav class="flex items-center gap-1.5 text-sm text-slate-500">{{ $breadcrumb }}</nav>
                @endisset
            </div>

            <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                <button type="button" @click="open = !open" class="flex items-center gap-2 rounded-lg p-1.5 hover:bg-slate-100">
                    <x-avatar :user="$user" class="size-8 text-xs" />
                    <span class="hidden text-sm font-medium text-slate-700 sm:block">{{ $user->name }}</span>
                    <x-heroicon-o-chevron-down class="hidden size-4 text-slate-400 sm:block" />
                </button>

                <div x-cloak x-show="open" x-transition.origin.top.right
                     class="absolute right-0 mt-2 w-60 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-lg">
                    <div class="border-b border-slate-100 px-4 py-3">
                        <p class="truncate text-sm font-medium text-slate-900">{{ $user->name }}</p>
                        <p class="truncate text-xs text-slate-500">{{ $user->email }}</p>
                    </div>
                    <div class="p-1.5">
                        <a href="{{ route('profile.edit') }}" class="flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm text-slate-700 hover:bg-slate-100">
                            <x-heroicon-o-user-circle class="size-5 text-slate-400" /> Profil Saya
                        </a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-sm text-rose-600 hover:bg-rose-50">
                                <x-heroicon-o-arrow-right-start-on-rectangle class="size-5" /> Keluar
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-7xl">
                {{ $slot }}
            </div>
        </main>
    </div>

    <x-flash />
    <x-confirm-dialog />
</body>
</html>
