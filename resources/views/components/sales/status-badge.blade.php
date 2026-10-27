@props(['status'])

@php
    $classes = [
        'draft' => 'bg-slate-100 text-slate-600',
        'confirmed' => 'bg-sky-50 text-sky-700',
        'picking' => 'bg-violet-50 text-violet-700',
        'packed' => 'bg-amber-50 text-amber-700',
        'shipped' => 'bg-emerald-50 text-emerald-700',
        'cancelled' => 'bg-rose-50 text-rose-700',
    ];
    $meta = \App\Models\SalesOrder::STATUSES[$status] ?? ['label' => $status, 'icon' => 'heroicon-o-question-mark-circle'];
@endphp

<span class="inline-flex cursor-help items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium whitespace-nowrap {{ $classes[$status] ?? 'bg-slate-100 text-slate-600' }}" @if (! empty($meta['hint'])) data-hint-title="{{ $meta['label'] }}" data-hint="{{ $meta['hint'] }}" @endif @if (! empty($meta['next'])) data-hint-next="{{ $meta['next'] }}" @endif>
    <x-dynamic-component :component="$meta['icon']" class="size-3.5" /> {{ $meta['label'] }}
</span>
