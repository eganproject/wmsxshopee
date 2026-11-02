<x-layouts.mobile title="Cek Stok Rak" :sub="$warehouse?->name" :back="route('mobile.home')" with-bar>
    <div x-data="stockCheckApp({})" class="content stack">
        <template x-if="!location">
            <div class="stack">
                <div class="card pad center" style="padding:22px 16px">
                    <div class="thumb tone-amber" style="margin:0 auto 12px;width:60px;height:60px;border-radius:18px"><x-m-icon name="layers" size="l" /></div>
                    <div style="font-size:16px;font-weight:650">Scan label rak</div>
                    <div class="muted" style="font-size:12.5px;margin:4px 0 16px">Hitung isi rak, selisihnya diajukan sebagai penyesuaian stok dan menunggu approval.</div>
                    <button type="button" class="btn b" style="width:100%" @click="$scan()" :class="loading && 'is-busy'"><x-m-icon name="scan" />Scan rak</button>
                    <form class="scanfield" style="margin-top:10px" @submit.prevent="scan()">
                        <input x-model="code" class="input" placeholder="atau ketik kode rak" autocomplete="off" autocapitalize="off" enterkeyhint="go">
                        <button class="btn s sq" aria-label="Buka"><x-m-icon name="arrow" /></button>
                    </form>
                </div>
            </div>
        </template>

        <template x-if="location">
            <div class="stack">
                <div class="row">
                    <button type="button" class="iconbtn" @click="reset()" aria-label="Ganti rak"><x-m-icon name="left" /></button>
                    <div class="grow"><div class="label">Rak</div><div class="bigcode" style="font-size:20px" x-text="location.code"></div></div>
                    <span class="chip" x-text="`${counted}/${items.length} dihitung`"></span>
                </div>
                <form class="scanfield" @submit.prevent="scan()">
                    <input x-model="code" class="input" placeholder="Scan / ketik SKU (tiap scan +1)" autocomplete="off" autocapitalize="off" enterkeyhint="go">
                    <button type="button" class="btn b sq" @click="$scan({ hint: 'Scan barang di rak ini' })" aria-label="Kamera"><x-m-icon name="scan" /></button>
                </form>

                <div class="card empty" x-show="!items.length" style="padding:22px"><b>Rak kosong di sistem</b>Scan barang yang ada di rak ini.</div>
                <div class="card list" x-show="items.length">
                    <template x-for="row in items" :key="row.variant_id">
                        <div class="it">
                            <div class="grow">
                                <div class="t mono" style="font-size:13px" x-text="row.sku"></div>
                                <div class="m truncate" x-text="row.name"></div>
                                <div class="m">
                                    <span x-text="`Sistem ${row.system_qty}`"></span>
                                    <template x-if="diff(row) !== null">
                                        <span :style="diff(row) === 0 ? 'color:var(--ok)' : 'color:var(--bad);font-weight:600'" x-text="diff(row) === 0 ? ' · cocok' : ` · selisih ${diff(row) > 0 ? '+' : ''}${diff(row)}`"></span>
                                    </template>
                                </div>
                            </div>
                            <div class="step" :class="row.counted !== null && 'active'">
                                <button type="button" @click="row.counted = Math.max(0, (row.counted ?? 0) - 1)"><x-m-icon name="minus" size="s" /></button>
                                <input type="number" inputmode="numeric" placeholder="–" :value="row.counted ?? ''" @change="row.counted = $event.target.value === '' ? null : Math.max(0, +$event.target.value)">
                                <button type="button" @click="row.counted = (row.counted ?? 0) + 1"><x-m-icon name="plus" size="s" /></button>
                            </div>
                        </div>
                    </template>
                </div>

                <template x-if="changes.length">
                    <div>
                        <label class="field-label">Alasan selisih</label>
                        <select x-model="reason" class="select">
                            <option value="">Pilih otomatis dari selisih</option>
                            @foreach ($reasons as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <textarea x-model="notes" class="textarea" style="margin-top:10px" placeholder="Catatan (opsional)"></textarea>
                    </div>
                </template>

                <div class="bar">
                    <button type="button" class="btn p" :class="busy && 'is-busy'" @click="submit()">
                        <span x-text="changes.length ? `Ajukan ${changes.length} selisih` : 'Semua cocok, selesai'"></span>
                    </button>
                </div>
            </div>
        </template>
    </div>
</x-layouts.mobile>
