<x-layouts.mobile title="Tanda Tangan Kurir" :sub="$handover->number" :back="route('mobile.handover')" :nav="false" with-bar>
    <div x-data="signApp(@js([
        'receiver' => $handover->receiver_name,
        'signed' => (bool) $handover->signed_at,
        'signUrl' => route('mobile.handover.sign', $handover),
        'manifestUrl' => route('mobile.handover.manifest', $handover),
    ]))" class="content stack">
        <div class="card pad">
            <div class="kv"><span>Kurir</span><span>{{ $handover->courier?->name ?? 'Tanpa kurir' }}</span></div>
            <div class="kv"><span>Jumlah paket</span><span>{{ $handover->orders->count() }} paket</span></div>
            <div class="kv"><span>Dibuat</span><span>{{ $handover->created_at->translatedFormat('d M Y H.i') }} · {{ $handover->creator?->name }}</span></div>
            @if ($handover->signed_at)
                <div class="kv"><span>Ditandatangani</span><span>{{ $handover->receiver_name }} · {{ $handover->signed_at->format('H.i') }}</span></div>
            @endif
        </div>

        <div class="card list" style="max-height:220px;overflow:auto">
            @foreach ($handover->orders as $order)
                <div class="it" style="padding:9px 14px">
                    <div class="grow">
                        <div class="t mono truncate" style="font-size:12.5px">{{ $order->tracking_number ?: $order->number }}</div>
                        <div class="m truncate">{{ $order->number }} · {{ $order->customer_name }}</div>
                    </div>
                </div>
            @endforeach
        </div>

        @if ($handover->receiver_signature_path)
            <div class="field-label">Tanda tangan tersimpan</div>
            <div class="card pad center"><img src="{{ Storage::disk('public')->url($handover->receiver_signature_path) }}" alt="Tanda tangan" style="max-width:100%;max-height:140px"></div>
        @endif

        <label class="field-label" for="receiver">Nama kurir penerima</label>
        <input id="receiver" x-model="name" class="input" placeholder="cth. Budi (SPX)" autocomplete="off">

        <div class="field-label row" style="justify-content:space-between">
            <span>{{ $handover->signed_at ? 'Tanda tangan ulang' : 'Tanda tangan kurir' }}</span>
            <button type="button" @click="clear()" class="muted" style="font-size:12.5px;font-weight:600">Hapus</button>
        </div>
        <div class="sig"><div class="line"></div><canvas x-ref="pad"></canvas></div>

        <div class="bar">
            <button type="button" class="btn s sq" @click="printManifest()" aria-label="Cetak manifest"><x-m-icon name="printer" /></button>
            <button type="button" class="btn p" :class="busy && 'is-busy'" @click="save()"><x-m-icon name="pen" x-show="!busy" />Simpan tanda tangan</button>
        </div>
    </div>
</x-layouts.mobile>
