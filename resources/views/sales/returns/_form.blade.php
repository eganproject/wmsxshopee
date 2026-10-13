@php
    $blank = ['sales_order_item_id' => '', 'product_variant_id' => '', 'qty_good' => '', 'qty_damaged' => '', 'qty_missing' => '', 'warehouse_location_id' => '', 'reason' => '', 'photo_path' => '', 'photo_url' => '', 'notes' => ''];
    $existing = $return->relationLoaded('items')
        ? $return->items->map(fn ($item) => [
            'sales_order_item_id' => (string) ($item->sales_order_item_id ?? ''),
            'product_variant_id' => (string) $item->product_variant_id,
            'qty_good' => $item->qty_good ?: '',
            'qty_damaged' => $item->qty_damaged ?: '',
            'qty_missing' => $item->qty_missing ?: '',
            'warehouse_location_id' => (string) ($item->warehouse_location_id ?? ''),
            'reason' => $item->reason,
            'photo_path' => $item->photo_path ?? '',
            'notes' => $item->notes ?? '',
        ])
        : collect();
    $items = collect(old('items', $existing->all()))
        ->map(fn ($row) => [...$blank, ...\Illuminate\Support\Arr::except($row, 'photo')])
        ->map(fn ($row) => [...$row, 'photo_url' => $row['photo_path'] ? Storage::url($row['photo_path']) : ''])
        ->values();
    $rowErrors = collect($errors->getMessages())->filter(fn ($messages, $key) => str_starts_with($key, 'items.'))->map(fn ($messages) => $messages[0]);
    $defaultWarehouse = $return->warehouse_id ?? ($orderPayload['warehouse_id'] ?? ($warehouses->count() === 1 ? $warehouses->first()->id : ''));
    $reasonOptions = collect($reasons)->map(fn ($label, $value) => ['value' => $value, 'text' => $label])->values();
@endphp

