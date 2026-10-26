@props(['type'])

@php
    $classes = [
        'in' => 'bg-emerald-50 text-emerald-700',
        'out' => 'bg-rose-50 text-rose-700',
        'transfer' => 'bg-sky-50 text-sky-700',
        'adjustment' => 'bg-amber-50 text-amber-700',
        'damage' => 'bg-rose-50 text-rose-700',
        'repair' => 'bg-emerald-50 text-emerald-700',
        'assembly' => 'bg-violet-50 text-violet-700',
        'transfer_out' => 'bg-sky-50 text-sky-700',
        'transfer_in' => 'bg-sky-50 text-sky-700',
    ];
    $meta = \App\Models\StockMovement::TYPES[$type] ?? ['label' => $type, 'icon' => 'heroicon-o-question-mark-circle'];
@endphp

<span class="inline-flex cursor-help items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium whitespace-nowrap {{ $classes[$type] ?? 'bg-slate-100 text-slate-600' }}" @if (! empty($meta['hint'])) data-hint-title="{{ $meta['label'] }}" data-hint="{{ $meta['hint'] }}" @endif>
    <x-dynamic-component :component="$meta['icon']" class="size-3.5" /> {{ $meta['label'] }}
</span>
