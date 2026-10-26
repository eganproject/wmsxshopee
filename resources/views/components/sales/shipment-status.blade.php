@props(['order'])

{{-- Status pengaturan pengiriman (pickup / drop off) di daftar pesanan. --}}
@if ($order->isSelfPickup())
    <div {{ $attributes }}>
        <span class="inline-flex cursor-help items-center gap-1 rounded-full border border-slate-200 px-2 py-0.5 text-[11px] font-medium whitespace-nowrap text-slate-600"
              data-hint="Pembeli mengambil sendiri di gudang / toko, jadi tidak perlu kurir, resi maupun serah terima.">
            <x-heroicon-o-hand-raised class="size-3.5" /> Diambil pembeli
        </span>
    </div>
@elseif ($order->shippingMethodLabel())
    @php
        $done = $order->hasStatus('shipped', 'cancelled');
        $meta = \App\Models\SalesOrder::SHIPPING_METHODS[$order->shipping_method];
        $when = $order->shipping_method === 'pickup' && $order->pickup_at ? $order->pickup_at->translatedFormat('D d/m H:i') : null;
    @endphp
    <div {{ $attributes }}>
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
@elseif ($order->shipmentAlreadyArranged() && ! $order->hasStatus('shipped', 'cancelled'))
    @php($platform = $order->isShopee() ? 'Shopee' : ($order->isTiktok() ? 'TikTok' : null))
    <div {{ $attributes }}>
        <span class="inline-flex items-center gap-1 rounded-full border border-sky-200 bg-sky-50 px-2 py-0.5 text-[11px] font-medium whitespace-nowrap text-sky-700"
              title="{{ $order->isShipmentArranged() || ! $platform ? 'Pengiriman sudah diatur' : 'Pengiriman diatur langsung di Seller Center '.$platform }}">
            <x-heroicon-o-check-circle class="size-3.5" /> {{ $order->isShipmentArranged() || ! $platform ? 'Pengiriman diatur' : 'Diatur di '.$platform }}
        </span>
    </div>
@elseif ($order->canArrangeShipment())
    <div {{ $attributes }}>
        <span class="inline-flex cursor-help items-center gap-1 rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[11px] font-medium whitespace-nowrap text-amber-700"
              data-hint-title="Pengiriman belum diatur" data-hint="Cara serah paket ke kurir (pickup atau drop off) belum dipilih, jadi resi belum terbit." data-hint-next="Klik ikon Atur pengiriman di baris pesanan ini.">
            <x-heroicon-o-exclamation-circle class="size-3.5" /> Belum diatur
        </span>
    </div>
@endif
