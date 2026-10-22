@props(['order'])

{{-- Status pengaturan pengiriman (pickup / drop off) di daftar pesanan. --}}
@if ($order->shippingMethodLabel())
    @php
        $done = $order->hasStatus('shipped', 'cancelled');
        $meta = \App\Models\SalesOrder::SHIPPING_METHODS[$order->shipping_method];
        $when = $order->shipping_method === 'pickup' && $order->pickup_at ? $order->pickup_at->translatedFormat('D d/m H:i') : null;
    @endphp
    <div {{ $attributes->class('mt-1') }}>
        <span @class([
            'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-medium whitespace-nowrap',
            'border-sky-200 bg-sky-50 text-sky-700' => ! $done,
            'border-slate-200 text-slate-600' => $done,
        ]) title="{{ $order->shipping_method === 'pickup' ? ($order->pickup_slot ?? $when ?? '') : ($order->dropoff_point ?? '') }}">
            <x-dynamic-component :component="$meta['icon']" class="size-3.5" />
            {{ $meta['label'] }}{{ $done ? '' : ' diatur' }}
        </span>
        @if ($when && ! $done)
            <p class="mt-0.5 text-[11px] whitespace-nowrap text-slate-600">Jemput {{ $when }}</p>
        @endif
    </div>
@elseif ($order->canArrangeShipment())
    <div {{ $attributes->class('mt-1') }}>
        <span class="inline-flex items-center gap-1 rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[11px] font-medium whitespace-nowrap text-amber-700">
            <x-heroicon-o-exclamation-circle class="size-3.5" /> Pengiriman belum diatur
        </span>
    </div>
@endif
