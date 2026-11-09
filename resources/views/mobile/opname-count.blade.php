<x-layouts.mobile :title="$count->number" :sub="$count->zone?->name ? 'Zona '.$count->zone->name : 'Seluruh gudang'" :back="route('mobile.opname')" with-bar>
    <div x-data="opnameApp(@js([
        'id' => $count->id,
        'number' => $count->number,
        'linesUrl' => route('mobile.opname.lines', $count),
        'saveUrl' => route('mobile.opname.save', $count),
    ]))" class="content stack">
        <template x-if="!bin">
            <div class="stack">
                <div class="hero">
                    <div class="glow"></div>
                    <div class="muted" style="font-size:12px;position:relative">Progres hitung</div>
                    <div class="big-title" style="margin-top:4px;position:relative">
                        <span x-text="lines.filter(l => l.counted_qty !== null && l.counted_qty !== undefined).length"></span>
                        <span class="muted" style="font-size:15px;font-weight:600" x-text="`/ ${lines.length} baris`"></span>
                    </div>
                    <div class="hero-stats">
                        <div><b x-text="bins.length"></b><span>Rak</span></div>
                        <div><b x-text="bins.filter(b => b.counted === b.total).length"></b><span>Rak selesai</span></div>
                        <div><b x-text="dirtyCount"></b><span>Belum terkirim</span></div>
                    </div>
                </div>

                <form class="scanfield" @submit.prevent="scan()">
                    <input x-model="code" class="input" placeholder="Scan / ketik kode rak" autocomplete="off" autocapitalize="off" enterkeyhint="go">
                    <button type="button" class="btn b sq" @click="openCamera()" aria-label="Kamera"><x-m-icon name="scan" /></button>
                </form>

                <div class="card empty" x-show="!loaded"><b>Memuat lembar…</b></div>
                <div class="card list" x-show="loaded && bins.length">
                    <template x-for="b in bins" :key="b.code">
                        <button type="button" class="it" @click="openBin(b)">
                            <span class="thumb" :class="b.counted === b.total ? 'tone-ok' : (b.counted ? 'tone-warn' : 'tone-plain')"><x-m-icon name="pin" /></span>
                            <div class="grow"><div class="t mono" x-text="b.code"></div><div class="m" x-text="`${b.counted}/${b.total} barang dihitung`"></div></div>
                            <x-m-icon name="right" size="s" class="muted" />
                        </button>
                    </template>
                </div>
            </div>
        </template>

        <template x-if="bin">
            <div class="stack">
                <div class="row">
                    <button type="button" class="iconbtn" @click="leaveBin()" aria-label="Daftar rak"><x-m-icon name="left" /></button>
                    <div class="grow"><div class="label">Rak</div><div class="bigcode" style="font-size:20px" x-text="bin.code"></div></div>
                    <button type="button" class="chip" @click="blind = !blind" style="height:32px">
                        <x-m-icon name="eyeoff" size="xs" x-show="blind" /><x-m-icon name="eye" size="xs" x-show="!blind" />
                        <span x-text="blind ? 'Stok sistem disembunyikan' : 'Stok sistem tampil'"></span>
                    </button>
                </div>

                <form class="scanfield" @submit.prevent="scan()">
                    <input x-model="code" class="input" placeholder="Scan / ketik SKU (tiap scan +1)" autocomplete="off" autocapitalize="off" enterkeyhint="go">
                    <button type="button" class="btn sq" :class="camera ? 's' : 'b'" @click="openCamera()" :aria-label="camera ? 'Tutup kamera' : 'Kamera'">
                        <x-m-icon name="scan" x-show="!camera" /><x-m-icon name="x" x-show="camera" />
                    </button>
                </form>

                <template x-if="lastLine">
                    <div class="card" style="padding:10px 12px;display:flex;align-items:center;gap:10px">
                        <span class="thumb tone-ok"><x-m-icon name="check" /></span>
                        <div class="grow" style="min-width:0">
                            <div class="label">Terakhir discan</div>
                            <div class="t mono" style="font-size:14px;font-weight:700" x-text="lastLine.sku"></div>
                            <div class="m truncate" x-text="lastLine.name"></div>
                        </div>
                        <div style="text-align:right">
                            <div class="label">Dihitung</div>
                            <div class="bigcode" style="font-size:22px" x-text="lastLine.counted_qty ?? 0"></div>
                        </div>
                    </div>
                </template>

                <div class="card list">
                    <template x-for="row in binLines" :key="row.key || (row.variant_id + ':' + row.location_id)">
                        <div class="it" :data-line="row.key">
                            <div class="grow">
                                <div class="t mono" style="font-size:13px" x-text="row.sku"></div>
                                <div class="m truncate" x-text="row.name"></div>
                                <div class="m" x-show="!blind || diff(row) !== null">
                                    <span x-show="!blind" x-text="`Sistem ${row.system_qty}`"></span>
                                    <template x-if="!blind && diff(row) !== null">
                                        <span :style="diff(row) === 0 ? 'color:var(--ok)' : 'color:var(--bad)'" x-text="diff(row) === 0 ? ' · cocok' : ` · selisih ${diff(row) > 0 ? '+' : ''}${diff(row)}`"></span>
                                    </template>
                                    <template x-if="row.found"><span class="chip warn" style="height:20px">baru</span></template>
                                </div>
                            </div>
                            <div class="step" :class="row.dirty && 'active'">
                                <button type="button" @click="set(row, (row.counted_qty ?? 0) - 1)"><x-m-icon name="minus" size="s" /></button>
                                <input type="number" inputmode="numeric" placeholder="–" :value="row.counted_qty ?? ''" @change="set(row, $event.target.value)">
                                <button type="button" @click="set(row, (row.counted_qty ?? 0) + 1)"><x-m-icon name="plus" size="s" /></button>
                            </div>
                        </div>
                    </template>
                </div>
                <p class="muted" style="font-size:12px;margin:0 2px">Barang yang ada di rak tapi tidak tercatat: scan saja, otomatis ditambahkan.</p>
            </div>
        </template>

        <div class="bar">
            <button type="button" class="btn p" :class="busy && 'is-busy'" @click="save('save')">
                <x-m-icon name="cloud" x-show="!busy" /><span x-text="dirtyCount ? `Kirim ${dirtyCount} hitungan` : 'Semua terkirim'"></span>
            </button>
            @can('opname.post')
                <button type="button" class="btn s sq" @click="confirm('Posting opname? Selisih langsung mengubah stok sistem.') && save('post')" aria-label="Posting"><x-m-icon name="shield" /></button>
            @endcan
        </div>
    </div>
</x-layouts.mobile>
