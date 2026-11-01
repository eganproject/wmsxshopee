<x-layouts.mobile :title="$receipt->number" :sub="$receipt->supplier?->name" :back="route('mobile.receipts')" :with-bar="$receipt->isDraft()">
    <div x-data="putawayApp(@js($putaway))" class="content stack">
        <div class="card pad">
            <div class="kv"><span>Status</span><span><span @class(['chip', 'ok' => ! $receipt->isDraft(), 'warn' => $receipt->isDraft()])>{{ $receipt->statusLabel() }}</span></span></div>
            <div class="kv"><span>Tanggal</span><span>{{ $receipt->receipt_date?->translatedFormat('d M Y') }}</span></div>
            @if ($receipt->supplier_reference)
                <div class="kv"><span>No. surat jalan</span><span>{{ $receipt->supplier_reference }}</span></div>
            @endif
            @if ($receipt->attachmentIsImage())
                <div style="margin-top:10px"><img src="{{ Storage::disk('public')->url($receipt->attachment_path) }}" alt="Surat jalan" style="width:100%;border-radius:12px"></div>
            @endif
        </div>

        <div class="h2">Barang</div>
        <div class="card list">
            @foreach ($receipt->items as $item)
                <div class="it">
                    <div class="grow">
                        <div class="t mono" style="font-size:13px">{{ $item->variant->sku }}</div>
                        <div class="m truncate">{{ $item->variant->fullName() }}</div>
                    </div>
                    <div style="text-align:right">
                        <div style="font-weight:700">{{ (int) $item->qty_received }}</div>
                        @if ($item->qty_damaged > 0)<div style="font-size:11.5px;color:var(--bad)">{{ (int) $item->qty_damaged }} rusak</div>@endif
                    </div>
                    <span class="chip">{{ $item->location?->code ?? 'Tanpa bin' }}</span>
                </div>
            @endforeach
        </div>

        @if (! $receipt->isDraft() && count($putaway))
            <div class="h2"><span>Simpan ke rak</span><span class="chip" x-text="remaining ? `${remaining} lagi` : 'Selesai'"></span></div>
            <div class="card list">
                <template x-for="p in rows" :key="p.sku + p.location">
                    <div class="it">
                        <span class="check" :class="confirmed[p.sku + p.location] ? 'done' : 'todo'"><x-m-icon name="check" size="xs" x-show="confirmed[p.sku + p.location]" /></span>
                        <div class="grow"><div class="t mono" style="font-size:13px" x-text="p.sku"></div><div class="m truncate" x-text="`${p.qty} pcs · ${p.name}`"></div></div>
                        <div class="bigcode" style="font-size:15px" x-text="p.location"></div>
                    </div>
                </template>
            </div>
            <p class="muted" style="font-size:12px;margin:0 2px">Tekan tombol scan, lalu scan label rak tempat barang ditaruh.</p>
        @endif

        @if ($receipt->isDraft())
            <div class="bar">
                @can('receipts.receive')
                    <button type="button" class="btn p" x-data="postAction(@js(route('mobile.receipts.receive', $receipt)), 'Terima barang ini? Stok akan bertambah.')" :class="busy && 'is-busy'" @click="go()"><x-m-icon name="check" />Konfirmasi terima</button>
                @else
                    <div class="banner plain grow">Menunggu admin gudang mengonfirmasi penerimaan.</div>
                @endcan
            </div>
        @endif
    </div>
</x-layouts.mobile>
