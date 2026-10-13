@props(['event'])

@php
    $meta = \App\Models\ActivityLog::EVENTS[$event] ?? ['label' => $event, 'icon' => 'heroicon-o-bolt'];
    $classes = [
        'created' => 'bg-emerald-50 text-emerald-700',
        'updated' => 'bg-sky-50 text-sky-700',
        'deleted' => 'bg-rose-50 text-rose-700',
        'status' => 'bg-violet-50 text-violet-700',
        'login_failed' => 'bg-amber-50 text-amber-700',
    ][$event] ?? 'bg-slate-100 text-slate-600';
@endphp

<span {{ $attributes->class(['inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap', $classes]) }}>
    <x-dynamic-component :component="$meta['icon']" class="size-3.5" /> {{ $meta['label'] }}
</span>
