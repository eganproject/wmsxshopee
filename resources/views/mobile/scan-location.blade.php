<x-layouts.mobile :title="$location->code" sub="Hasil scan · rak" :back="route('mobile.home')">
    <div class="content stack">
        <div class="card pad row">
            <span class="thumb tone-amber"><x-m-icon name="pin" /></span>
            <div class="grow"><div class="t" style="font-weight:650">{{ $stocks->count() }} barang · {{ (int) $stocks->sum('qty') }} pcs</div><div class="m muted" style="font-size:12px">{{ $location->is_active ? 'Rak aktif' : 'Rak nonaktif' }}</div></div>
        </div>
        @if ($stocks->isEmpty())
            <div class="card empty"><b>Rak kosong</b>Tidak ada stok tercatat di rak ini.</div>
        @else
            <div class="card list">
                @foreach ($stocks as $s)
                    <div class="it">
                        <div class="grow"><div class="t mono" style="font-size:13px">{{ $s->variant->sku }}</div><div class="m truncate">{{ $s->variant->fullName() }}</div></div>
                        <b>{{ (int) $s->qty }}</b>
                    </div>
                @endforeach
            </div>
        @endif
        @can('adjustments.create')
            <a href="{{ route('mobile.stock-check') }}" class="btn s" style="width:100%"><x-m-icon name="layers" />Cek stok rak ini</a>
        @endcan
    </div>
</x-layouts.mobile>
