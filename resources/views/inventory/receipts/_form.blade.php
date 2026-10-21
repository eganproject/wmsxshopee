@php
    $blank = ['product_variant_id' => '', 'warehouse_location_id' => '', 'qty_ordered' => '', 'qty_received' => '', 'qty_damaged' => '', 'notes' => ''];
    $existing = $receipt->relationLoaded('items')
        ? $receipt->items->map(fn ($item) => [
            'product_variant_id' => (string) $item->product_variant_id,
            'warehouse_location_id' => (string) ($item->warehouse_location_id ?? ''),
            'qty_ordered' => $item->qty_ordered ?? '',
            'qty_received' => $item->qty_received,
            'qty_damaged' => $item->qty_damaged ?: '',
            'notes' => $item->notes ?? '',
        ])
        : collect();
    $items = collect(old('items', $existing->all()))->map(fn ($row) => [...$blank, ...$row])->values();
    $rowErrors = collect($errors->getMessages())->filter(fn ($messages, $key) => str_starts_with($key, 'items.'))->map(fn ($messages) => $messages[0]);
    $defaultWarehouse = $receipt->warehouse_id ?? ($warehouses->count() === 1 ? $warehouses->first()->id : '');
@endphp

<div class="space-y-6"
     x-data="{
         warehouse: @js((string) old('warehouse_id', $defaultWarehouse)),
         items: @js($items->isEmpty() ? [$blank] : $items),
         variants: @js($variants),
         locations: @js($locations),
         defaults: @js($defaults),
         errors: @js($rowErrors),
         scan: '',
         scanError: '',
         splitNote: '',
         blank() {
             // Baris baru ikut lokasi bin baris terakhir supaya tidak perlu pilih ulang.
             const last = this.items[this.items.length - 1];
             return { ...@js($blank), warehouse_location_id: last ? last.warehouse_location_id : '' };
         },
         fixedBins(item) {
             return window.Optima.fixedBinsFor(this.defaults, this.warehouse, item.product_variant_id);
         },
         fillDefault(item) {
             // Bin utama lokasi tetap SKU di gudang ini (Inventori > Lokasi Tetap SKU) menggantikan bin baris sebelumnya.
             const bins = this.fixedBins(item);
             if (bins.length) item.warehouse_location_id = bins[0].id;
             this.spread(item);
         },
         spread(item) {
             // Bin utama penuh (sesuai kapasitas): kelebihan diterima dipindah ke bin cadangan.
             if (! item.product_variant_id) return;
             const bins = this.fixedBins(item);
             if (bins.length < 2) return;
             const changed = window.Optima.spreadToFixedBins(this.items, item, {
                 bins,
                 qtyKey: 'qty_received',
                 locKey: 'warehouse_location_id',
                 same: r => String(r.product_variant_id) === String(item.product_variant_id),
                 make: (loc, qty) => ({ ...@js($blank), product_variant_id: String(item.product_variant_id), warehouse_location_id: loc, qty_received: qty }),
                 moved: (from, to, qty) => {
                     // Jumlah dipesan ikut dipindah supaya selisih per baris tetap benar.
                     if (from.qty_ordered === '' || from.qty_ordered === null) return;
                     const take = Math.min(qty, parseInt(from.qty_ordered) || 0);
                     from.qty_ordered = (parseInt(from.qty_ordered) || 0) - take;
                     to.qty_ordered = (parseInt(to.qty_ordered) || 0) + take;
                 },
             });
             if (changed) this.splitNote = 'Bin utama penuh, sisa barang disarankan ke bin cadangan (lihat Inventori > Lokasi Tetap SKU).';
         },
         locationsFor() {
             return this.locations.filter(l => String(l.warehouse_id) === String(this.warehouse));
         },
         get variantOptions() {
             return this.variants.map(v => ({ value: v.id, text: v.label }));
         },
         get locationOptions() {
             return this.locationsFor().map(l => ({ value: l.id, text: l.code }));
         },
         totals() {
             const sum = key => this.items.reduce((total, item) => total + (parseInt(item[key]) || 0), 0);
             return { ordered: sum('qty_ordered'), received: sum('qty_received'), damaged: sum('qty_damaged') };
         },
         variance(item) {
             if (item.qty_ordered === '' || item.qty_ordered === null) return null;
             return (parseInt(item.qty_received) || 0) + (parseInt(item.qty_damaged) || 0) - (parseInt(item.qty_ordered) || 0);
         },
         addScanned() {
             const code = this.scan.trim().toUpperCase();
             if (! code) return;
             const variant = this.variants.find(v => v.sku === code || (v.barcode && v.barcode.toUpperCase() === code));
             if (! variant) { this.scanError = `SKU / barcode ${code} tidak ditemukan.`; return; }
             this.scanError = '';
             this.scan = '';
             // Scan barang yang sudah ada di daftar menambah jumlah diterima 1 (baris terakhir
             // barang itu, yaitu bin cadangan bila bin utama sudah penuh).
             const existing = this.items.filter(i => String(i.product_variant_id) === String(variant.id)).pop();
             if (existing) { existing.qty_received = (parseInt(existing.qty_received) || 0) + 1; this.spread(existing); return; }
             const empty = this.items.find(i => ! i.product_variant_id);
             if (empty) { empty.product_variant_id = String(variant.id); empty.qty_received = empty.qty_received || 1; this.fillDefault(empty); return; }
             const row = { ...this.blank(), product_variant_id: String(variant.id), qty_received: 1 };
             this.fillDefault(row);
             this.items.push(row);
         },
     }">
    <div class="card">
        <div class="border-b border-slate-100 px-5 py-3">
            <h2 class="flex items-center gap-2 text-base font-semibold text-slate-900">
                <x-heroicon-o-document-text class="size-5 text-slate-400" /> Informasi Penerimaan
            </h2>
            <p class="mt-0.5 text-sm text-slate-500">
                @if ($receipt->number)
                    Nomor <span class="font-mono font-medium text-slate-700">{{ $receipt->number }}</span>
                @else
                    Nomor dibuat otomatis saat disimpan, cth. {{ \App\Models\GoodsReceipt::PREFIX.now()->format('ym') }}-001.
                @endif
            </p>
        </div>
        <div class="grid gap-x-6 gap-y-4 p-5 sm:grid-cols-3">
            <x-select name="supplier_id" label="Supplier" required class="sm:col-span-2">
                <option value="">Pilih supplier</option>
                @foreach ($suppliers as $supplier)
                    <option value="{{ $supplier->id }}" @selected((string) old('supplier_id', $receipt->supplier_id) === (string) $supplier->id)>
                        {{ $supplier->code }} · {{ $supplier->name }}
                    </option>
                @endforeach
            </x-select>
            <x-input name="receipt_date" type="date" label="Tanggal Terima" :value="$receipt->receipt_date?->toDateString()" :max="now()->toDateString()" required />
            <x-select name="warehouse_id" label="Gudang Tujuan" required x-model="warehouse">
                <option value="">Pilih gudang</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}">{{ $warehouse->code }} · {{ $warehouse->name }}</option>
                @endforeach
            </x-select>
            <x-input name="supplier_reference" label="No. PO / Surat Jalan" :value="$receipt->supplier_reference" placeholder="cth. SJ-0912" />
            <div>
                <label for="attachment" class="form-label">Foto Surat Jalan</label>
                <input id="attachment" name="attachment" type="file" accept="image/jpeg,image/png,image/webp,application/pdf"
                       @class(['form-input file:mr-3 file:border-0 file:bg-transparent file:text-sm file:font-medium', 'form-input-error' => $errors->has('attachment')])>
                @error('attachment')
                    <p class="mt-1 flex items-center gap-1 text-xs text-rose-600"><x-heroicon-o-exclamation-triangle class="size-4 shrink-0" /> {{ $message }}</p>
                @else
                    @if ($receipt->attachment_path)
                        <label class="mt-1 flex items-center gap-1.5 text-xs text-slate-500">
                            <a href="{{ Storage::url($receipt->attachment_path) }}" target="_blank" class="text-brand-600 hover:underline">Lihat lampiran</a>
                            · <input type="checkbox" name="remove_attachment" value="1" class="rounded border-slate-300"> hapus
                        </label>
                    @else
                        <p class="mt-1 text-xs text-slate-400">JPG, PNG, WEBP atau PDF, maks. 5 MB.</p>
                    @endif
                @enderror
            </div>
            <x-textarea name="notes" label="Catatan" :value="$receipt->notes" rows="2" class="sm:col-span-3" />
        </div>
    </div>

    <div class="card">
        <div class="flex flex-col gap-3 border-b border-slate-100 px-5 py-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="flex items-center gap-2 text-base font-semibold text-slate-900">
                    <x-heroicon-o-cube class="size-5 text-slate-400" /> Barang Diterima
                </h2>
                <p class="mt-0.5 text-sm text-slate-500">Scan SKU / barcode: barang baru menambah baris, barang yang sama menambah jumlah diterima.</p>
            </div>
            <div class="sm:w-72">
                <div class="relative">
                    <x-heroicon-o-qr-code class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-slate-400" />
                    <input type="text" class="form-input pl-8" placeholder="Scan SKU / barcode lalu Enter" x-model="scan" @keydown.enter.prevent="addScanned()">
                </div>
                <p class="mt-1 text-xs text-rose-600" x-show="scanError" x-text="scanError" x-cloak></p>
            </div>
        </div>

        @error('items')
            <p class="mx-5 mt-4 flex items-center gap-1 text-sm text-rose-600"><x-heroicon-o-exclamation-triangle class="size-4" /> {{ $message }}</p>
        @enderror

        <p class="mx-5 mt-4 rounded-lg bg-sky-50 px-3 py-2 text-sm text-sky-700" x-show="splitNote" x-text="splitNote" x-cloak></p>

        <p class="mx-5 mt-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-700" x-show="! warehouse" x-cloak>
            Pilih gudang tujuan dulu agar daftar lokasi bin muncul. Lokasi bin boleh dikosongkan.
        </p>

        <div class="overflow-x-auto p-5 pt-4">
            <table class="min-w-full text-sm">
                <thead class="text-left text-[13px] text-slate-500">
                    <tr>
                        <th class="py-2 pr-2 font-normal">Barang *</th>
                        <th class="px-2 py-2 text-right font-normal">Dipesan</th>
                        <th class="px-2 py-2 text-right font-normal">Diterima Baik *</th>
                        <th class="px-2 py-2 text-right font-normal">Rusak</th>
                        <th class="px-2 py-2 text-right font-normal">Selisih</th>
                        <th class="px-2 py-2 font-normal">Lokasi Bin</th>
                        <th class="px-2 py-2 font-normal">Keterangan</th>
                        <th class="py-2 pl-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(item, index) in items" :key="index">
                        <tr class="align-top">
                            <td class="py-1.5 pr-2">
                                <select class="form-input min-w-64" :name="`items[${index}][product_variant_id]`" x-model="item.product_variant_id" @change="$nextTick(() => fillDefault(item))"
                                        x-tom-select="{ options: variantOptions, value: item.product_variant_id }" data-placeholder="Cari SKU / nama barang"
                                        :class="errors[`items.${index}.product_variant_id`] && 'form-input-error'"></select>
                                <p class="mt-1 text-xs text-rose-600" x-show="errors[`items.${index}.product_variant_id`]" x-text="errors[`items.${index}.product_variant_id`]"></p>
                            </td>
                            <td class="px-2 py-1.5">
                                <input type="number" min="0" step="1" class="form-input w-24 text-right" :name="`items[${index}][qty_ordered]`" x-model="item.qty_ordered" placeholder="—"
                                       :class="errors[`items.${index}.qty_ordered`] && 'form-input-error'">
                            </td>
                            <td class="px-2 py-1.5">
                                <input type="number" min="0" step="1" class="form-input w-24 text-right" :name="`items[${index}][qty_received]`" x-model="item.qty_received" @change="spread(item)"
                                       :class="errors[`items.${index}.qty_received`] && 'form-input-error'">
                                <p class="mt-1 max-w-40 text-xs text-rose-600" x-show="errors[`items.${index}.qty_received`]" x-text="errors[`items.${index}.qty_received`]"></p>
                            </td>
                            <td class="px-2 py-1.5">
                                <input type="number" min="0" step="1" class="form-input w-20 text-right" :name="`items[${index}][qty_damaged]`" x-model="item.qty_damaged" placeholder="0"
                                       :class="errors[`items.${index}.qty_damaged`] && 'form-input-error'">
                            </td>
                            <td class="px-2 py-1.5 text-right">
                                <span class="inline-block pt-1.5 font-medium tabular-nums"
                                      :class="variance(item) === null ? 'text-slate-400' : (variance(item) < 0 ? 'text-rose-600' : (variance(item) > 0 ? 'text-amber-600' : 'text-emerald-600'))"
                                      x-text="variance(item) === null ? '—' : (variance(item) > 0 ? '+' + variance(item) : variance(item))"></span>
                            </td>
                            <td class="px-2 py-1.5">
                                <select class="form-input min-w-36" :name="`items[${index}][warehouse_location_id]`" x-model="item.warehouse_location_id"
                                        x-tom-select="{ options: locationOptions, value: item.warehouse_location_id }" data-placeholder="Tanpa bin"
                                        :class="errors[`items.${index}.warehouse_location_id`] && 'form-input-error'">
                                    <option value="">Tanpa bin</option>
                                </select>
                                <p class="mt-1 max-w-48 text-xs text-rose-600" x-show="errors[`items.${index}.warehouse_location_id`]" x-text="errors[`items.${index}.warehouse_location_id`]"></p>
                            </td>
                            <td class="px-2 py-1.5">
                                <input type="text" class="form-input min-w-40" maxlength="255" :name="`items[${index}][notes]`" x-model="item.notes">
                            </td>
                            <td class="py-1.5 pl-2">
                                <button type="button" class="btn-icon hover:bg-rose-50 hover:text-rose-600" title="Hapus baris"
                                        x-show="items.length > 1" @click="items.splice(index, 1); errors = {}">
                                    <x-heroicon-o-trash class="size-5" />
                                </button>
                            </td>
                        </tr>
                    </template>
                </tbody>
                <tfoot class="border-t border-slate-100 text-[13px] text-slate-500">
                    <tr>
                        <td class="py-2 pr-2 text-right">Total</td>
                        <td class="px-2 py-2 text-right font-medium tabular-nums text-slate-700" x-text="totals().ordered"></td>
                        <td class="px-2 py-2 text-right font-medium tabular-nums text-slate-700" x-text="totals().received"></td>
                        <td class="px-2 py-2 text-right font-medium tabular-nums text-slate-700" x-text="totals().damaged"></td>
                        <td colspan="4"></td>
                    </tr>
                </tfoot>
            </table>
            <button type="button" class="btn-secondary mt-3" @click="items.push(blank())">
                <x-heroicon-o-plus class="size-5" /> Tambah Baris
            </button>
            <p class="mt-3 text-xs text-slate-500">Hanya jumlah <span class="font-medium">Diterima Baik</span> yang masuk stok. Barang <span class="font-medium">Rusak</span> masuk ke Stok Rusak gudang (tidak ikut dijual), lalu bisa diretur ke supplier, dimusnahkan atau diperbaiki. Baris tanpa lokasi bin masuk sebagai stok "Tanpa bin" di gudang tujuan.</p>
        </div>
    </div>
</div>
