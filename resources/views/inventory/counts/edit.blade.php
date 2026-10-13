@php
    // Setelah gagal validasi, baris dipulihkan dari payload yang dikirim supaya
    // ketikan tidak hilang dan pesan error tetap menempel di baris yang sama.
    $oldPayload = json_decode((string) old('payload'), true);
    $rows = is_array($oldPayload) ? $oldPayload : $lines;
    $lineErrors = collect($errors->getMessages())->filter(fn ($messages, $key) => str_starts_with($key, 'lines.'))->map(fn ($messages) => $messages[0]);
    $otherErrors = collect($errors->getMessages())->reject(fn ($messages, $key) => str_starts_with($key, 'lines.'))->flatten();
@endphp

<x-layouts.app :title="$count->number">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('inventory.counts.index') }}" class="hover:text-slate-700">Stock Opname</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">{{ $count->number }}</span>
    </x-slot:breadcrumb>

    <div x-data="{
             lines: @js($rows),
             variants: @js($variants),
             scopeLocations: @js($scopeLocations),
             bins: @js($bins),
             allowUnbinned: @js($allowUnbinned),
             errors: @js($lineErrors),
             action: 'save',
             search: '',
             only: '',
             scan: '',
             scanLocation: '',
             scanMessage: '',
             lastScanned: null,
             dirty: @js(is_array($oldPayload)),
             init() {
                 window.addEventListener('beforeunload', (event) => { if (this.dirty) event.preventDefault(); });
             },
             qty(value) {
                 return value === '' || value === null || value === undefined ? null : parseInt(value);
             },
             variantOf(line) {
                 return this.variants.find(v => String(v.id) === String(line.product_variant_id)) ?? {};
             },
             sku(line) { return line.sku ?? this.variantOf(line).sku ?? ''; },
             label(line) { return line.label ?? this.variantOf(line).label ?? ''; },
             unit(line) { return line.unit ?? this.variantOf(line).unit ?? ''; },
             locationCode(line) {
                 if (line.location_code !== undefined) return line.location_code;
                 return this.scopeLocations.find(l => String(l.id) === String(line.warehouse_location_id))?.code ?? null;
             },
             variance(line) {
                 const counted = this.qty(line.counted_qty);
                 return counted === null || line.id === null ? null : counted - line.current_qty;
             },
             visible(line) {
                 const term = this.search.trim().toLowerCase();
                 if (term && ! `${this.sku(line)} ${line.barcode ?? ''} ${this.label(line)} ${this.locationCode(line) ?? 'tanpa bin'}`.toLowerCase().includes(term)) return false;
                 if (this.only === 'uncounted') return this.qty(line.counted_qty) === null;
                 if (this.only === 'variance') return (this.variance(line) ?? 0) !== 0;
                 return true;
             },
             get summary() {
                 const counted = this.lines.filter(l => this.qty(l.counted_qty) !== null);
                 const variances = this.lines.map(l => this.variance(l) ?? 0);
                 return {
                     total: this.lines.length,
                     counted: counted.length,
                     uncounted: this.lines.length - counted.length,
                     withVariance: variances.filter(v => v !== 0).length,
                     plus: variances.filter(v => v > 0).reduce((a, b) => a + b, 0),
                     minus: variances.filter(v => v < 0).reduce((a, b) => a + b, 0),
                 };
             },
             get variantOptions() { return this.variants.map(v => ({ value: v.id, text: `${v.sku} · ${v.label}` })); },
             get locationOptions() { return this.scopeLocations.map(l => ({ value: l.id, text: l.code })); },
             get binOptions() { return this.bins.map(l => ({ value: l.id, text: l.code })); },
             payload() {
                 return JSON.stringify(this.lines.map(l => ({
                     ...l,
                     counted_qty: this.qty(l.counted_qty),
                     warehouse_location_id: l.warehouse_location_id === '' ? null : l.warehouse_location_id,
                     target_location_id: l.target_location_id === '' ? null : l.target_location_id,
                 })));
             },
             blank(locationId = null) {
                 return { id: null, product_variant_id: '', warehouse_location_id: locationId, counted_qty: '', target_location_id: null, notes: '' };
             },
             addLine() {
                 const location = this.scanLocation && this.scanLocation !== 'none' ? this.scanLocation : (this.allowUnbinned ? null : '');
                 this.lines.push(this.blank(location));
                 this.dirty = true;
             },
             removeLine(index) {
                 this.lines.splice(index, 1);
                 this.errors = {};
                 this.dirty = true;
             },
             fillSystem() {
                 this.lines.forEach(l => { if (l.id !== null && this.qty(l.counted_qty) === null) l.counted_qty = Math.max(l.current_qty, 0); });
                 this.dirty = true;
             },
             scanned() {
                 const code = this.scan.trim().toUpperCase();
                 if (! code) return;
                 this.scan = '';
                 const variant = this.variants.find(v => v.sku === code || (v.barcode && v.barcode.toUpperCase() === code))
                     ?? this.lines.find(l => l.sku === code || (l.barcode && l.barcode.toUpperCase() === code));
                 const variantId = variant ? String(variant.product_variant_id ?? variant.id) : null;
                 if (! variantId) { this.scanMessage = `SKU / barcode ${code} tidak ditemukan.`; return; }

                 const matches = this.lines
                     .map((line, index) => ({ line, index }))
                     .filter(({ line }) => String(line.product_variant_id) === variantId)
                     .filter(({ line }) => this.scanLocation === '' || String(line.warehouse_location_id ?? 'none') === this.scanLocation);

                 if (matches.length > 1) {
                     this.scanMessage = `${code} ada di beberapa lokasi (${matches.map(m => this.locationCode(m.line) ?? 'Tanpa bin').join(', ')}). Pilih lokasi yang sedang dihitung.`;
                     return;
                 }

                 let index;
                 if (matches.length === 1) {
                     index = matches[0].index;
                     this.lines[index].counted_qty = (this.qty(this.lines[index].counted_qty) ?? 0) + 1;
                 } else if (this.scanLocation === '' && ! (this.allowUnbinned && this.scopeLocations.length === 0)) {
                     this.scanMessage = `${code} belum ada di lembar ini. Pilih lokasi yang sedang dihitung untuk menambahkannya.`;
                     return;
                 } else {
                     const location = this.scanLocation === '' || this.scanLocation === 'none' ? null : this.scanLocation;
                     this.lines.push({ ...this.blank(location), product_variant_id: variantId, counted_qty: 1 });
                     index = this.lines.length - 1;
                 }

                 this.scanMessage = '';
                 this.lastScanned = index;
                 this.dirty = true;
                 this.$nextTick(() => document.getElementById(`line-${index}`)?.scrollIntoView({ block: 'center', behavior: 'smooth' }));
             },
             confirmPost() {
                 const s = this.summary;
                 if (s.counted === 0) { this.scanMessage = 'Belum ada baris yang dihitung.'; return; }
                 this.action = 'post';
                 this.dirty = false;
                 this.$dispatch('confirm', {
                     form: 'count-form',
                     title: 'Posting opname {{ $count->number }}?',
                     message: `Stok ${s.counted} baris disetel ke hasil hitung (selisih +${s.plus} / ${s.minus}).` + (s.uncounted ? ` ${s.uncounted} baris belum dihitung dan tidak mengubah stok.` : '') + ' Lembar terkunci setelah diposting.',
                     confirmText: 'Posting',
                     variant: 'primary',
                 });
             },
         }"
         @input="dirty = true">

        <x-page-header :title="$count->number" :description="'Opname '.$count->warehouse->code.' · '.$count->scopeLabel().' · '.$count->count_date->translatedFormat('d F Y')" icon="heroicon-o-clipboard-document-check">
            <a href="{{ route('inventory.counts.index') }}" class="btn-secondary"><x-heroicon-o-arrow-left class="size-5" /> Kembali</a>
            <a href="{{ route('inventory.counts.sheet', $count) }}" target="_blank" class="btn-secondary"><x-heroicon-o-printer class="size-5" /> Cetak Lembar</a>
            @can('opname.delete')
                <form id="delete-count" method="POST" action="{{ route('inventory.counts.destroy', $count) }}">@csrf @method('DELETE')</form>
                <button type="button" class="btn-secondary hover:text-rose-600"
                        @click="dirty = false; $dispatch('confirm', { form: 'delete-count', title: 'Hapus lembar opname?', message: @js("Lembar {$count->number} dan semua hasil hitungnya dihapus. Stok tidak berubah."), confirmText: 'Hapus' })">
                    <x-heroicon-o-trash class="size-5" /> Hapus
                </button>
            @endcan
        </x-page-header>

        @if ($otherErrors->isNotEmpty() || $lineErrors->isNotEmpty())
            <div class="mb-6 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                @foreach ($otherErrors as $message)
                    <p class="flex items-center gap-1.5"><x-heroicon-o-exclamation-triangle class="size-4 shrink-0" /> {{ $message }}</p>
                @endforeach
                @if ($lineErrors->isNotEmpty())
                    <p class="flex items-center gap-1.5"><x-heroicon-o-exclamation-triangle class="size-4 shrink-0" /> Ada {{ $lineErrors->count() }} isian yang perlu diperbaiki, lihat baris bertanda merah.</p>
                @endif
            </div>
        @endif

        <div class="card mb-6 grid gap-5 p-5 sm:grid-cols-3 lg:grid-cols-6">
            <div>
                <p class="text-xs text-slate-500">Status</p>
                <p class="mt-1"><x-inventory.count-status :status="$count->status" /></p>
            </div>
            <div>
                <p class="text-xs text-slate-500">Dihitung</p>
                <p class="mt-1 text-sm font-semibold tabular-nums text-slate-900"><span x-text="summary.counted"></span> / <span x-text="summary.total"></span> baris</p>
            </div>
            <div>
                <p class="text-xs text-slate-500">Belum Dihitung</p>
                <p class="mt-1 text-sm font-semibold tabular-nums" :class="summary.uncounted ? 'text-amber-600' : 'text-slate-900'" x-text="summary.uncounted"></p>
            </div>
            <div>
                <p class="text-xs text-slate-500">Baris Selisih</p>
                <p class="mt-1 text-sm font-semibold tabular-nums text-slate-900" x-text="summary.withVariance"></p>
            </div>
            <div>
                <p class="text-xs text-slate-500">Selisih Lebih / Kurang</p>
                <p class="mt-1 text-sm font-semibold tabular-nums">
                    <span class="text-emerald-600" x-text="'+' + summary.plus"></span> /
                    <span class="text-rose-600" x-text="summary.minus"></span>
                </p>
            </div>
            <div>
                <p class="text-xs text-slate-500">Dibuat oleh</p>
                <p class="mt-1 text-sm text-slate-700">{{ $count->creator?->name ?? '—' }}</p>
            </div>
            @if ($count->notes)
                <div class="sm:col-span-3 lg:col-span-6">
                    <p class="text-xs text-slate-500">Catatan</p>
                    <p class="mt-1 text-sm whitespace-pre-line text-slate-700">{{ $count->notes }}</p>
                </div>
            @endif
        </div>

        <form id="count-form" method="POST" action="{{ route('inventory.counts.update', $count) }}" @submit="dirty = false">
            @csrf
            @method('PUT')
            <input type="hidden" name="action" :value="action">
            <input type="hidden" name="payload" :value="payload()">

            <div class="card">
                <div class="flex flex-col gap-3 border-b border-slate-100 p-4 lg:flex-row lg:items-start">
                    <div class="flex flex-1 flex-col gap-2 sm:flex-row">
                        <div class="sm:w-48">
                            <select class="form-input" x-model="scanLocation" data-placeholder="Semua lokasi"
                                    x-tom-select="{ options: [{ value: '', text: 'Semua lokasi' }, ...(allowUnbinned ? [{ value: 'none', text: 'Tanpa bin' }] : []), ...locationOptions], value: scanLocation }"></select>
                        </div>
                        <div class="flex-1">
                            <div class="relative">
                                <x-heroicon-o-qr-code class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-slate-400" />
                                <input type="text" class="form-input pl-8" placeholder="Scan SKU / barcode lalu Enter (tiap scan +1)" x-model="scan" @keydown.enter.prevent="scanned()" @input.stop>
                            </div>
                            <p class="mt-1 text-xs text-rose-600" x-show="scanMessage" x-text="scanMessage" x-cloak></p>
                        </div>
                    </div>
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <div class="relative sm:w-56">
                            <x-heroicon-o-magnifying-glass class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-slate-400" />
                            <input type="search" class="form-input pl-8" placeholder="Saring SKU, nama, lokasi..." x-model="search" @input.stop @keydown.enter.prevent>
                        </div>
                        <div class="inline-flex shrink-0 rounded-lg border border-slate-200 p-0.5 text-sm">
                            @foreach (['' => 'Semua', 'uncounted' => 'Belum dihitung', 'variance' => 'Ada selisih'] as $value => $label)
                                <button type="button" class="rounded-md px-2.5 py-1 font-medium whitespace-nowrap transition"
                                        :class="only === @js($value) ? 'bg-brand-50 text-brand-700' : 'text-slate-600 hover:text-slate-900'"
                                        @click="only = @js($value)">{{ $label }}</button>
                            @endforeach
                        </div>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                            <tr>
                                <th class="h-8 px-3 py-0 font-normal">#</th>
                                <th class="h-8 px-3 py-0 font-normal">Lokasi</th>
                                <th class="h-8 px-3 py-0 font-normal">Barang</th>
                                <th class="h-8 px-3 py-0 text-right font-normal">Sistem</th>
                                <th class="h-8 px-3 py-0 text-right font-normal">Hasil Hitung</th>
                                <th class="h-8 px-3 py-0 text-right font-normal">Selisih</th>
                                <th class="h-8 px-3 py-0 font-normal" title="Khusus stok tanpa bin: hasil hitung langsung ditaruh ke bin ini saat posting">Taruh ke Bin</th>
                                <th class="h-8 px-3 py-0 font-normal">Catatan</th>
                                <th class="h-8 px-3 py-0"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <template x-for="(line, index) in lines" :key="line.id ?? `new-${index}`">
                                <tr :id="`line-${index}`" x-show="visible(line)" class="align-top transition-colors"
                                    :class="lastScanned === index ? 'bg-brand-50/60' : (line.id === null ? 'bg-sky-50/40' : '')">
                                    <td class="px-3 py-2 pt-3.5 text-slate-400" x-text="index + 1"></td>
                                    <td class="px-3 py-2">
                                        <template x-if="line.id !== null">
                                            <span class="inline-block pt-1.5" :class="line.warehouse_location_id ? 'font-mono text-slate-700' : 'text-slate-400'" x-text="locationCode(line) ?? 'Tanpa bin'"></span>
                                        </template>
                                        <template x-if="line.id === null">
                                            <div>
                                                <select class="form-input min-w-32" x-model="line.warehouse_location_id" data-placeholder="{{ $allowUnbinned ? 'Tanpa bin' : 'Pilih lokasi' }}"
                                                        :class="errors[`lines.${index}.warehouse_location_id`] && 'form-input-error'"
                                                        x-tom-select="{ options: [...(allowUnbinned ? [{ value: '', text: 'Tanpa bin' }] : []), ...locationOptions], value: line.warehouse_location_id ?? '' }"></select>
                                                <p class="mt-1 max-w-40 text-xs text-rose-600" x-show="errors[`lines.${index}.warehouse_location_id`]" x-text="errors[`lines.${index}.warehouse_location_id`]"></p>
                                            </div>
                                        </template>
                                    </td>
                                    <td class="px-3 py-2">
                                        <template x-if="line.id !== null">
                                            <div class="pt-1">
                                                <p class="font-mono font-medium text-slate-900" x-text="sku(line)"></p>
                                                <p class="text-xs text-slate-500" x-text="label(line)"></p>
                                            </div>
                                        </template>
                                        <template x-if="line.id === null">
                                            <div>
                                                <select class="form-input min-w-64" x-model="line.product_variant_id" data-placeholder="Cari SKU / nama barang"
                                                        :class="errors[`lines.${index}.product_variant_id`] && 'form-input-error'"
                                                        x-tom-select="{ options: variantOptions, value: line.product_variant_id }"></select>
                                                <p class="mt-1 text-xs text-sky-700">Barang ditemukan, belum ada di lembar.</p>
                                            </div>
                                        </template>
                                        <p class="mt-1 max-w-64 text-xs text-rose-600" x-show="errors[`lines.${index}.product_variant_id`] || errors[`lines.${index}.id`]" x-text="errors[`lines.${index}.product_variant_id`] ?? errors[`lines.${index}.id`]"></p>
                                    </td>
                                    <td class="px-3 py-2 pt-3.5 text-right tabular-nums text-slate-600">
                                        <template x-if="line.id !== null">
                                            <div>
                                                <span x-text="line.current_qty.toLocaleString('id-ID')"></span>
                                                <span class="text-xs text-slate-400" x-text="unit(line)"></span>
                                                <p class="text-xs text-amber-600" x-show="line.current_qty !== line.system_qty" x-text="`awal ${line.system_qty}`"
                                                   title="Saldo berubah sejak lembar dibuat (ada transaksi selama opname). Posting memakai saldo terbaru."></p>
                                            </div>
                                        </template>
                                        <template x-if="line.id === null"><span class="text-slate-400">—</span></template>
                                    </td>
                                    <td class="px-3 py-2 text-right">
                                        <input type="number" min="0" step="1" class="form-input ml-auto w-24 text-right" placeholder="—" x-model="line.counted_qty"
                                               :class="errors[`lines.${index}.counted_qty`] && 'form-input-error'"
                                               @keydown.enter.prevent="document.querySelectorAll('[data-count-input]')[[...document.querySelectorAll('[data-count-input]')].indexOf($el) + 1]?.focus()"
                                               data-count-input>
                                        <p class="mt-1 text-xs text-rose-600" x-show="errors[`lines.${index}.counted_qty`]" x-text="errors[`lines.${index}.counted_qty`]"></p>
                                    </td>
                                    <td class="px-3 py-2 pt-3.5 text-right font-medium tabular-nums"
                                        :class="variance(line) === null ? 'text-slate-400' : (variance(line) < 0 ? 'text-rose-600' : (variance(line) > 0 ? 'text-emerald-600' : 'text-slate-500'))"
                                        x-text="variance(line) === null ? '—' : (variance(line) > 0 ? '+' + variance(line) : variance(line))"></td>
                                    <td class="px-3 py-2">
                                        <template x-if="! line.warehouse_location_id">
                                            <div>
                                                <select class="form-input min-w-36" x-model="line.target_location_id" data-placeholder="Tetap tanpa bin"
                                                        :class="errors[`lines.${index}.target_location_id`] && 'form-input-error'"
                                                        x-tom-select="{ options: [{ value: '', text: 'Tetap tanpa bin' }, ...binOptions], value: line.target_location_id ?? '' }"></select>
                                                <p class="mt-1 max-w-40 text-xs text-rose-600" x-show="errors[`lines.${index}.target_location_id`]" x-text="errors[`lines.${index}.target_location_id`]"></p>
                                            </div>
                                        </template>
                                        <template x-if="line.warehouse_location_id"><span class="inline-block pt-1.5 text-slate-300">—</span></template>
                                    </td>
                                    <td class="px-3 py-2">
                                        <input type="text" class="form-input min-w-36" maxlength="255" x-model="line.notes" @keydown.enter.prevent>
                                    </td>
                                    <td class="px-3 py-2">
                                        <button type="button" class="btn-icon hover:bg-rose-50 hover:text-rose-600" title="Hapus baris dari lembar" @click="removeLine(index)">
                                            <x-heroicon-o-trash class="size-5" />
                                        </button>
                                    </td>
                                </tr>
                            </template>
                            <tr x-show="lines.length === 0" x-cloak>
                                <td colspan="9" class="px-5 py-12 text-center">
                                    <x-heroicon-o-clipboard-document-list class="mx-auto size-10 text-slate-300" />
                                    <p class="mt-2 font-medium text-slate-700">Tidak ada stok di cakupan ini</p>
                                    <p class="text-sm text-slate-500">Scan atau tambah baris untuk barang yang ditemukan saat dihitung.</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-wrap items-center gap-2 border-t border-slate-100 p-4">
                    <button type="button" class="btn-secondary" @click="addLine()"><x-heroicon-o-plus class="size-5" /> Tambah Baris</button>
                    <button type="button" class="btn-secondary" x-show="summary.uncounted > 0" @click="fillSystem()"
                            title="Baris yang belum diisi dianggap sama dengan saldo sistem">
                        <x-heroicon-o-document-duplicate class="size-5" /> Isi yang kosong = sistem
                    </button>
                    <p class="text-xs text-slate-500 sm:ml-auto">Baris kosong tidak mengubah stok. Stok tanpa bin bisa langsung ditaruh ke bin lewat kolom <span class="font-medium">Taruh ke Bin</span>.</p>
                </div>
            </div>

            <div class="sticky bottom-0 mt-6 flex items-center justify-between gap-3 border-t border-slate-200 bg-white/90 py-3 backdrop-blur">
                <p class="hidden items-center gap-1.5 text-xs text-slate-500 sm:flex">
                    <x-heroicon-o-information-circle class="size-4" /> Posting menyetel stok ke hasil hitung terhadap saldo terbaru, lewat satu mutasi Penyesuaian.
                </p>
                <div class="flex gap-2">
                    <button type="submit" class="btn-secondary" @click="action = 'save'"><x-heroicon-o-document-check class="size-5" /> Simpan</button>
                    @can('opname.post')
                        <button type="button" class="btn-primary" @click="confirmPost()"><x-heroicon-o-check-badge class="size-5" /> Posting</button>
                    @endcan
                </div>
            </div>
        </form>
    </div>
</x-layouts.app>
