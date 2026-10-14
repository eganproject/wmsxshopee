@props(['order'])

{{-- Status pesanan di TikTok Shop. --}}
@if ($order->tiktok_order_status)
    @php
        $colors = [
            'slate' => 'border-slate-200 text-slate-600',
            'sky' => 'border-sky-200 text-sky-700',
            'violet' => 'border-violet-200 text-violet-700',
            'amber' => 'border-amber-200 text-amber-700',
            'emerald' => 'border-emerald-200 text-emerald-700',
            'rose' => 'border-rose-200 text-rose-700',
        ];
        $color = \App\Models\SalesOrder::TIKTOK_STATUSES[$order->tiktok_order_status]['color'] ?? 'slate';
    @endphp
    <div {{ $attributes->class('mt-1 space-y-0.5') }}>
        <span class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] whitespace-nowrap {{ $colors[$color] }}" title="Status di TikTok Shop">
            TikTok: {{ $order->tiktokStatusLabel() }}
        </span>
        @if ($order->tiktokAhead())
            <p class="flex items-center gap-1 text-[11px] whitespace-nowrap text-rose-600" title="{{ $order->tiktok_sync_error }}">
                <x-heroicon-o-exclamation-triangle class="size-3.5" /> Dikirim di TikTok, belum di gudang
            </p>
        @endif
        @if ($order->shipped_automatically)
            <p class="text-[11px] whitespace-nowrap text-slate-500">Dikirim otomatis dari TikTok</p>
        @endif
    </div>
@endif
