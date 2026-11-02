<x-layouts.mobile title="Hasil scan" :back="route('mobile.home')">
    <div class="content stack">
        @if ($bundle)
            <div class="card pad">
                <div class="label">Produk paket</div>
                <div style="font-size:16px;font-weight:650;margin-top:2px">{{ $bundle->name }}</div>
                <div class="muted mono" style="font-size:12px">{{ $bundle->sku }}</div>
            </div>
            <div class="card list">
                @foreach ($bundle->items as $item)
                    <div class="it">
                        <div class="grow"><div class="t mono" style="font-size:13px">{{ $item->variant->sku }}</div><div class="m truncate">{{ $item->variant->fullName() }}</div></div>
                        <b>×{{ (int) $item->qty }}</b>
                    </div>
                @endforeach
            </div>
        @else
            <div class="card empty">
                <div class="thumb tone-bad"><x-m-icon name="search" size="l" /></div>
                <b>Kode tidak dikenal</b>
                <span class="mono">{{ $code }}</span> bukan resi, pesanan, rak, atau barang di gudang ini.
            </div>
        @endif
    </div>
</x-layouts.mobile>
