@props(['subject', 'limit' => 30])

{{-- Riwayat perubahan satu data, untuk halaman detail / edit. --}}
@php
    $logs = $subject->exists
        ? $subject->activityLogs()->with('user:id,name')->limit($limit + 1)->get()
        : collect();
    $more = $logs->count() > $limit;
    $logs = $logs->take($limit);
@endphp

<div {{ $attributes->class(['card mt-6']) }}>
    <div class="flex items-center justify-between gap-3 border-b border-slate-100 px-5 py-3">
        <h2 class="flex items-center gap-2 text-sm font-medium text-slate-900">
            <x-heroicon-o-clock class="size-4 text-slate-500" /> Riwayat
        </h2>
        @if ($more && auth()->user()->can('activity.view'))
            <a href="{{ route('admin.activity.index', ['search' => \App\Support\Activity::label($subject)]) }}" class="text-xs text-brand-600 hover:underline">Lihat semua</a>
        @endif
    </div>

    @if ($logs->isEmpty())
        <p class="px-5 py-6 text-center text-sm text-slate-500">Belum ada riwayat perubahan yang tercatat.</p>
    @else
        <ol class="divide-y divide-slate-100">
            @foreach ($logs as $log)
                <li x-data="{ open: false }" class="px-5 py-3">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <x-activity.event-badge :event="$log->event" />
                        <p class="min-w-0 flex-1 text-sm text-slate-700">{{ $log->description }}</p>
                        <p class="text-xs whitespace-nowrap text-slate-500" title="{{ $log->created_at->format('d/m/Y H:i:s') }}">
                            {{ $log->user?->name ?? $log->user_name ?? 'Sistem' }} · {{ $log->created_at->format('d/m/Y H:i') }}
                        </p>
                        @if ($log->hasDiff())
                            <button type="button" class="btn-icon" @click="open = !open" :aria-expanded="open" title="Lihat perubahan">
                                <x-heroicon-o-chevron-down class="size-4 transition" ::class="open && 'rotate-180'" />
                            </button>
                        @endif
                    </div>
                    @if ($log->hasDiff())
                        <div x-cloak x-show="open" x-collapse class="mt-2">
                            <x-activity.changes :log="$log" />
                        </div>
                    @endif
                </li>
            @endforeach
        </ol>
    @endif
</div>
