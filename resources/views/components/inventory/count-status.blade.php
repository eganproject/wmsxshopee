@props(['status'])

@php
    $classes = ['draft' => 'bg-amber-50 text-amber-700', 'posted' => 'bg-emerald-50 text-emerald-700'];
    $meta = \App\Models\StockCount::STATUSES[$status] ?? ['label' => $status, 'icon' => 'heroicon-o-question-mark-circle'];
@endphp

<span class="inline-flex cursor-help items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium whitespace-nowrap {{ $classes[$status] ?? 'bg-slate-100 text-slate-600' }}" @if (! empty($meta['hint'])) data-hint-title="{{ $meta['label'] }}" data-hint="{{ $meta['hint'] }}" @endif>
    <x-dynamic-component :component="$meta['icon']" class="size-3.5" /> {{ $meta['label'] }}
</span>
