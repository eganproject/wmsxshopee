{{-- Lonceng notifikasi di topbar: jumlah peringatan per grup, detail di halaman Notifikasi. --}}
@php
    $alerts = app(\App\Services\AlertService::class)->summary(auth()->user());
    $active = collect($alerts['groups'])->filter(fn ($group) => $group['count'] > 0);
    $dots = ['rose' => 'bg-rose-500', 'amber' => 'bg-amber-500', 'slate' => 'bg-slate-400'];
@endphp

<div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false">
    <button type="button" class="btn-icon relative" @click="open = !open"
            title="Notifikasi" aria-label="Notifikasi{{ $alerts['total'] ? ' ('.$alerts['total'].')' : '' }}">
        <x-heroicon-o-bell />
        @if ($alerts['total'])
            <span class="absolute -top-0.5 -right-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-rose-600 px-1 text-[10px] leading-none font-semibold text-white tabular-nums" data-testid="notification-count">
                {{ $alerts['total'] > 99 ? '99+' : $alerts['total'] }}
            </span>
        @endif
    </button>

    <div x-cloak x-show="open" x-transition.origin.top.right
         class="absolute right-0 mt-1.5 w-72 overflow-hidden rounded-xl border border-slate-200 bg-white p-1 shadow-lg sm:w-80">
        <p class="px-2.5 pt-2 pb-1.5 text-sm font-medium text-slate-900">Notifikasi</p>
        @forelse ($active as $group)
            <a href="{{ route('notifications.index') }}#{{ $group['key'] }}" class="flex items-start gap-2.5 rounded-lg px-2.5 py-2 hover:bg-slate-100">
                <span @class(['mt-1.5 size-2 shrink-0 rounded-full', $dots[$group['tone']] ?? 'bg-slate-400'])></span>
                <span class="min-w-0 flex-1">
                    <span class="block text-sm text-slate-900">{{ $group['label'] }}</span>
                    <span class="block truncate text-xs text-slate-500">{{ $group['hint'] }}</span>
                </span>
                <span class="text-sm font-semibold tabular-nums text-slate-900">{{ number_format($group['count'], 0, ',', '.') }}</span>
            </a>
        @empty
            <div class="flex flex-col items-center gap-1.5 px-2.5 py-6 text-center text-sm text-slate-500">
                <x-heroicon-o-check-circle class="size-6 text-emerald-600" />
                Tidak ada peringatan.
            </div>
        @endforelse
        <div class="my-1 border-t border-slate-100"></div>
        <a href="{{ route('notifications.index') }}" class="flex h-7 items-center justify-center rounded-lg text-sm text-slate-600 hover:bg-slate-100">Lihat semua notifikasi</a>
    </div>
</div>
