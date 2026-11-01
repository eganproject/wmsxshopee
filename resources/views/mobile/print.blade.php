<x-layouts.mobile title="Cetak Label" sub="Printer thermal Bluetooth" :back="route('mobile.home')" active="print" with-bar>
    <div x-data="printApp(@js([
        'canProducts' => auth()->user()->can('products.view'),
        'canLocations' => auth()->user()->can('warehouses.view'),
        'warehouse' => $warehouse?->code,
    ]))" class="content stack">
        <div class="banner warn" x-show="!isApp" x-cloak><x-m-icon name="alert" size="s" /><div><b>Buka dari aplikasi Android</b>Printer Bluetooth hanya bisa dipakai dari aplikasi Optima Gudang.</div></div>

        <div class="card pad">
            <div class="row">
                <span class="thumb tone-brand"><x-m-icon name="bt" /></span>
                <div class="grow">
                    <div class="t" style="font-weight:650" x-text="s.name || 'Belum ada printer'"></div>
                    <div class="m muted" :class="s.address && 'mono'" style="font-size:11.5px" x-text="s.address || 'Pasangkan printer di Bluetooth HP, lalu pilih di sini'"></div>
                </div>
                <button type="button" class="btn s sm" :class="loadingPrinters && 'is-busy'" @click="loadPrinters()" :disabled="!isApp">Pilih</button>
            </div>
            <template x-if="printers.length">
                <div class="list" style="margin:10px -14px -14px;border-top:1px solid var(--border)">
                    <template x-for="p in printers" :key="p.address">
                        <button type="button" class="it" @click="choose(p)">
                            <x-m-icon name="printer" />
                            <div class="grow"><div class="t" x-text="p.name || 'Tanpa nama'"></div><div class="m mono" x-text="p.address"></div></div>
                            <x-m-icon name="check" size="s" x-show="p.address === s.address" />
                        </button>
                    </template>
                </div>
            </template>
        </div>

        <div class="field-label">Jenis printer</div>
        <div class="seg">
            <button type="button" :class="s.lang === 'escpos' && 'on'" @click="s.lang = 'escpos'">Struk (ESC/POS)</button>
            <button type="button" :class="s.lang === 'tspl' && 'on'" @click="s.lang = 'tspl'">Label (TSPL)</button>
        </div>
        <template x-if="s.lang === 'escpos'">
            <div class="seg">
                <button type="button" :class="s.widthMm == 58 && 'on'" @click="s.widthMm = 58">Kertas 58 mm</button>
                <button type="button" :class="s.widthMm == 80 && 'on'" @click="s.widthMm = 80">Kertas 80 mm</button>
            </div>
        </template>
        <template x-if="s.lang === 'tspl'">
            <div class="row" style="gap:8px">
                <label class="grow"><span class="label">Lebar (mm)</span><input class="input" type="number" inputmode="numeric" x-model.number="s.labelWidthMm" style="height:44px;margin-top:4px"></label>
                <label class="grow"><span class="label">Tinggi (mm)</span><input class="input" type="number" inputmode="numeric" x-model.number="s.labelHeightMm" style="height:44px;margin-top:4px"></label>
                <label class="grow"><span class="label">Jarak (mm)</span><input class="input" type="number" inputmode="numeric" x-model.number="s.gapMm" style="height:44px;margin-top:4px"></label>
            </div>
        </template>
        @can('sales.fulfill')
            <button type="button" class="card pad row" style="width:100%;text-align:left" @click="s.autoQc = !s.autoQc">
                <div class="grow"><div class="t" style="font-weight:600">Cetak resi otomatis setelah QC</div><div class="m muted" style="font-size:12px">Resi marketplace langsung dicetak saat QC selesai.</div></div>
                <span class="toggle" :class="s.autoQc && 'on'"></span>
            </button>
        @endcan

        <div class="h2">Buat label</div>
        @if (auth()->user()->can('products.view') && auth()->user()->can('warehouses.view'))
            <div class="seg">
                <button type="button" :class="mode === 'product' && 'on'" @click="mode = 'product'; target = null; preview = null">Label barang</button>
                <button type="button" :class="mode === 'location' && 'on'" @click="mode = 'location'; target = null; preview = null">Label rak</button>
            </div>
        @endif
        <form class="scanfield" @submit.prevent="scan()">
            <input x-model="code" class="input" :placeholder="mode === 'product' ? 'Scan / ketik SKU' : 'Scan / ketik kode rak'" autocomplete="off" autocapitalize="off" enterkeyhint="go">
            <button type="button" class="btn b sq" @click="$scan()" aria-label="Kamera"><x-m-icon name="scan" /></button>
        </form>

        <div class="preview-box">
            <template x-if="preview"><img :src="preview" class="preview-label" alt="Pratinjau label"></template>
            <div class="muted" style="font-size:12.5px" x-show="!preview">Pratinjau label muncul di sini setelah scan.</div>
            <div class="row" x-show="preview">
                <span class="label">Jumlah</span>
                <div class="step">
                    <button type="button" @click="copies = Math.max(1, copies - 1)"><x-m-icon name="minus" size="s" /></button>
                    <input type="number" inputmode="numeric" x-model.number="copies">
                    <button type="button" @click="copies = Math.min(100, copies + 1)"><x-m-icon name="plus" size="s" /></button>
                </div>
            </div>
        </div>

        <div class="bar">
            <button type="button" class="btn s" :class="busy && 'is-busy'" @click="test()" :disabled="!s.address">Tes printer</button>
            <button type="button" class="btn p" :class="busy && 'is-busy'" @click="print()" :disabled="!preview || !s.address"><x-m-icon name="printer" />Cetak</button>
        </div>
    </div>
</x-layouts.mobile>
