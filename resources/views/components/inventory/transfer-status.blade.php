@props(['transfer'])

@php
    $classes = [
        'amber' => 'bg-amber-50 text-amber-700',
        'emerald' => 'bg-emerald-50 text-emerald-700',
        'slate' => 'bg-slate-100 text-slate-600',
    ];
@endphp

<span class="inline-flex cursor-help items-center rounded-full px-2.5 py-0.5 text-xs font-medium whitespace-nowrap {{ $classes[$transfer->statusColor()] ?? $classes['slate'] }}"
      data-hint-title="{{ $transfer->statusLabel() }}" data-hint="{{ \App\Models\WarehouseTransfer::STATUSES[$transfer->status]['hint'] ?? $transfer->statusLabel() }}">
    {{ $transfer->statusLabel() }}
</span>
