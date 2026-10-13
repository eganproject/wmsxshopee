<x-layouts.app title="Dashboard">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" /> <span class="font-medium text-slate-700">Dashboard</span>
    </x-slot:breadcrumb>

    {{-- Sambutan --}}
    <div class="mb-6 border-b border-slate-200 pb-5">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm text-slate-500">{{ now()->translatedFormat('l, d F Y') }}</p>
                <h1 class="mt-1 text-xl font-semibold tracking-normal text-slate-900">Halo, {{ auth()->user()->name }} 👋</h1>
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
                ['label' => 'Total User', 'value' => $stats['users'], 'icon' => 'heroicon-o-users'],
                ['label' => 'User Aktif', 'value' => $stats['active_users'], 'icon' => 'heroicon-o-check-circle'],
                ['label' => 'Role', 'value' => $stats['roles'], 'icon' => 'heroicon-o-shield-check'],
                ['label' => 'Permission', 'value' => $stats['permissions'], 'icon' => 'heroicon-o-key'],
            ];
        @endphp
        <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
            @foreach ($cards as $card)
                <div class="card flex items-center gap-3 p-4">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-600">
                        <x-dynamic-component :component="$card['icon']" class="size-5" />
                    </span>
                    <div>
                        <p class="text-sm text-slate-500">{{ $card['label'] }}</p>
                        <p class="text-xl font-semibold text-slate-900 tabular-nums">{{ number_format($card['value']) }}</p>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        @can('users.view')
            <div class="card lg:col-span-2">
                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                    <h2 class="flex items-center gap-2 text-base font-semibold text-slate-900">
                        Login Terakhir
                    </h2>
                    <a href="{{ route('admin.users.index') }}" class="text-sm text-slate-600 hover:text-slate-900">Lihat semua</a>
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
            <h2 class="flex items-center gap-2 text-base font-semibold text-slate-900">
                Akses Anda
            </h2>
            <div class="mt-4 flex flex-wrap gap-2">
                @forelse (auth()->user()->roles as $role)
                    <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-0.5 text-xs text-slate-700">
                        <x-heroicon-o-shield-check class="size-3.5" /> {{ $role->name }}
                    </span>
                @empty
                    <span class="text-sm text-slate-500">Belum ada role.</span>
                @endforelse
            </div>
            <div class="mt-5 flex items-start gap-3 rounded-lg bg-slate-50 p-4">
                <x-heroicon-o-building-storefront class="size-5 shrink-0 text-slate-500" />
                <div>
                    <p class="text-sm font-medium text-slate-900">Integrasi Shopee</p>
                    <p class="mt-0.5 text-xs text-slate-500">Modul produk, stok & pesanan Shopee akan tersedia pada tahap berikutnya.</p>
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
