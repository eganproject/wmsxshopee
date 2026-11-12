<x-layouts.app :title="'QC '.$order->number">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('sales.qc.index') }}" class="hover:text-slate-700">QC Kemas</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">{{ $order->number }}</span>
    </x-slot:breadcrumb>

    <x-page-header :title="'QC '.$order->number" :description="($order->isOutbound() ? 'Barang Keluar Manual ('.$order->outboundPurposeLabel().') · ' : '').$order->customer_name.' · '.($order->courier?->name ?? 'tanpa kurir').($order->tracking_number ? ' · resi '.$order->tracking_number : '')" icon="heroicon-o-viewfinder-circle">
        @can('sales.view')
            <a href="{{ route('sales.orders.show', $order) }}" class="btn-secondary"><x-heroicon-o-eye class="size-5" /> Detail Pesanan</a>
        @endcan
        <a href="{{ route('sales.qc.index') }}" class="btn-secondary"><x-heroicon-o-arrow-left class="size-5" /> Kembali</a>
    </x-page-header>

    <x-form-errors />

    @if ($order->isQcHeld())
        <div class="mb-5 flex flex-col gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 sm:flex-row sm:items-center">
            <x-heroicon-o-pause-circle class="size-6 shrink-0" />
            <div class="min-w-0 flex-1">
                <p class="font-medium">QC ditahan: {{ $order->qc_hold_reason }}</p>
                <p class="mt-0.5">Oleh {{ $order->qcHolder?->name ?? '—' }} pada {{ $order->qc_held_at->translatedFormat('d M Y H:i') }}. Selesaikan masalahnya, lalu lepas tahan untuk melanjutkan scan.</p>
            </div>
            <form method="POST" action="{{ route('sales.qc.release', $order) }}">
                @csrf
                <button type="submit" class="btn-primary"><x-heroicon-o-play class="size-5" /> Lepas Tahan</button>
            </form>
        </div>
    @endif

    <div x-data="{
            lines: @js($expected['lines']),
            bundles: @js($expected['bundles']),
            restored: @js($restored),
            held: @js($order->isQcHeld()),
            orderId: @js($order->id),
            warehouseId: @js($order->warehouse_id),
            scanned: {},
            code: '',
            message: null,
            ok: true,
            holdOpen: false,
            subOpen: false,
            sub: { from: '', qty: 1 },
            init() {
                this.lines.forEach(l => this.scanned[l.variant_id] = l.exempt ? l.qty : Math.min(l.qty, Number(this.restored[l.variant_id] ?? 0)));
                if (this.lines.length) this.sub.from = this.lines[0].variant_id;
            },
            get total() { return this.lines.reduce((s, l) => s + l.qty, 0); },
            get done() { return this.lines.reduce((s, l) => s + Math.min(this.scanned[l.variant_id], l.qty), 0); },
            get complete() { return this.lines.every(l => this.scanned[l.variant_id] === l.qty); },
            get subLine() { return this.lines.find(l => l.variant_id == this.sub.from); },
            beep(good) {
                try {
                    const ctx = new (window.AudioContext || window.webkitAudioContext)();
                    const osc = ctx.createOscillator();
                    osc.frequency.value = good ? 880 : 220;
                    osc.connect(ctx.destination);
                    osc.start(); osc.stop(ctx.currentTime + (good ? 0.08 : 0.35));
                } catch (e) {}
            },
            feedback(good, text) { this.ok = good; this.message = text; this.beep(good); },
            matches(line, key) {
                return line.sku.toLowerCase() === key
                    || (line.barcode && line.barcode.toLowerCase() === key)
                    || (line.aliases || []).some(a => a.toLowerCase() === key);
            },
            scan() {
                const value = this.code.trim(); this.code = '';
                if (!value || this.held) return;
                const key = value.toLowerCase();
                if (this.bundles[key]) {
                    // Isi paket yang termasuk SKU tanpa scan sudah otomatis terhitung.
                    const contents = Object.entries(this.bundles[key]).filter(([id]) => !this.lines.find(l => l.variant_id == id)?.exempt);
                    const over = contents.find(([id, qty]) => this.scanned[id] + qty > this.lines.find(l => l.variant_id == id).qty);
                    if (over) return this.feedback(false, `Paket ${value} melebihi jumlah pesanan.`);
                    contents.forEach(([id, qty]) => this.scanned[id] += qty);
                    return this.finish(`Paket ${value} ✓`);
                }
                const line = this.lines.find(l => this.matches(l, key));
                if (!line) {
                    window.reportScanMiss?.(value, 'qc', { sales_order_id: this.orderId, warehouse_id: this.warehouseId });
                    return this.feedback(false, `${value} tidak ada di pesanan ini. Jangan dimasukkan ke paket.`);
                }
                if (line.exempt) return this.feedback(true, `${line.sku} tidak perlu di-scan (otomatis terhitung).`);
                if (this.scanned[line.variant_id] >= line.qty) return this.feedback(false, `${line.sku} sudah cukup (${line.qty}). Kelebihan, keluarkan dari paket.`);
                this.scanned[line.variant_id]++;
                this.finish(`${line.sku} ${this.scanned[line.variant_id]}/${line.qty}`);
            },
            finish(text) {
                this.feedback(true, text);
                if (this.complete) { this.message = 'Semua barang cocok. Menutup paket...'; this.$nextTick(() => this.$refs.form.submit()); }
            },
            reset() { this.lines.forEach(l => this.scanned[l.variant_id] = l.exempt ? l.qty : 0); this.message = null; this.$refs.code?.focus(); },
         }">
        <form method="POST" action="{{ route('sales.qc.store', $order) }}" x-ref="form">
            @csrf
            <template x-for="line in lines" :key="line.variant_id">
                <input type="hidden" :name="`scanned[${line.variant_id}]`" :value="scanned[line.variant_id]">
            </template>

            <div class="card mb-6 p-5" :class="held && 'opacity-60'">
                <label for="scan-code" class="form-label">Scan Barcode / QR <span class="whitespace-nowrap">SKU <x-hint text="Scan barcode, barcode alias atau SKU setiap barang yang dimasukkan ke paket. Paket ditandai Sudah Dikemas bila jumlah semua barang cocok dengan pesanan." /></span></label>
                <div class="relative">
                    <x-heroicon-o-qr-code class="pointer-events-none absolute top-1/2 left-3 size-5 -translate-y-1/2 text-slate-400" />
                    <input id="scan-code" x-ref="code" x-model="code" @keydown.enter.prevent="scan()" data-auto-scan="enter" @unless ($order->isQcHeld()) autofocus @endunless autocomplete="off" data-native
                           :disabled="held" class="form-input h-12 pl-10 font-mono text-lg" :placeholder="held ? 'QC ditahan. Lepas tahan dulu.' : 'Scan barang satu per satu...'">
                </div>
                <div x-show="message" x-cloak class="mt-3 rounded-lg px-4 py-3 text-base font-medium"
                     :class="ok ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'" x-text="message"></div>
                <div class="mt-4">
                    <div class="flex justify-between text-sm text-slate-600"><span>Progres</span><span class="tabular-nums" x-text="`${done} / ${total}`"></span></div>
                    <div class="mt-1 h-2 overflow-hidden rounded-full bg-slate-100">
                        <div class="h-full rounded-full bg-emerald-500 transition-all" :style="`width: ${total ? done / total * 100 : 0}%`"></div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                            <tr>
                                <th class="h-8 px-4 py-0 font-normal">SKU</th>
                                <th class="h-8 px-4 py-0 font-normal"><span class="whitespace-nowrap">Barcode <x-hint text="Barcode utama, lalu barcode alias (bila ada) yang juga dikenali." /></span></th>
                                <th class="h-8 px-4 py-0 font-normal">Produk / Varian</th>
                                <th class="h-8 px-4 py-0 text-right font-normal"><span class="whitespace-nowrap">Di-scan <x-hint text="Jumlah yang sudah di-scan dibanding jumlah yang dipesan." /></span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <template x-for="line in lines" :key="line.variant_id">
                                <tr :class="scanned[line.variant_id] === line.qty ? 'bg-emerald-50/60' : ''">
                                    <td class="px-4 py-2.5 font-mono font-medium text-slate-900" x-text="line.sku"></td>
                                    <td class="px-4 py-2.5 font-mono text-slate-500">
                                        <span x-text="line.barcode || '—'"></span>
                                        <template x-if="line.aliases.length"><p class="text-xs text-slate-400" x-text="`+ ${line.aliases.join(', ')}`"></p></template>
                                    </td>
                                    <td class="px-4 py-2.5 text-slate-700">
                                        <span x-text="line.name"></span>
                                        <template x-if="line.exempt">
                                            <p class="mt-0.5 inline-flex items-center gap-1 rounded-md bg-slate-100 px-1.5 text-xs text-slate-600"><x-heroicon-o-no-symbol class="size-3.5" /> <span x-text="`Tanpa scan QC${line.exempt_reason ? ': ' + line.exempt_reason : ''}`"></span></p>
                                        </template>
                                    </td>
                                    <td class="px-4 py-2.5 text-right text-base font-semibold tabular-nums"
                                        :class="scanned[line.variant_id] === line.qty ? 'text-emerald-600' : 'text-slate-900'">
                                        <span x-text="scanned[line.variant_id]"></span> / <span x-text="line.qty"></span>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-4 flex flex-wrap justify-end gap-2">
                @unless ($order->isQcHeld())
                    @can('sales.qc_substitute')
                        <button type="button" class="btn-secondary" @click="subOpen = true"><x-heroicon-o-arrows-right-left class="size-5" /> Ganti SKU</button>
                    @endcan
                    <button type="button" class="btn-secondary" @click="holdOpen = true"><x-heroicon-o-pause-circle class="size-5" /> Tahan QC</button>
                @endunless
                <button type="button" class="btn-secondary" @click="reset()" :disabled="held"><x-heroicon-o-arrow-path class="size-5" /> Ulangi Scan</button>
                <button type="submit" class="btn-primary" :disabled="!complete || held"><x-heroicon-o-check class="size-5" /> Selesai QC</button>
            </div>
        </form>

        @if ($order->qcSubstitutions->isNotEmpty())
            <div class="card mt-6">
                <div class="border-b border-slate-100 px-5 py-3">
                    <h2 class="text-base font-semibold text-slate-900">Barang Diganti</h2>
                </div>
                <ul class="divide-y divide-slate-100 text-sm">
                    @foreach ($order->qcSubstitutions as $substitution)
                        <li class="px-5 py-2.5 text-slate-700">
                            <span class="font-mono">{{ $substitution->qty }} × {{ $substitution->fromVariant?->sku }}</span>
                            <x-heroicon-o-arrow-right class="inline size-4 text-slate-400" />
                            <span class="font-mono font-medium text-slate-900">{{ $substitution->toVariant?->sku }}</span>
                            <span class="text-slate-500">· {{ $substitution->reason }} · {{ $substitution->user?->name }} {{ $substitution->created_at->format('d/m H:i') }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Tahan QC --}}
        <div x-show="holdOpen" x-cloak x-transition.opacity class="fixed inset-0 z-[70] bg-black/40"></div>
        <div x-show="holdOpen" x-cloak class="fixed inset-0 z-[80] flex items-center justify-center p-4" @keydown.escape.window="holdOpen = false">
            <form method="POST" action="{{ route('sales.qc.hold', $order) }}" @click.outside="holdOpen = false" class="w-full max-w-md rounded-xl border border-slate-200 bg-white p-5 shadow-xl">
                @csrf
                <h2 class="text-base font-semibold text-slate-900">Tahan QC {{ $order->number }}</h2>
                <p class="mt-1 text-sm text-slate-500">Paket tidak bisa ditutup sampai tahanan dilepas. Pesanan tetap di antrean QC dengan tanda Ditahan.</p>
                <label for="hold-reason" class="form-label mt-4">Alasan <span class="text-rose-500">*</span></label>
                <input id="hold-reason" name="reason" required maxlength="255" class="form-input" placeholder="cth. barang rusak, stok di rak kurang, tunggu konfirmasi pembeli" x-effect="holdOpen && $nextTick(() => $el.focus())">
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="btn-secondary" @click="holdOpen = false">Batal</button>
                    <button type="submit" class="btn-primary"><x-heroicon-o-pause-circle class="size-5" /> Tahan</button>
                </div>
            </form>
        </div>

        {{-- Ganti SKU --}}
        @can('sales.qc_substitute')
            <div x-show="subOpen" x-cloak x-transition.opacity class="fixed inset-0 z-[70] bg-black/40"></div>
            <div x-show="subOpen" x-cloak class="fixed inset-0 z-[80] flex items-center justify-center p-4" @keydown.escape.window="subOpen = false">
                <form method="POST" action="{{ route('sales.qc.substitute', $order) }}" @click.outside="subOpen = false" class="w-full max-w-lg rounded-xl border border-slate-200 bg-white p-5 shadow-xl">
                    @csrf
                    <template x-for="line in lines" :key="line.variant_id">
                        <input type="hidden" :name="`scanned[${line.variant_id}]`" :value="line.exempt ? 0 : scanned[line.variant_id]">
                    </template>
                    <h2 class="text-base font-semibold text-slate-900">Ganti SKU</h2>
                    <p class="mt-1 text-sm text-slate-500">Barang pesanan diganti SKU lain, cth. warna habis dan pembeli setuju. Stok barang pengganti diambil dari bin dengan stok bebas, dan penggantian dicatat beserta alasannya.</p>
                    <div class="mt-4 grid gap-4 sm:grid-cols-3">
                        <div class="sm:col-span-2">
                            <label for="sub-from" class="form-label">Barang yang diganti <span class="text-rose-500">*</span></label>
                            <select id="sub-from" name="from_variant_id" x-model="sub.from" class="form-input" data-native>
                                <template x-for="line in lines" :key="line.variant_id">
                                    <option :value="line.variant_id" x-text="`${line.sku} · ${line.name} (${line.qty})`"></option>
                                </template>
                            </select>
                        </div>
                        <div>
                            <label for="sub-qty" class="form-label">Jumlah <span class="text-rose-500">*</span></label>
                            <input id="sub-qty" type="number" name="qty" min="1" :max="subLine?.qty ?? 1" x-model.number="sub.qty" required class="form-input">
                        </div>
                    </div>
                    <label for="sub-to" class="form-label mt-4">SKU / barcode pengganti <span class="text-rose-500">*</span></label>
                    <input id="sub-to" name="to_code" required maxlength="100" autocomplete="off" class="form-input font-mono" placeholder="Scan barang pengganti" @keydown.enter.prevent="$refs.subReason.focus()">
                    <label for="sub-reason" class="form-label mt-4">Alasan <span class="text-rose-500">*</span></label>
                    <input id="sub-reason" x-ref="subReason" name="reason" required maxlength="255" class="form-input" placeholder="cth. warna merah habis, pembeli setuju diganti biru">
                    <div class="mt-5 flex justify-end gap-2">
                        <button type="button" class="btn-secondary" @click="subOpen = false">Batal</button>
                        <button type="submit" class="btn-primary"><x-heroicon-o-arrows-right-left class="size-5" /> Ganti</button>
                    </div>
                </form>
            </div>
        @endcan
    </div>
</x-layouts.app>
