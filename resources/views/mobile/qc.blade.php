<x-layouts.mobile title="QC Kemas" with-bar :sub="$warehouse?->name" :back="route('mobile.home')">
    <div x-data="qcApp" class="content stack">
        {{-- Langkah 1: scan resi / pesanan --}}
        <template x-if="step === 'find'">
            <div class="stack">
                <div class="row" style="gap:8px">
                    <span class="chip vio"><span class="d"></span>1. Scan resi</span>
                    <span class="chip">2. Scan barang</span>
                    <span class="chip">3. Foto & selesai</span>
                </div>

                <div class="card pad center" style="padding:22px 16px">
                    <div class="thumb tone-vio" style="margin:0 auto 12px;width:60px;height:60px;border-radius:18px"><x-m-icon name="barcode" size="l" /></div>
                    <div style="font-size:16px;font-weight:650">Scan resi atau no. pesanan</div>
                    <div class="muted" style="font-size:12.5px;margin:4px 0 16px">Barang baru bisa discan setelah pesanannya ketemu.</div>
                    <button type="button" class="btn b" style="width:100%" @click="openCamera()"><x-m-icon name="scan" />Buka kamera</button>
                    <form class="scanfield" style="margin-top:10px" @submit.prevent="find()">
                        <input x-model="code" class="input" placeholder="atau ketik resi / no. SO" autocomplete="off" autocapitalize="off" enterkeyhint="search">
                        <button class="btn s sq" aria-label="Cari"><x-m-icon name="search" /></button>
                    </form>
                </div>

                <template x-if="alert">
                    <div class="banner" :class="alert.kind"><x-m-icon name="alert" size="s" /><div><b x-text="alert.title"></b><span x-text="alert.text"></span></div></div>
                </template>

                <div class="h2">
                    <span>Antrean QC <span class="muted" x-text="`(${queue.length})`"></span></span>
                    <button type="button" @click="refreshQueue()" :class="loadingQueue && 'is-busy'"><span x-text="loadingQueue ? 'Memuat…' : 'Muat ulang'"></span></button>
                </div>
                <div class="card list" x-show="queue.length">
                    <template x-for="o in queue.slice(0, 50)" :key="o.id">
                        <button type="button" class="it" @click="find(o.number)">
                            <span class="thumb tone-vio"><x-m-icon name="package" /></span>
                            <div class="grow">
                                <div class="t mono truncate" x-text="o.tracking || o.number"></div>
                                <div class="m truncate" x-text="`${o.number} · ${o.customer || '-'} · ${o.courier || 'Tanpa kurir'}`"></div>
                            </div>
                            <span class="chip" x-text="`${o.lines.reduce((s, l) => s + l.qty, 0)} pcs`"></span>
                        </button>
                    </template>
                </div>
                <div class="card empty" x-show="!queue.length && !loadingQueue">
                    <div class="thumb tone-ok"><x-m-icon name="check" size="l" /></div>
                    <b>Antrean kosong</b>Belum ada pesanan yang sedang diambil.
                </div>
            </div>
        </template>

        {{-- Langkah 2: scan barang satu per satu --}}
        <template x-if="step === 'items' && order">
            <div class="stack">
                <div class="card pad">
                    <div class="row">
                        <div class="grow">
                            <div class="label" x-text="`${order.channel || 'Pesanan'} · ${order.courier || 'Tanpa kurir'}`"></div>
                            <div class="bigcode truncate" style="font-size:19px;margin-top:3px" x-text="order.tracking || order.number"></div>
                            <div class="muted truncate" style="font-size:12.5px;margin-top:2px" x-text="`${order.number} · ${order.customer || '-'}`"></div>
                        </div>
                        <div class="ring" :style="`--p:${percent};--c:${complete ? 'var(--ok)' : 'var(--vio)'};--sz:58px`"><div style="font-size:13px" x-text="`${done}/${total}`"></div></div>
                    </div>
                </div>

                <template x-if="alert">
                    <div class="banner flash-bad" :class="alert.kind"><x-m-icon name="alert" size="s" /><div><b x-text="alert.title"></b><span x-text="alert.text"></span></div></div>
                </template>
                <template x-if="!alert && last">
                    <div class="banner ok" :key="last.code + done"><x-m-icon name="check" size="s" /><div class="grow truncate"><b class="mono" x-text="last.code"></b><span x-text="last.text"></span></div></div>
                </template>

                <form class="scanfield" @submit.prevent="scanItem()">
                    <input x-model="code" class="input" placeholder="Scan / ketik SKU barang" autocomplete="off" autocapitalize="off" enterkeyhint="go">
                    <button type="button" class="btn sq" :class="camera ? 's' : 'b'" @click="openCamera()" :aria-label="camera ? 'Tutup kamera' : 'Buka kamera'"><x-m-icon name="scan" x-show="!camera" /><x-m-icon name="x" x-show="camera" /></button>
                </form>

                <div class="card list">
                    <template x-for="line in order.lines" :key="line.variant_id">
                        <div class="it">
                            <span class="check" :class="(scanned[line.variant_id] || 0) >= line.qty ? 'done' : 'todo'">
                                <x-m-icon name="check" size="xs" x-show="(scanned[line.variant_id] || 0) >= line.qty" />
                            </span>
                            <div class="grow">
                                <div class="t mono" style="font-size:13px" x-text="line.sku"></div>
                                <div class="m" style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden" x-text="line.name"></div>
                            </div>
                            <div class="step">
                                <button type="button" @click="adjust(line, -1)" aria-label="Kurangi"><x-m-icon name="minus" size="s" /></button>
                                <input type="text" readonly :value="`${scanned[line.variant_id] || 0}/${line.qty}`" style="width:56px;font-size:13.5px">
                                <button type="button" @click="adjust(line, 1)" aria-label="Tambah"><x-m-icon name="plus" size="s" /></button>
                            </div>
                        </div>
                    </template>
                </div>
                <p class="muted" style="font-size:11.5px;margin:0 2px">Tombol + − untuk barang yang barcodenya rusak. Scan tetap disarankan.</p>

                <div class="field-label">Foto paket <span style="font-weight:500">(opsional)</span></div>
                <div class="photos">
                    <template x-if="photoUrl">
                        <div class="photo"><img :src="photoUrl" alt="Foto paket"><button type="button" class="x" @click="removePhoto()" aria-label="Hapus foto"><x-m-icon name="x" size="xs" /></button></div>
                    </template>
                    <label class="addph" x-show="!photoUrl" @click="closeCamera()">
                        <x-m-icon name="camera" />Foto paket
                        <input type="file" accept="image/*" capture="environment" @change="pickPhoto($event)">
                    </label>
                </div>

                <div class="bar">
                    <button type="button" class="btn s sq" @click="reset()" aria-label="Batal"><x-m-icon name="x" /></button>
                    <button type="button" class="btn p" :disabled="!complete" :class="busy && 'is-busy'" @click="finish()">
                        <x-m-icon name="check" x-show="!busy" /><span x-text="complete ? 'Selesai QC' : `Scan ${total - done} barang lagi`"></span>
                    </button>
                </div>
            </div>
        </template>
    </div>

</x-layouts.mobile>