<div class="space-y-6"
     x-data="{
         source: @js(old('source', $return->source ?? 'order')),
         warehouse: @js((string) old('warehouse_id', $defaultWarehouse)),
         tracking: @js((string) old('tracking_number', $return->tracking_number ?? '')),
         order: @js($orderPayload),
         choices: [],
         code: '',
         lookupError: '',
         looking: false,
         orderRows: [],
         manualRows: [],
         variants: @js($variants),
         locations: @js($locations),
         reasons: @js($reasonOptions),
         errors: @js($rowErrors),
         scan: '',
         scanError: '',
         uid: 0,
         init() {
             const saved = @js($items);
             if (this.source === 'order') {
                 this.orderRows = this.order ? this.buildOrderRows(saved) : [];
                 this.manualRows = [this.blankRow()];
             } else {
                 this.manualRows = saved.length ? saved.map(row => this.row(row)) : [this.blankRow()];
             }
         },
         row(data) {
             return { ...@js($blank), ...data, uid: ++this.uid, preview: '' };
         },
         blankRow() {
             // Baris baru ikut lokasi bin & alasan baris terakhir supaya tidak perlu pilih ulang.
             const last = this.manualRows[this.manualRows.length - 1];
             return this.row({ warehouse_location_id: last ? last.warehouse_location_id : '', reason: last ? last.reason : '' });
         },
         buildOrderRows(saved = []) {
             return this.order.items.map(line => {
                 const prev = saved.find(row => String(row.sales_order_item_id) === String(line.id)) || {};
                 return this.row({ ...prev, sales_order_item_id: String(line.id), product_variant_id: String(line.product_variant_id), line });
             });
         },
         get rows() {
             return this.source === 'order' ? this.orderRows : this.manualRows;
         },
         get variantOptions() {
             return this.variants.map(v => ({ value: v.id, text: v.label }));
         },
         get locationOptions() {
             return this.locations.filter(l => String(l.warehouse_id) === String(this.warehouse)).map(l => ({ value: l.id, text: l.code }));
         },
         qty(item) {
             return (parseInt(item.qty_good) || 0) + (parseInt(item.qty_damaged) || 0) + (parseInt(item.qty_missing) || 0);
         },
         totals() {
             const sum = key => this.rows.reduce((total, item) => total + (parseInt(item[key]) || 0), 0);
             return { good: sum('qty_good'), damaged: sum('qty_damaged'), missing: sum('qty_missing') };
         },
         setWarehouse(id) {
             this.warehouse = String(id);
             document.getElementById('warehouse_id')?.tomselect?.setValue(this.warehouse, true);
         },
         async lookup() {
             const code = this.code.trim();
             if (! code || this.looking) return;
             this.looking = true;
             this.lookupError = '';
             this.choices = [];
             try {
                 const response = await fetch(@js(route('sales.returns.lookup')) + '?' + new URLSearchParams({ code, except: @js((string) ($return->id ?? '')) }), { headers: { Accept: 'application/json' } });
                 const data = await response.json();
                 if (! response.ok) { this.lookupError = data.message || 'Pesanan tidak ditemukan.'; return; }
                 if (data.matched_tracking && ! this.tracking) this.tracking = code;
                 if (data.orders.length === 1) this.pick(data.orders[0]); else this.choices = data.orders;
                 this.code = '';
             } catch (e) {
                 this.lookupError = 'Gagal mencari pesanan. Coba lagi.';
             } finally {
                 this.looking = false;
             }
         },
         pick(order) {
             this.order = order;
             this.choices = [];
             this.orderRows = this.buildOrderRows();
             this.errors = {};
             this.setWarehouse(order.warehouse_id);
             this.$nextTick(() => this.$refs.itemScan?.focus());
         },
         resetOrder() {
             this.order = null;
             this.orderRows = [];
             this.$nextTick(() => this.$refs.orderScan?.focus());
         },
         addScanned() {
             const code = this.scan.trim().toUpperCase();
             if (! code) return;
             const match = v => v.sku.toUpperCase() === code || (v.barcode && v.barcode.toUpperCase() === code);
             this.scan = '';
             this.scanError = '';
             if (this.source === 'order') {
                 // Scan barang dari paket: jumlah layak jual baris itu bertambah 1, maksimal sisa yang bisa diretur.
                 const rows = this.orderRows.filter(r => match(r.line));
                 if (! rows.length) { this.scanError = `${code} tidak ada di pesanan ${this.order.number}.`; return; }
                 const row = rows.find(r => this.qty(r) < r.line.remaining);
                 if (! row) { this.scanError = `${code} sudah mencapai jumlah maksimal retur.`; return; }
                 row.qty_good = (parseInt(row.qty_good) || 0) + 1;
                 return;
             }
             const variant = this.variants.find(match);
             if (! variant) { this.scanError = `SKU / barcode ${code} tidak ditemukan.`; return; }
             const existing = this.manualRows.find(r => String(r.product_variant_id) === String(variant.id));
             if (existing) { existing.qty_good = (parseInt(existing.qty_good) || 0) + 1; return; }
             const empty = this.manualRows.find(r => ! r.product_variant_id);
             if (empty) { empty.product_variant_id = String(variant.id); empty.qty_good = empty.qty_good || 1; return; }
             this.manualRows.push({ ...this.blankRow(), product_variant_id: String(variant.id), qty_good: 1 });
         },
         preview(item, event) {
             const file = event.target.files[0];
             item.preview = file ? URL.createObjectURL(file) : '';
         },
         clearPhoto(item) {
             item.photo_path = '';
             item.photo_url = '';
             item.preview = '';
             const input = document.getElementById(`photo-${item.uid}`);
             if (input) input.value = '';
         },
     }">
    <input type="hidden" name="source" :value="source">

    <div class="card">
        <div class="border-b border-slate-100 px-5 py-3">
            <h2 class="flex items-center gap-2 text-base font-semibold text-slate-900">
                <x-heroicon-o-document-text class="size-5 text-slate-400" /> Informasi Retur
            </h2>
            <p class="mt-0.5 text-sm text-slate-500">
                @if ($return->number)
                    Nomor <span class="font-mono font-medium text-slate-700">{{ $return->number }}</span>
                @else
                    Nomor dibuat otomatis saat disimpan, cth. {{ \App\Models\SalesReturn::PREFIX.now()->format('ym') }}-001.
                @endif
            </p>
        </div>

        <div class="flex flex-col gap-5 p-5">
            <div>
                <p class="form-label">Sumber Retur</p>
                <div class="inline-flex rounded-lg border border-slate-200 p-0.5">
                    @foreach (\App\Models\SalesReturn::SOURCES as $value => $label)
                        <button type="button" class="flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium"
                                :class="source === @js($value) ? 'bg-slate-900 text-white shadow-sm' : 'text-slate-600 hover:text-slate-900'"
                                @click="source = @js($value); errors = {}">
                            <x-dynamic-component :component="$value === 'order' ? 'heroicon-o-shopping-cart' : 'heroicon-o-user'" class="size-4" /> {{ $label }}
                        </button>
                    @endforeach
                </div>
                <p class="mt-1 text-xs text-slate-500" x-show="source === 'manual'" x-cloak>Untuk paket yang tidak punya pesanan di Optima, cth. dari reseller atau toko offline.</p>
            </div>

            {{-- Retur dari pesanan: scan resi / no. SO / no. pesanan --}}
            <template x-if="source === 'order'">
                <div>
                    <input type="hidden" name="sales_order_id" :value="order ? order.id : ''">

                    <div x-show="! order">
                        <label class="form-label" for="order-scan">Scan Resi / No. SO / No. Pesanan <span class="text-rose-500">*</span></label>
                        <div class="flex max-w-xl gap-2">
                            <div class="relative flex-1">
                                <x-heroicon-o-qr-code class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-slate-400" />
                                <input id="order-scan" type="text" x-ref="orderScan" class="form-input pl-8" placeholder="cth. JX1234567890 atau SO2610-001"
                                       x-model="code" @keydown.enter.prevent="lookup()" autofocus>
                            </div>
                            <button type="button" class="btn-secondary" @click="lookup()" :disabled="looking">
                                <x-heroicon-o-magnifying-glass class="size-5" /> <span x-text="looking ? 'Mencari...' : 'Cari'"></span>
                            </button>
                        </div>
                        <p class="mt-1 flex items-center gap-1 text-xs text-rose-600" x-show="lookupError" x-cloak><x-heroicon-o-exclamation-triangle class="size-4 shrink-0" /> <span x-text="lookupError"></span></p>
                        @error('sales_order_id')
                            <p class="mt-1 flex items-center gap-1 text-xs text-rose-600"><x-heroicon-o-exclamation-triangle class="size-4 shrink-0" /> {{ $message }}</p>
                        @else
                            <p class="mt-1 text-xs text-slate-400" x-show="! lookupError">Kalau resi retur berbeda dari resi kirim, cari lewat no. SO atau no. pesanan channel.</p>
                        @enderror

                        <div class="mt-3 max-w-xl space-y-2" x-show="choices.length" x-cloak>
                            <p class="text-sm text-slate-600">Ditemukan beberapa pesanan, pilih salah satu:</p>
                            <template x-for="choice in choices" :key="choice.id">
                                <button type="button" class="flex w-full items-center justify-between rounded-lg border border-slate-200 px-3 py-2 text-left text-sm hover:border-brand-500" @click="pick(choice)">
                                    <span><span class="font-mono font-medium" x-text="choice.number"></span> · <span x-text="choice.customer_name"></span></span>
                                    <span class="text-xs text-slate-500" x-text="[choice.channel_name, choice.external_number].filter(Boolean).join(' #')"></span>
                                </button>
                            </template>
                        </div>
                    </div>

                    <div class="rounded-lg border border-emerald-200 bg-emerald-50/60 p-4" x-show="order" x-cloak>
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="flex items-start gap-3">
                                <x-heroicon-o-check-circle class="mt-0.5 size-5 shrink-0 text-emerald-600" />
                                <div>
                                    <p class="text-sm font-semibold text-slate-900">
                                        <a :href="order?.url" target="_blank" class="font-mono hover:text-brand-600" x-text="order?.number"></a>
                                        · <span x-text="order?.customer_name"></span>
                                    </p>
                                    <p class="mt-0.5 text-xs text-slate-600">
                                        <span x-show="order?.channel_name" x-text="order?.channel_name + (order?.external_number ? ' #' + order.external_number : '')"></span>
                                        <span x-show="order?.tracking_number"> · Resi kirim <span class="font-mono" x-text="order?.tracking_number"></span></span>
                                        <span x-show="order?.shipped_at"> · Dikirim <span x-text="order?.shipped_at"></span></span>
                                    </p>
                                    <p class="mt-1 text-xs text-amber-700" x-show="order?.returns.length">
                                        Pesanan ini sudah punya retur <span class="font-mono" x-text="order?.returns.join(', ')"></span>. Jumlah yang sudah diretur tidak bisa diretur lagi.
                                    </p>
                                </div>
                            </div>
                            <button type="button" class="btn-secondary" @click="resetOrder()"><x-heroicon-o-arrow-path class="size-5" /> Ganti Pesanan</button>
                        </div>
                    </div>
                </div>
            </template>

            {{-- Retur tanpa pesanan --}}
            <template x-if="source === 'manual'">
                <div class="grid gap-x-6 gap-y-4 sm:grid-cols-3">
                    <x-input name="sender_name" label="Nama Pengirim" :value="$return->source === 'manual' ? $return->sender_name : null" placeholder="cth. Reseller Budi" required />
                    <x-input name="sender_phone" label="Telepon" :value="$return->source === 'manual' ? $return->sender_phone : null" placeholder="cth. 0812..." />
                    <x-input name="channel_name" label="Channel / Sumber" :value="$return->source === 'manual' ? $return->channel_name : null" placeholder="cth. Toko Offline" />
                    <x-input name="external_number" label="No. Nota / Referensi" :value="$return->source === 'manual' ? $return->external_number : null" placeholder="opsional" />
                </div>
            </template>

            <div class="grid gap-x-6 gap-y-4 sm:grid-cols-3">
                <x-input name="return_date" type="date" label="Tanggal Retur" :value="$return->return_date?->toDateString()" :max="now()->toDateString()" required />
                <x-select name="warehouse_id" label="Gudang Penerima" required x-model="warehouse">
                    <option value="">Pilih gudang</option>
                    @foreach ($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}">{{ $warehouse->code }} · {{ $warehouse->name }}</option>
                    @endforeach
                </x-select>
                <div>
                    <label for="tracking_number" class="form-label">No. Resi Retur</label>
                    <input id="tracking_number" name="tracking_number" type="text" maxlength="100" x-model="tracking"
                           @class(['form-input', 'form-input-error' => $errors->has('tracking_number')]) :placeholder="source === 'order' ? 'kosong = resi kirim pesanan' : 'opsional'">
                    @error('tracking_number')
                        <p class="mt-1 flex items-center gap-1 text-xs text-rose-600"><x-heroicon-o-exclamation-triangle class="size-4 shrink-0" /> {{ $message }}</p>
                    @enderror
                </div>
                <x-textarea name="notes" label="Catatan" :value="$return->notes" rows="2" class="sm:col-span-3" />
            </div>
        </div>
    </div>

    <div class="card" x-show="source === 'manual' || order">
        <div class="flex flex-col gap-3 border-b border-slate-100 px-5 py-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="flex items-center gap-2 text-base font-semibold text-slate-900">
                    <x-heroicon-o-cube class="size-5 text-slate-400" /> Cek Barang Retur
                </h2>
                <p class="mt-0.5 text-sm text-slate-500" x-show="source === 'order'">Isi jumlah yang kembali per barang. Scan SKU / barcode menambah 1 layak jual. Barang yang dikosongkan tidak ikut retur.</p>
                <p class="mt-0.5 text-sm text-slate-500" x-show="source === 'manual'" x-cloak>Scan SKU / barcode satu per satu: barang baru menambah baris, barang yang sama menambah jumlah layak jual.</p>
            </div>
            <div class="sm:w-72">
                <div class="relative">
                    <x-heroicon-o-qr-code class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-slate-400" />
                    <input type="text" x-ref="itemScan" class="form-input pl-8" placeholder="Scan SKU / barcode lalu Enter" x-model="scan" @keydown.enter.prevent="addScanned()">
                </div>
                <p class="mt-1 text-xs text-rose-600" x-show="scanError" x-text="scanError" x-cloak></p>
            </div>
        </div>

        @error('items')
            <p class="mx-5 mt-4 flex items-center gap-1 text-sm text-rose-600"><x-heroicon-o-exclamation-triangle class="size-4" /> {{ $message }}</p>
        @enderror

        <p class="mx-5 mt-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-700" x-show="! warehouse" x-cloak>
            Pilih gudang penerima dulu agar daftar lokasi bin muncul. Lokasi bin boleh dikosongkan.
        </p>

        <div class="overflow-x-auto p-5 pt-4">
            <table class="min-w-full text-sm">
                <thead class="text-left text-[13px] text-slate-500">
                    <tr>
                        <th class="py-2 pr-2 font-normal">Barang *</th>
                        <th class="px-2 py-2 text-right font-normal" x-show="source === 'order'">Dikirim</th>
                        <th class="px-2 py-2 text-right font-normal whitespace-nowrap">Layak Jual</th>
                        <th class="px-2 py-2 text-right font-normal">Rusak</th>
                        <th class="px-2 py-2 text-right font-normal" title="Seharusnya ikut kembali tapi tidak ada di paket">Hilang</th>
                        <th class="px-2 py-2 font-normal">Lokasi Bin</th>
                        <th class="px-2 py-2 font-normal">Alasan *</th>
                        <th class="px-2 py-2 font-normal">Foto</th>
                        <th class="px-2 py-2 font-normal">Keterangan</th>
                        <th class="py-2 pl-2" x-show="source === 'manual'"></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(item, index) in rows" :key="item.uid">
                        <tr class="align-top" :class="source === 'order' && ! qty(item) && 'opacity-60'">
                            <td class="py-1.5 pr-2">
                                <template x-if="source === 'order'">
                                    <div class="min-w-56 pt-1">
                                        <input type="hidden" :name="`items[${index}][sales_order_item_id]`" :value="item.sales_order_item_id">
                                        <p class="font-mono font-medium text-slate-900" x-text="item.line.sku"></p>
                                        <p class="text-xs text-slate-500" x-text="item.line.label.split(' · ').slice(1).join(' · ')"></p>
                                        <p class="text-xs text-violet-600" x-show="item.line.bundle" x-text="'Isi paket ' + item.line.bundle"></p>
                                    </div>
                                </template>
                                <template x-if="source === 'manual'">
                                    <div>
                                        <select class="form-input min-w-64" :name="`items[${index}][product_variant_id]`" x-model="item.product_variant_id"
                                                x-tom-select="{ options: variantOptions, value: item.product_variant_id }" data-placeholder="Cari SKU / nama barang"
                                                :class="errors[`items.${index}.product_variant_id`] && 'form-input-error'"></select>
                                        <p class="mt-1 text-xs text-rose-600" x-show="errors[`items.${index}.product_variant_id`]" x-text="errors[`items.${index}.product_variant_id`]"></p>
                                    </div>
                                </template>
                            </td>
                            <td class="px-2 py-1.5 text-right" x-show="source === 'order'">
                                <span class="inline-block pt-1.5 tabular-nums text-slate-600" x-text="item.line?.shipped"></span>
                            </td>
                            <td class="px-2 py-1.5">
                                <input type="number" min="0" step="1" class="form-input w-16 text-right" :name="`items[${index}][qty_good]`" x-model="item.qty_good" placeholder="0"
                                       :class="errors[`items.${index}.qty_good`] && 'form-input-error'">
                                <p class="mt-1 max-w-40 text-xs text-rose-600" x-show="errors[`items.${index}.qty_good`]" x-text="errors[`items.${index}.qty_good`]"></p>
                            </td>
                            <td class="px-2 py-1.5">
                                <input type="number" min="0" step="1" class="form-input w-16 text-right" :name="`items[${index}][qty_damaged]`" x-model="item.qty_damaged" placeholder="0"
                                       :class="errors[`items.${index}.qty_damaged`] && 'form-input-error'">
                            </td>
                            <td class="px-2 py-1.5">
                                <input type="number" min="0" step="1" class="form-input w-16 text-right" :name="`items[${index}][qty_missing]`" x-model="item.qty_missing" placeholder="0"
                                       :class="errors[`items.${index}.qty_missing`] && 'form-input-error'">
                            </td>
                            <td class="px-2 py-1.5">
                                <div x-show="parseInt(item.qty_good) > 0 || source === 'manual'">
                                    <select class="form-input min-w-32" :name="`items[${index}][warehouse_location_id]`" x-model="item.warehouse_location_id"
                                            x-tom-select="{ options: locationOptions, value: item.warehouse_location_id }" data-placeholder="Tanpa bin"
                                            :class="errors[`items.${index}.warehouse_location_id`] && 'form-input-error'">
                                        <option value="">Tanpa bin</option>
                                    </select>
                                </div>
                                <span class="inline-block pt-1.5 text-xs text-slate-400" x-show="! (parseInt(item.qty_good) > 0) && source === 'order'">—</span>
                                <p class="mt-1 max-w-48 text-xs text-rose-600" x-show="errors[`items.${index}.warehouse_location_id`]" x-text="errors[`items.${index}.warehouse_location_id`]"></p>
                            </td>
                            <td class="px-2 py-1.5">
                                <div class="w-44">
                                <select data-native class="form-input w-full" :name="`items[${index}][reason]`" x-model="item.reason"
                                        :class="errors[`items.${index}.reason`] && 'form-input-error'">
                                    <option value="">Pilih alasan</option>
                                    <template x-for="reason in reasons" :key="reason.value">
                                        <option :value="reason.value" x-text="reason.text" :selected="reason.value === item.reason"></option>
                                    </template>
                                </select>
                                <p class="mt-1 text-xs text-rose-600" x-show="errors[`items.${index}.reason`]" x-text="errors[`items.${index}.reason`]"></p>
                                </div>
                            </td>
                            <td class="px-2 py-1.5">
                                <input type="hidden" :name="`items[${index}][photo_path]`" :value="item.photo_path">
                                <div class="flex items-center gap-2">
                                    <template x-if="item.preview || item.photo_url">
                                        <a :href="item.preview || item.photo_url" target="_blank" class="block size-9 shrink-0 overflow-hidden rounded-md border border-slate-200">
                                            <img :src="item.preview || item.photo_url" alt="" class="size-full object-cover">
                                        </a>
                                    </template>
                                    <label class="btn-icon cursor-pointer" :title="item.preview || item.photo_url ? 'Ganti foto' : 'Ambil / pilih foto'">
                                        <x-heroicon-o-camera class="size-5" />
                                        <input type="file" class="sr-only" accept="image/jpeg,image/png,image/webp" capture="environment"
                                               :id="`photo-${item.uid}`" :name="`items[${index}][photo]`" @change="preview(item, $event)">
                                    </label>
                                    <button type="button" class="btn-icon hover:text-rose-600" title="Hapus foto" x-show="item.preview || item.photo_url" @click="clearPhoto(item)">
                                        <x-heroicon-o-x-mark class="size-4" />
                                    </button>
                                </div>
                                <p class="mt-1 max-w-40 text-xs text-rose-600" x-show="errors[`items.${index}.photo`]" x-text="errors[`items.${index}.photo`]"></p>
                            </td>
                            <td class="px-2 py-1.5">
                                <input type="text" class="form-input min-w-32" maxlength="255" :name="`items[${index}][notes]`" x-model="item.notes" placeholder="cth. segel terbuka">
                            </td>
                            <td class="py-1.5 pl-2" x-show="source === 'manual'">
                                <button type="button" class="btn-icon hover:bg-rose-50 hover:text-rose-600" title="Hapus baris"
                                        x-show="manualRows.length > 1" @click="manualRows.splice(index, 1); errors = {}">
                                    <x-heroicon-o-trash class="size-5" />
                                </button>
                            </td>
                        </tr>
                    </template>
                </tbody>
                <tfoot class="border-t border-slate-100 text-[13px] text-slate-500">
                    <tr>
                        <td class="py-2 pr-2 text-right" :colspan="source === 'order' ? 2 : 1">Total</td>
                        <td class="px-2 py-2 text-right font-medium tabular-nums text-emerald-700" x-text="totals().good"></td>
                        <td class="px-2 py-2 text-right font-medium tabular-nums text-rose-600" x-text="totals().damaged"></td>
                        <td class="px-2 py-2 text-right font-medium tabular-nums text-amber-600" x-text="totals().missing"></td>
                        <td colspan="5"></td>
                    </tr>
                </tfoot>
            </table>
            <button type="button" class="btn-secondary mt-3" x-show="source === 'manual'" @click="manualRows.push(blankRow())">
                <x-heroicon-o-plus class="size-5" /> Tambah Baris
            </button>
            <p class="mt-3 text-xs text-slate-500">Hanya jumlah <span class="font-medium">Layak Jual</span> yang masuk lagi ke stok. Barang <span class="font-medium">Rusak</span> dicatat terpisah di retur ini dan tidak menambah stok jual. <span class="font-medium">Hilang</span> untuk barang yang seharusnya ikut kembali tapi tidak ada di paket (bahan klaim ke Shopee / kurir); alasannya otomatis "Tidak ada di paket". Tanpa lokasi bin, barang masuk sebagai stok "Tanpa bin".</p>
        </div>
    </div>
</div>
