<x-layouts.mobile :title="$order->number" sub="Hasil scan · pesanan" :back="route('mobile.home')" with-bar>
    <div class="content stack">
        <div class="card pad">
            <div class="row">
                <div class="grow">
                    <div class="label">{{ $order->channel_name ?: 'Pesanan' }}</div>
                    <div class="bigcode truncate" style="font-size:18px;margin-top:2px">{{ $order->tracking_number ?: $order->number }}</div>
                </div>
                <span class="chip">{{ $order->statusLabel() }}</span>
            </div>
            <div class="kv" style="margin-top:8px"><span>Pembeli</span><span>{{ $order->customer_name ?: '-' }}</span></div>
            <div class="kv"><span>Kurir</span><span>{{ $order->courier?->name ?? '-' }}</span></div>
            <div class="kv"><span>Gudang</span><span>{{ $order->warehouse?->name }}</span></div>
            @if ($order->external_number)
                <div class="kv"><span>No. marketplace</span><span class="mono">{{ $order->external_number }}</span></div>
            @endif
        </div>
        <div class="card list">
            @foreach ($order->items as $item)
                <div class="it">
                    <div class="grow"><div class="t mono" style="font-size:13px">{{ $item->variant->sku }}</div><div class="m truncate">{{ $item->variant->fullName() }}</div></div>
                    <b>×{{ (int) $item->qty }}</b>
                </div>
            @endforeach
        </div>
        @if ($order->packing_photo_path)
            <div class="field-label">Foto paket saat QC</div>
            <img src="{{ Storage::disk('public')->url($order->packing_photo_path) }}" alt="Foto paket" style="width:100%;border-radius:16px">
        @endif

        @php
            $canQc = $order->status === 'picking' && auth()->user()->can('sales.fulfill');
            $canResi = $order->hasShippingLabel() && auth()->user()->can('sales.fulfill');
        @endphp
        <div class="bar">
            @if ($canQc)
                <a href="{{ route('mobile.qc') }}" class="btn p"><x-m-icon name="pkgcheck" />QC pesanan ini</a>
            @endif
            @if ($canResi)
                <button type="button" class="btn s" x-data="printResi(@js(route('sales.orders.shipping-label', $order)))" :class="busy && 'is-busy'" @click="go()"><x-m-icon name="printer" />Cetak resi</button>
            @endif
            @if (! $canQc && ! $canResi)
                <a href="{{ route('mobile.home') }}" class="btn s">Kembali</a>
            @endif
        </div>
    </div>
</x-layouts.mobile>
