<x-layouts.mobile title="Stock Opname" :sub="$warehouse?->name" :back="route('mobile.home')">
    <div class="content stack">
        <div class="banner plain"><x-m-icon name="clip" size="s" /><div>Pilih lembar opname, lalu hitung per rak. Beberapa orang bisa menghitung lembar yang sama sekaligus. Lembar baru dibuat dari web.</div></div>

        @forelse ($counts as $c)
            @php($pct = $c->items_count ? (int) round($c->counted_count / $c->items_count * 100) : 0)
            <a href="{{ route('mobile.opname.show', $c) }}" class="card pad" style="display:block">
                <div class="row">
                    <span class="thumb tone-warn"><x-m-icon name="clip" /></span>
                    <div class="grow">
                        <div class="t mono" style="font-size:13.5px;font-weight:650">{{ $c->number }}</div>
                        <div class="muted" style="font-size:12px">{{ $c->zone?->name ? 'Zona '.$c->zone->name : 'Seluruh gudang' }} · {{ $c->count_date?->translatedFormat('d M') }}</div>
                    </div>
                    <x-m-icon name="right" size="s" class="muted" />
                </div>
                <div class="row" style="margin-top:12px">
                    <div class="prog grow"><i style="width:{{ $pct }}%;background:var(--warn)"></i></div>
                    <span class="muted" style="font-size:12px;font-weight:600">{{ $c->counted_count }}/{{ $c->items_count }}</span>
                </div>
            </a>
        @empty
            <div class="card empty">
                <div class="thumb tone-warn"><x-m-icon name="clip" size="l" /></div>
                <b>Tidak ada opname berjalan</b>Minta admin membuat lembar opname di web. Untuk cek cepat satu rak, pakai Cek Stok Rak.
            </div>
        @endforelse

        @can('adjustments.create')
            <a href="{{ route('mobile.stock-check') }}" class="card list" style="display:block"><div class="it"><span class="thumb tone-amber"><x-m-icon name="layers" /></span><div class="grow"><div class="t">Cek Stok Rak</div><div class="m">Hitung ulang 1 rak, selisih diajukan ke approval</div></div><x-m-icon name="right" size="s" class="muted" /></div></a>
        @endcan
    </div>
</x-layouts.mobile>
