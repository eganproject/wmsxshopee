<x-layouts.mobile title="Terima Barang" :sub="$warehouse?->name" :back="route('mobile.receipts')" :nav="false" with-bar>
    <div x-data="receiptApp(@js(['indexUrl' => route('mobile.receipts')]))" class="content stack">
        <template x-if="!result">
            <div class="stack">
                <label class="field-label" style="margin-top:0" for="supplier">Supplier</label>
                <select id="supplier" x-model="supplier" class="select">
                    <option value="">Pilih supplier…</option>
                    <template x-for="s in suppliers" :key="s.id"><option :value="s.id" x-text="s.name"></option></template>
                </select>
                <input x-model="reference" class="input" placeholder="No. surat jalan / invoice (opsional)" autocomplete="off">

                <div class="field-label">Foto surat jalan <span style="font-weight:500">(opsional)</span></div>
                <div class="photos">
                    <template x-if="photoUrl">
                        <div class="photo"><img :src="photoUrl" alt="Surat jalan"><button type="button" class="x" @click="photo = null; photoUrl = null" aria-label="Hapus foto"><x-m-icon name="x" size="xs" /></button></div>
                    </template>
                    <label class="addph" x-show="!photoUrl"><x-m-icon name="camera" />Foto<input type="file" accept="image/*" capture="environment" @change="pickPhoto($event)"></label>
                </div>

                <div class="h2"><span>Barang diterima <span class="muted" x-text="`(${totalReceived} pcs)`"></span></span></div>
                <form class="scanfield" @submit.prevent="scan()">
                    <input x-model="code" class="input" placeholder="Scan / ketik SKU, lalu kode rak" autocomplete="off" autocapitalize="off" enterkeyhint="go">
                    <button type="button" class="btn b sq" @click="$scan()" aria-label="Kamera"><x-m-icon name="scan" /></button>
                </form>

                <div class="card empty" x-show="!lines.length" style="padding:24px">
                    <b>Belum ada barang</b>Scan barcode barang. Scan kode rak setelahnya untuk menaruh ke rak itu.
                </div>

                <template x-for="(line, i) in lines" :key="line.variant_id">
                    <div class="card pad" :style="active === i ? 'border-color:var(--text)' : ''" @click="active = i">
                        <div class="row" style="align-items:flex-start">
                            <div class="grow">
                                <div class="t mono" style="font-size:13px;font-weight:650" x-text="line.sku"></div>
                                <div class="m muted" style="font-size:12px" x-text="line.name"></div>
                            </div>
                            <button type="button" class="muted" @click.stop="remove(i)" aria-label="Hapus"><x-m-icon name="trash" size="s" /></button>
                        </div>
                        <div class="row" style="margin-top:10px;gap:8px;flex-wrap:wrap">
                            <div>
                                <div class="label" style="margin-bottom:4px">Baik</div>
                                <div class="step"><button type="button" @click.stop="line.received = Math.max(0, line.received - 1)"><x-m-icon name="minus" size="s" /></button><input type="number" inputmode="numeric" x-model.number="line.received" @click.stop><button type="button" @click.stop="line.received++"><x-m-icon name="plus" size="s" /></button></div>
                            </div>
                            <div>
                                <div class="label" style="margin-bottom:4px">Rusak</div>
                                <div class="step"><button type="button" @click.stop="line.damaged = Math.max(0, line.damaged - 1)"><x-m-icon name="minus" size="s" /></button><input type="number" inputmode="numeric" x-model.number="line.damaged" @click.stop><button type="button" @click.stop="line.damaged++"><x-m-icon name="plus" size="s" /></button></div>
                            </div>
                            <div class="grow" style="min-width:120px">
                                <div class="label" style="margin-bottom:4px">Rak (opsional)</div>
                                <select class="select" style="height:42px;font-size:13.5px;border-radius:12px" x-model="line.location_id" @click.stop>
                                    <option value="">Tanpa bin</option>
                                    <template x-for="l in locations" :key="l.id"><option :value="l.id" x-text="l.code" :selected="String(l.id) === String(line.location_id)"></option></template>
                                </select>
                            </div>
                        </div>
                    </div>
                </template>

                <textarea x-model="notes" class="textarea" placeholder="Catatan (opsional)"></textarea>

                <div class="bar">
                    <button type="button" class="btn s" :class="busy && 'is-busy'" @click="submit('save')">Simpan draft</button>
                    @can('receipts.receive')
                        <button type="button" class="btn p" :class="busy && 'is-busy'" @click="submit('receive')"><x-m-icon name="check" />Terima</button>
                    @endcan
                </div>
            </div>
        </template>

        {{-- Setelah diterima: panduan simpan ke rak --}}
        <template x-if="result">
            <div class="stack">
                <div class="banner ok"><x-m-icon name="check" size="s" /><div><b x-text="result.number"></b><span x-text="result.message"></span></div></div>
                <template x-if="result.putaway?.length">
                    <div>
                        <div class="h2">Simpan ke rak <span class="muted" style="font-size:12px">scan label rak untuk konfirmasi</span></div>
                        <div class="card list">
                            <template x-for="p in result.putaway" :key="p.sku + p.location">
                                <div class="it">
                                    <span class="check" :class="confirmed[p.sku + p.location] ? 'done' : 'todo'"><x-m-icon name="check" size="xs" x-show="confirmed[p.sku + p.location]" /></span>
                                    <div class="grow"><div class="t mono" style="font-size:13px" x-text="p.sku"></div><div class="m truncate" x-text="p.name"></div></div>
                                    <div class="center"><div class="bigcode" style="font-size:15px" x-text="p.location"></div><div class="muted" style="font-size:11.5px" x-text="`${p.qty} pcs`"></div></div>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
                <div class="bar">
                    <a href="{{ route('mobile.receipts') }}" class="btn p">Selesai</a>
                </div>
            </div>
        </template>
    </div>
</x-layouts.mobile>
