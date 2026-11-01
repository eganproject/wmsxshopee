<x-layouts.mobile title="Retur" :sub="$warehouse?->name" :back="route('mobile.home')" with-bar>
    <div x-data="returnApp({})" class="content stack">
        <template x-if="!order">
            <div class="stack">
                <div class="card pad center" style="padding:22px 16px">
                    <div class="thumb tone-bad" style="margin:0 auto 12px;width:60px;height:60px;border-radius:18px"><x-m-icon name="undo" size="l" /></div>
                    <div style="font-size:16px;font-weight:650">Scan resi paket retur</div>
                    <div class="muted" style="font-size:12.5px;margin:4px 0 16px">Resi asli, resi retur, atau no. pesanan.</div>
                    <button type="button" class="btn b" style="width:100%" @click="$scan()" :class="loading && 'is-busy'"><x-m-icon name="scan" />Scan resi</button>
                    <form class="scanfield" style="margin-top:10px" @submit.prevent="lookup()">
                        <input x-model="code" class="input" placeholder="atau ketik resi / no. pesanan" autocomplete="off" autocapitalize="off" enterkeyhint="search">
                        <button class="btn s sq" aria-label="Cari"><x-m-icon name="search" /></button>
                    </form>
                </div>

                <div class="h2">Retur terbaru</div>
                @if ($returns->isEmpty())
                    <div class="card empty" style="padding:22px"><b>Belum ada retur</b></div>
                @else
                    <div class="card list">
                        @foreach ($returns as $r)
                            <div class="it">
                                <span class="thumb tone-bad"><x-m-icon name="undo" /></span>
                                <div class="grow">
                                    <div class="t mono" style="font-size:13px">{{ $r->number }}</div>
                                    <div class="m truncate">{{ $r->tracking_number ?: '-' }} · {{ (int) $r->items_sum_qty_good }} baik · {{ (int) $r->items_sum_qty_damaged }} rusak</div>
                                </div>
                                <span @class(['chip', 'ok' => ! $r->isDraft(), 'warn' => $r->isDraft()])>{{ $r->statusLabel() }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </template>

        <template x-if="order">
            <div class="stack">
                <div class="card pad">
                    <div class="row">
                        <div class="grow">
                            <div class="label" x-text="order.channel_name || 'Pesanan'"></div>
                            <div class="bigcode truncate" style="font-size:18px;margin-top:2px" x-text="order.number"></div>
                            <div class="muted truncate" style="font-size:12.5px" x-text="`${order.customer_name || '-'} · resi ${order.scanned}`"></div>
                        </div>
                        <button type="button" class="btn s sm" @click="order = null; rows = []">Ganti</button>
                    </div>
                </div>

                <template x-for="row in rows" :key="row.id">
                    <div class="card pad">
                        <div class="row" style="align-items:flex-start">
                            <div class="grow">
                                <div class="t mono" style="font-size:13px;font-weight:650" x-text="row.sku"></div>
                                <div class="muted" style="font-size:12px" x-text="row.label"></div>
                            </div>
                            <div class="step">
                                <button type="button" @click="row.qty = Math.max(0, row.qty - 1)"><x-m-icon name="minus" size="s" /></button>
                                <input type="number" inputmode="numeric" x-model.number="row.qty" :max="row.remaining">
                                <button type="button" @click="row.qty = Math.min(row.remaining, row.qty + 1)"><x-m-icon name="plus" size="s" /></button>
                            </div>
                        </div>
                        <div class="seg" style="margin-top:10px">
                            <button type="button" :class="row.condition === 'good' && 'on'" @click="row.condition = 'good'">Layak jual</button>
                            <button type="button" :class="row.condition === 'damaged' && 'on'" @click="row.condition = 'damaged'">Rusak</button>
                            <button type="button" :class="row.condition === 'missing' && 'on'" @click="row.condition = 'missing'">Tidak ada</button>
                        </div>
                        <template x-if="row.condition !== 'missing'">
                            <div>
                                <select x-model="row.reason" class="select" style="margin-top:10px">
                                    <option value="">Alasan retur…</option>
                                    @foreach ($reasons as $key => $label)
                                        @continue($key === 'missing')
                                        <option value="{{ $key }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                <div class="row" style="margin-top:10px;align-items:flex-end">
                                    <div class="grow" x-show="row.condition === 'good'">
                                        <div class="label" style="margin-bottom:4px">Kembalikan ke rak (opsional)</div>
                                        <select class="select" style="height:44px" x-model="row.location_id">
                                            <option value="">Tanpa bin</option>
                                            <template x-for="l in locations" :key="l.id"><option :value="l.id" x-text="l.code" :selected="String(l.id) === String(row.location_id)"></option></template>
                                        </select>
                                    </div>
                                    <div class="grow" x-show="row.condition !== 'good'"></div>
                                    <template x-if="row.photoUrl">
                                        <div class="photo" style="width:64px;height:64px"><img :src="row.photoUrl" alt="Foto"><button type="button" class="x" @click="row.photo = null; row.photoUrl = null"><x-m-icon name="x" size="xs" /></button></div>
                                    </template>
                                    <label class="addph" style="width:64px;height:64px" x-show="!row.photoUrl"><x-m-icon name="camera" />Foto<input type="file" accept="image/*" capture="environment" @change="pickPhoto(row, $event)"></label>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>

                <textarea x-model="notes" class="textarea" placeholder="Catatan (opsional)"></textarea>

                <div class="bar">
                    <button type="button" class="btn s" :class="busy && 'is-busy'" @click="submit('save')">Simpan draft</button>
                    @can('returns.receive')
                        <button type="button" class="btn p" :class="busy && 'is-busy'" :disabled="!ready" @click="submit('receive')"><x-m-icon name="check" />Terima retur</button>
                    @endcan
                </div>
            </div>
        </template>
    </div>
</x-layouts.mobile>
