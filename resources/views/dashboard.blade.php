<x-layouts.app title="Dashboard">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" /> <span class="font-medium text-slate-700">Dashboard</span>
    </x-slot:breadcrumb>

    {{-- Sambutan --}}
    <div class="card relative mb-6 overflow-hidden p-6 sm:p-8">
        <div class="absolute -top-16 -right-16 size-56 rounded-full bg-brand-100/60 blur-2xl"></div>
        <div class="relative flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm text-slate-500">{{ now()->translatedFormat('l, d F Y') }}</p>
                <h1 class="mt-1 text-2xl font-semibold text-slate-900">Halo, {{ auth()->user()->name }} 👋</h1>
                <p class="mt-1 text-sm text-slate-500">Selamat datang di panel {{ config('app.name') }}.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @can('users.create')
                    <a href="{{ route('admin.users.create') }}" class="btn-primary">
                        <x-heroicon-o-user-plus class="size-5" /> Buat Akun
                    </a>
                @endcan
                <a href="{{ route('profile.edit') }}" class="btn-secondary">
                    <x-heroicon-o-user-circle class="size-5" /> Profil Saya
                </a>
            </div>
        </div>
    </div>

    @if ($stats)
        @php
            $cards = [
                ['label' => 'Total User', 'value' => $stats['users'], 'icon' => 'heroicon-o-users', 'color' => 'bg-sky-50 text-sky-600'],
                ['label' => 'User Aktif', 'value' => $stats['active_users'], 'icon' => 'heroicon-o-check-circle', 'color' => 'bg-emerald-50 text-emerald-600'],
                ['label' => 'Role', 'value' => $stats['roles'], 'icon' => 'heroicon-o-shield-check', 'color' => 'bg-violet-50 text-violet-600'],
                ['label' => 'Permission', 'value' => $stats['permissions'], 'icon' => 'heroicon-o-key', 'color' => 'bg-amber-50 text-amber-600'],
            ];
        @endphp
        <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
            @foreach ($cards as $card)
                <div class="card flex items-center gap-4 p-5">
                    <span class="flex size-12 shrink-0 items-center justify-center rounded-xl {{ $card['color'] }}">
                        <x-dynamic-component :component="$card['icon']" class="size-6" />
                    </span>
                    <div>
                        <p class="text-sm text-slate-500">{{ $card['label'] }}</p>
                        <p class="text-2xl font-semibold text-slate-900">{{ number_format($card['value']) }}</p>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        @can('users.view')
            <div class="card lg:col-span-2">
                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                    <h2 class="flex items-center gap-2 font-semibold text-slate-900">
                        <x-heroicon-o-clock class="size-5 text-slate-400" /> Login Terakhir
                    </h2>
                    <a href="{{ route('admin.users.index') }}" class="text-sm font-medium text-brand-600 hover:text-brand-700">Lihat semua</a>
                </div>
                <ul class="divide-y divide-slate-100">
                    @forelse ($recentLogins as $login)
                        <li class="flex items-center gap-3 px-5 py-3">
                            <x-avatar :user="$login" class="size-9 text-sm" />
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-slate-900">{{ $login->name }}</p>
                                <p class="truncate text-xs text-slate-500">{{ '@'.$login->username }}</p>
                            </div>
                            <span class="text-xs text-slate-500" title="{{ $login->last_login_at->format('d/m/Y H:i') }}">
                                {{ $login->last_login_at->diffForHumans() }}
                            </span>
                        </li>
                    @empty
                        <li class="px-5 py-8 text-center text-sm text-slate-500">Belum ada aktivitas login.</li>
                    @endforelse
                </ul>
            </div>
        @endcan

        <div class="card p-5">
            <h2 class="flex items-center gap-2 font-semibold text-slate-900">
                <x-heroicon-o-key class="size-5 text-slate-400" /> Akses Anda
            </h2>
            <div class="mt-4 flex flex-wrap gap-2">
                @forelse (auth()->user()->roles as $role)
                    <span class="inline-flex items-center gap-1 rounded-full bg-brand-50 px-3 py-1 text-xs font-medium text-brand-700 ring-1 ring-brand-100">
                        <x-heroicon-o-shield-check class="size-3.5" /> {{ $role->name }}
                    </span>
                @empty
                    <span class="text-sm text-slate-500">Belum ada role.</span>
                @endforelse
            </div>
            <div class="mt-5 flex items-start gap-3 rounded-lg bg-slate-50 p-4">
                <x-heroicon-o-building-storefront class="size-6 shrink-0 text-brand-500" />
                <div>
                    <p class="text-sm font-medium text-slate-900">Integrasi Shopee</p>
                    <p class="mt-0.5 text-xs text-slate-500">Modul produk, stok & pesanan Shopee akan tersedia pada tahap berikutnya.</p>
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
