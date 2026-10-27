<x-layouts.app :title="'QC '.$order->number">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('sales.qc.index') }}" class="hover:text-slate-700">QC Kemas</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">{{ $order->number }}</span>
    </x-slot:breadcrumb>

    <x-page-header :title="'QC '.$order->number" :description="$order->customer_name.' · '.($order->courier?->name ?? 'tanpa kurir').($order->tracking_number ? ' · resi '.$order->tracking_number : '')" icon="heroicon-o-viewfinder-circle">
        @can('sales.view')
            <a href="{{ route('sales.orders.show', $order) }}" class="btn-secondary"><x-heroicon-o-eye class="size-5" /> Detail Pesanan</a>
        @endcan
        <a href="{{ route('sales.qc.index') }}" class="btn-secondary"><x-heroicon-o-arrow-left class="size-5" /> Kembali</a>
    </x-page-header>

    <x-form-errors />

    <form method="POST" action="{{ route('sales.qc.store', $order) }}" x-ref="form"
          x-data="{
              lines: @js($expected['lines']),
              bundles: @js($expected['bundles']),
              scanned: {},
              code: '',
              message: null,
              ok: true,
              init() { this.lines.forEach(l => this.scanned[l.variant_id] = 0); },
              get total() { return this.lines.reduce((s, l) => s + l.qty, 0); },
              get done() { return this.lines.reduce((s, l) => s + Math.min(this.scanned[l.variant_id], l.qty), 0); },
              get complete() { return this.lines.every(l => this.scanned[l.variant_id] === l.qty); },
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
              scan() {
                  const value = this.code.trim(); this.code = '';
                  if (!value) return;
                  const key = value.toLowerCase();
                  if (this.bundles[key]) {
                      const contents = this.bundles[key];
                      const over = Object.entries(contents).find(([id, qty]) => this.scanned[id] + qty > this.lines.find(l => l.variant_id == id).qty);
                      if (over) return this.feedback(false, `Paket ${value} melebihi jumlah pesanan.`);
                      Object.entries(contents).forEach(([id, qty]) => this.scanned[id] += qty);
                      return this.finish(`Paket ${value} ✓`);
                  }
                  const line = this.lines.find(l => (l.barcode && l.barcode.toLowerCase() === key) || l.sku.toLowerCase() === key);
                  if (!line) return this.feedback(false, `${value} tidak ada di pesanan ini. Jangan dimasukkan ke paket.`);
                  if (this.scanned[line.variant_id] >= line.qty) return this.feedback(false, `${line.sku} sudah cukup (${line.qty}). Kelebihan, keluarkan dari paket.`);
                  this.scanned[line.variant_id]++;
                  this.finish(`${line.sku} ${this.scanned[line.variant_id]}/${line.qty}`);
              },
              finish(text) {
                  this.feedback(true, text);
                  if (this.complete) { this.message = 'Semua barang cocok. Menutup paket...'; this.$nextTick(() => this.$refs.form.submit()); }
              },
              reset() { this.lines.forEach(l => this.scanned[l.variant_id] = 0); this.message = null; this.$refs.code.focus(); },
          }">
        @csrf
        <template x-for="line in lines" :key="line.variant_id">
            <input type="hidden" :name="`scanned[${line.variant_id}]`" :value="scanned[line.variant_id]">
        </template>

        <div class="card mb-6 p-5">
            <label for="scan-code" class="form-label">Scan Barcode / QR <span class="whitespace-nowrap">SKU <x-hint text="Scan barcode atau SKU setiap barang yang dimasukkan ke paket. Paket ditandai Sudah Dikemas bila jumlah semua barang cocok dengan pesanan." /></span></label>
            <div class="relative">
                <x-heroicon-o-qr-code class="pointer-events-none absolute top-1/2 left-3 size-5 -translate-y-1/2 text-slate-400" />
                <input id="scan-code" x-ref="code" x-model="code" @keydown.enter.prevent="scan()" data-auto-scan="enter" autofocus autocomplete="off" data-native
                       class="form-input h-12 pl-10 font-mono text-lg" placeholder="Scan barang satu per satu...">
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
                            <th class="h-8 px-4 py-0 font-normal">Barcode</th>
                            <th class="h-8 px-4 py-0 font-normal">Produk / Varian</th>
                            <th class="h-8 px-4 py-0 text-right font-normal"><span class="whitespace-nowrap">Di-scan <x-hint text="Jumlah yang sudah di-scan dibanding jumlah yang dipesan." /></span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <template x-for="line in lines" :key="line.variant_id">
                            <tr :class="scanned[line.variant_id] === line.qty ? 'bg-emerald-50/60' : ''">
                                <td class="px-4 py-2.5 font-mono font-medium text-slate-900" x-text="line.sku"></td>
                                <td class="px-4 py-2.5 font-mono text-slate-500" x-text="line.barcode || '—'"></td>
                                <td class="px-4 py-2.5 text-slate-700" x-text="line.name"></td>
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

        <div class="mt-4 flex justify-end gap-2">
            <button type="button" class="btn-secondary" @click="reset()"><x-heroicon-o-arrow-path class="size-5" /> Ulangi Scan</button>
            <button type="submit" class="btn-primary" :disabled="!complete"><x-heroicon-o-check class="size-5" /> Selesai QC</button>
        </div>
    </form>
</x-layouts.app>
