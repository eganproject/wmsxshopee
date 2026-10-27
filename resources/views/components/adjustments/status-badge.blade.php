@props(['adjustment'])

@php
    $meta = \App\Models\StockAdjustment::STATUSES[$adjustment->status] ?? \App\Models\StockAdjustment::STATUSES['draft'];
    $classes = [
        'slate' => 'bg-slate-100 text-slate-600',
        'amber' => 'bg-amber-50 text-amber-700',
        'emerald' => 'bg-emerald-50 text-emerald-700',
        'rose' => 'bg-rose-50 text-rose-700',
    ][$meta['color']];
@endphp

<span class="inline-flex cursor-help items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium whitespace-nowrap {{ $classes }}"
      data-hint-title="{{ $meta['label'] }}" data-hint="{{ $meta['hint'] }}">
    <x-dynamic-component :component="$meta['icon']" class="size-3.5" /> {{ $meta['label'] }}
</span>
