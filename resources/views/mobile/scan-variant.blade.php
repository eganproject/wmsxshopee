<x-layouts.mobile :title="$variant->sku" sub="Hasil scan · barang" :back="route('mobile.home')">
    <div class="content stack">
        <div class="card pad">
            <div style="font-size:16px;font-weight:650">{{ $variant->fullName() }}</div>
            @if ($variant->barcode)
                <div class="muted mono" style="font-size:12px;margin-top:2px">{{ $variant->barcode }}</div>
            @endif
            <div class="big-title" style="margin-top:10px">{{ (int) $stocks->sum('qty') }} <span class="muted" style="font-size:14px;font-weight:600">pcs di gudang ini</span></div>
        </div>
        <div class="h2">Lokasi</div>
        @if ($stocks->isEmpty())
            <div class="card empty"><b>Stok kosong</b>Barang ini tidak ada stok di gudang aktif.</div>
        @else
            <div class="card list">
                @foreach ($stocks as $s)
                    <div class="it">
                        <span class="thumb tone-plain"><x-m-icon name="pin" size="s" /></span>
                        <div class="grow t mono">{{ $s->location?->code ?? 'Tanpa bin' }}</div>
                        <b>{{ (int) $s->qty }}</b>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-layouts.mobile>
