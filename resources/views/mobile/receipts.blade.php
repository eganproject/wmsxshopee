<x-layouts.mobile title="Penerimaan Barang" :sub="$warehouse?->name" :back="route('mobile.home')" with-bar>
    <div class="content stack">
        <div class="banner plain"><x-m-icon name="inbox" size="s" /><div>Scan barang dari supplier, foto surat jalan, lalu simpan. Rak (bin) boleh dikosongkan, stok tetap masuk ke gudang.</div></div>

        <div class="h2">Terbaru</div>
        @if ($receipts->isEmpty())
            <div class="card empty">
                <div class="thumb tone-ok"><x-m-icon name="inbox" size="l" /></div>
                <b>Belum ada penerimaan</b>Tekan "Terima barang" untuk mulai.
            </div>
        @else
            <div class="card list">
                @foreach ($receipts as $r)
                    @php($canOpen = auth()->user()->can('receipts.view'))
                    <{{ $canOpen ? 'a' : 'div' }} @if ($canOpen) href="{{ route('mobile.receipts.show', $r) }}" @endif class="it">
                        <span class="thumb tone-ok"><x-m-icon name="inbox" /></span>
                        <div class="grow">
                            <div class="t mono" style="font-size:13px">{{ $r->number }}</div>
                            <div class="m truncate">{{ $r->supplier?->name ?? '-' }} · {{ (int) $r->items_sum_qty_received }} pcs · {{ $r->created_at->translatedFormat('d M H.i') }}</div>
                        </div>
                        <span @class(['chip', 'ok' => ! $r->isDraft(), 'warn' => $r->isDraft()])>{{ $r->statusLabel() }}</span>
                    </{{ $canOpen ? 'a' : 'div' }}>
                @endforeach
            </div>
        @endif
    </div>
    <x-slot:bar>
        <a href="{{ route('mobile.receipts.create') }}" class="btn p"><x-m-icon name="plus" />Terima barang</a>
    </x-slot:bar>
</x-layouts.mobile>
