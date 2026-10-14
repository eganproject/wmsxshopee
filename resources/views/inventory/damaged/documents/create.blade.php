@php
    // Kolom per jenis: baris input selalu ada; assembly punya bahan (input) & hasil (output).
    $damagedOut = in_array($type, ['supplier_return', 'disposal', 'repair'], true);
    $blankInput = ['role' => 'input', 'source' => $type === 'intake' ? 'good' : 'damaged', 'product_variant_id' => '', 'from_location_id' => '', 'to_location_id' => '', 'qty' => '', 'notes' => ''];
    $blankOutput = [...$blankInput, 'role' => 'output', 'source' => 'good'];
    $existing = $document?->items->map(fn ($item) => [
        'role' => $item->role, 'source' => $item->source, 'product_variant_id' => (string) $item->product_variant_id,
        'from_location_id' => (string) ($item->from_location_id ?? ''), 'to_location_id' => (string) ($item->to_location_id ?? ''),
        'qty' => (string) $item->qty, 'notes' => $item->notes ?? '',
    ])->all() ?? [];
    $items = collect(old('items', $existing))->map(fn ($row) => [...($row['role'] ?? 'input') === 'output' ? $blankOutput : $blankInput, ...$row])->values();

    if ($items->isEmpty()) {
        $items = collect([[...$blankInput, 'product_variant_id' => (string) ($prefill['variant'] ?? '')]]);
        if ($type === 'assembly') {
            $items->push($blankOutput);
        }
    }

    $rowErrors = collect($errors->getMessages())->filter(fn ($messages, $key) => str_starts_with($key, 'items.'))->map(fn ($messages) => $messages[0]);
    $defaultWarehouse = old('warehouse_id', $prefill['warehouse'] ?? ($warehouses->count() === 1 ? $warehouses->first()->id : ''));
    $title = $document ? "Edit {$document->number}" : 'Catat '.$meta['label'];
@endphp

<x-layouts.app :title="$title">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('inventory.damaged.index') }}" class="hover:text-slate-700">Stok Rusak</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">{{ $document ? $document->number : $meta['label'] }}</span>
    </x-slot:breadcrumb>

    <x-page-header :title="$title" :description="$meta['hint']" :icon="$meta['icon']">
        <a href="{{ $document ? route('inventory.damaged.documents.show', $document) : route('inventory.damaged.index', array_filter(['warehouse' => $prefill['warehouse'] ?? null])) }}" class="btn-secondary"><x-heroicon-o-arrow-left class="size-5" /> Kembali</a>
    </x-page-header>

    <form method="POST" action="{{ $document ? route('inventory.damaged.documents.update', $document) : route('inventory.damaged.documents.store') }}" data-dirty-guard class="space-y-6"
          x-data="{
              warehouse: @js((string) $defaultWarehouse),
              items: @js($items),
              variants: @js($variants),
              locations: @js($locations),
              damaged: @js($damaged),
              errors: @js($rowErrors),
              scan: '',
              scanError: '',
              blankInput() { return @js($blankInput) },
              blankOutput() { return @js($blankOutput) },
              available(variantId) {
                  return Number((this.damaged[this.warehouse] || {})[variantId] || 0);
              },
              locationOptions(activeOnly) {
                  return this.locations
                      .filter(l => String(l.warehouse_id) === String(this.warehouse) && (! activeOnly || l.active))
                      .map(l => ({ value: l.id, text: l.code }));
              },
              get allOptions() {
                  return this.variants.map(v => ({ value: v.id, text: v.label }));
              },
              get damagedOptions() {
                  return this.variants
                      .filter(v => this.available(v.id) > 0)
                      .map(v => ({ value: v.id, text: `${v.label} · rusak ${this.available(v.id)}` }));
              },
              optionsFor(item) {
                  return item.role === 'input' && item.source === 'damaged' ? this.damagedOptions : this.allOptions;
              },
              count(role) {
                  return this.items.filter(i => i.role === role).length;
              },
              remove(index) {
                  this.items.splice(index, 1);
                  this.errors = {};
              },
              addScanned() {
                  const code = this.scan.trim().toUpperCase();
                  if (! code) return;
                  const variant = this.variants.find(v => v.sku === code || (v.barcode && v.barcode.toUpperCase() === code));
                  if (! variant) { this.scanError = `SKU / barcode ${code} tidak ditemukan.`; return; }
                  this.scanError = '';
                  const empty = this.items.find(i => i.role === 'input' && ! i.product_variant_id);
                  if (empty) { empty.product_variant_id = String(variant.id); } else { this.items.push({ ...this.blankInput(), product_variant_id: String(variant.id) }); }
                  this.scan = '';
              },
          }">
        @csrf
        @if ($document)
            @method('PUT')
        @endif
        <input type="hidden" name="type" value="{{ $type }}">

        <div class="card">
            <div class="border-b border-slate-100 px-6 py-4">
                <h2 class="flex items-center gap-2 font-semibold text-slate-900">
                    <x-heroicon-o-document-text class="size-5 text-slate-400" /> Informasi Dokumen
                </h2>
                <p class="mt-0.5 text-sm text-slate-500">Nomor dokumen dibuat otomatis saat disimpan.</p>
            </div>
            <div class="grid gap-5 p-6 sm:grid-cols-3">
                <x-select name="warehouse_id" label="Gudang" required x-model="warehouse">
                    <option value="">Pilih gudang</option>
                    @foreach ($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}">{{ $warehouse->code }} · {{ $warehouse->name }}</option>
                    @endforeach
                </x-select>
                <x-input name="document_date" type="date" label="Tanggal" :value="$document?->document_date->toDateString() ?? now()->toDateString()" :max="now()->toDateString()" required />
                <x-input name="reference" label="Referensi" :value="$document?->reference" :placeholder="$type === 'supplier_return' ? 'cth. no. surat jalan retur' : 'opsional'" />
                @if ($type === 'supplier_return')
                    <x-select name="supplier_id" label="Supplier" hint="Kosongkan bila diretur ke pihak lain.">
                        <option value="">Bukan supplier / pihak lain</option>
                        @foreach ($suppliers as $supplier)
                            <option value="{{ $supplier->id }}" @selected(old('supplier_id', $document?->supplier_id) == $supplier->id)>{{ $supplier->code }} · {{ $supplier->name }}</option>
                        @endforeach
                    </x-select>
                    <x-input name="recipient" label="Penerima Lain" :value="$document?->recipient" placeholder="cth. distributor, service center" hint="Diisi bila tidak memilih supplier." class="sm:col-span-2" />
                @endif
                <x-textarea name="notes" label="Catatan" rows="2" class="sm:col-span-3" :value="$document?->notes" />
            </div>
        </div>

        <div class="card">
            <div class="flex flex-col gap-3 border-b border-slate-100 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="flex items-center gap-2 font-semibold text-slate-900">
                        <x-heroicon-o-cube class="size-5 text-slate-400" /> {{ $type === 'assembly' ? 'Bahan' : 'Barang' }}
                    </h2>
                    <p class="mt-0.5 text-sm text-slate-500">
                        @switch($type)
                            @case('intake') Pilih barang dan lokasi bin asalnya di stok baik. Kosongkan lokasi untuk stok "Tanpa bin". @break
                            @case('repair') Hanya barang yang punya stok rusak di gudang ini. Hasil perbaikan masuk ke lokasi bin tujuan. @break
                            @case('assembly') Barang rusak yang dipakai, ditambah barang lain dari stok baik bila perlu. @break
                            @default Hanya barang yang punya stok rusak di gudang ini.
                        @endswitch
                    </p>
                </div>
                <div class="sm:w-72">
                    <div class="relative">
                        <x-heroicon-o-qr-code class="pointer-events-none absolute top-1/2 left-3.5 size-5 -translate-y-1/2 text-slate-400" />
                        <input type="text" class="form-input pl-11" placeholder="Scan SKU / barcode lalu Enter" x-model="scan" @keydown.enter.prevent="addScanned()">
                    </div>
                    <p class="mt-1 text-xs text-rose-600" x-show="scanError" x-text="scanError" x-cloak></p>
                </div>
            </div>

            @error('items')
                <p class="mx-6 mt-4 flex items-center gap-1 text-sm text-rose-600"><x-heroicon-o-exclamation-triangle class="size-4" /> {{ $message }}</p>
            @enderror

            <p class="mx-6 mt-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-700" x-show="! warehouse" x-cloak>
                Pilih gudang dulu agar daftar barang rusak dan lokasi bin muncul.
            </p>
            @if ($damagedOut)
                <p class="mx-6 mt-4 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600" x-show="warehouse && damagedOptions.length === 0" x-cloak>
                    Belum ada stok rusak di gudang ini.
                </p>
            @endif

            <div class="overflow-x-auto p-6 pt-4">
                <table class="min-w-full text-sm">
                    <thead class="text-left text-[13px] text-slate-500">
                        <tr>
                            <th class="py-2 pr-2 font-normal">Varian *</th>
                            @if ($type === 'assembly')
                                <th class="px-2 py-2 font-normal">Asal *</th>
                            @endif
                            @if (in_array($type, ['intake', 'assembly'], true))
                                <th class="px-2 py-2 font-normal">Lokasi Asal</th>
                            @endif
                            @if ($type !== 'intake')
                                <th class="px-2 py-2 text-right font-normal">Stok Rusak</th>
                            @endif
                            @if ($type === 'repair')
                                <th class="px-2 py-2 font-normal">Lokasi Tujuan</th>
                            @endif
                            <th class="px-2 py-2 font-normal">Jumlah *</th>
                            <th class="px-2 py-2 font-normal">Keterangan</th>
                            <th class="py-2 pl-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(item, index) in items" :key="index">
                            <template x-if="item.role === 'input'">
                            <tr class="align-top">
                                <td class="py-1.5 pr-2">
                                    <input type="hidden" :name="`items[${index}][role]`" :value="item.role">
                                    <select class="form-input min-w-64" :name="`items[${index}][product_variant_id]`" x-model="item.product_variant_id"
                                            x-tom-select="{ options: optionsFor(item), value: item.product_variant_id }" data-placeholder="Cari SKU / nama varian"
                                            :class="errors[`items.${index}.product_variant_id`] && 'form-input-error'"></select>
                                    <p class="mt-1 text-xs text-rose-600" x-show="errors[`items.${index}.product_variant_id`]" x-text="errors[`items.${index}.product_variant_id`]"></p>
                                </td>
                                @if ($type === 'assembly')
                                    <td class="px-2 py-1.5">
                                        <select class="form-input w-36" data-native :name="`items[${index}][source]`" x-model="item.source">
                                            <option value="damaged">Stok Rusak</option>
                                            <option value="good">Stok Baik</option>
                                        </select>
                                    </td>
                                @endif
                                @if (in_array($type, ['intake', 'assembly'], true))
                                    <td class="px-2 py-1.5">
                                        <div x-show="item.source === 'good'">
                                            <select class="form-input min-w-40" :name="`items[${index}][from_location_id]`" x-model="item.from_location_id"
                                                    x-tom-select="{ options: locationOptions(false), value: item.from_location_id }" data-placeholder="Tanpa bin"
                                                    :class="errors[`items.${index}.from_location_id`] && 'form-input-error'">
                                                <option value="">Tanpa bin</option>
                                            </select>
                                        </div>
                                        <p class="py-2 text-xs text-slate-400" x-show="item.source !== 'good'">—</p>
                                        <p class="mt-1 text-xs text-rose-600" x-show="errors[`items.${index}.from_location_id`]" x-text="errors[`items.${index}.from_location_id`]"></p>
                                    </td>
                                @endif
                                @if ($type !== 'intake')
                                    <td class="px-2 py-1.5 text-right">
                                        <p class="py-2 tabular-nums" x-show="item.source === 'damaged'"
                                           :class="item.product_variant_id && Number(item.qty) > available(item.product_variant_id) ? 'font-semibold text-rose-600' : 'text-slate-600'"
                                           x-text="item.product_variant_id ? available(item.product_variant_id) : '—'"></p>
                                        <p class="py-2 text-xs text-slate-400" x-show="item.source !== 'damaged'">—</p>
                                    </td>
                                @endif
                                @if ($type === 'repair')
                                    <td class="px-2 py-1.5">
                                        <select class="form-input min-w-40" :name="`items[${index}][to_location_id]`" x-model="item.to_location_id"
                                                x-tom-select="{ options: locationOptions(true), value: item.to_location_id }" data-placeholder="Tanpa bin"
                                                :class="errors[`items.${index}.to_location_id`] && 'form-input-error'">
                                            <option value="">Tanpa bin</option>
                                        </select>
                                        <p class="mt-1 text-xs text-rose-600" x-show="errors[`items.${index}.to_location_id`]" x-text="errors[`items.${index}.to_location_id`]"></p>
                                    </td>
                                @endif
                                <td class="px-2 py-1.5">
                                    <input type="number" min="1" step="1" class="form-input w-28" :name="`items[${index}][qty]`" x-model="item.qty"
                                           :class="errors[`items.${index}.qty`] && 'form-input-error'">
                                    <p class="mt-1 max-w-56 text-xs text-rose-600" x-show="errors[`items.${index}.qty`]" x-text="errors[`items.${index}.qty`]"></p>
                                </td>
                                <td class="px-2 py-1.5">
                                    <input type="text" class="form-input min-w-40" maxlength="255" :name="`items[${index}][notes]`" x-model="item.notes"
                                           placeholder="{{ $type === 'intake' ? 'cth. kemasan sobek' : '' }}">
                                </td>
                                <td class="py-1.5 pl-2">
                                    <button type="button" class="btn-icon hover:bg-rose-50 hover:text-rose-600" title="Hapus baris"
                                            x-show="count('input') > 1" @click="remove(index)">
                                        <x-heroicon-o-trash class="size-5" />
                                    </button>
                                </td>
                            </tr>
                            </template>
                        </template>
                    </tbody>
                </table>
                <button type="button" class="btn-secondary mt-3" @click="items.push(blankInput())">
                    <x-heroicon-o-plus class="size-5" /> Tambah {{ $type === 'assembly' ? 'Bahan' : 'Baris' }}
                </button>
            </div>
        </div>

        @if ($type === 'assembly')
            <div class="card">
                <div class="border-b border-slate-100 px-6 py-4">
                    <h2 class="flex items-center gap-2 font-semibold text-slate-900">
                        <x-heroicon-o-puzzle-piece class="size-5 text-slate-400" /> Hasil Rakit / Campur
                    </h2>
                    <p class="mt-0.5 text-sm text-slate-500">Barang baru yang dihasilkan. Masuk ke stok baik di lokasi bin tujuan.</p>
                </div>
                <div class="overflow-x-auto p-6 pt-4">
                    <table class="min-w-full text-sm">
                        <thead class="text-left text-[13px] text-slate-500">
                            <tr>
                                <th class="py-2 pr-2 font-normal">Varian Hasil *</th>
                                <th class="px-2 py-2 font-normal">Lokasi Tujuan</th>
                                <th class="px-2 py-2 font-normal">Jumlah *</th>
                                <th class="px-2 py-2 font-normal">Keterangan</th>
                                <th class="py-2 pl-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(item, index) in items" :key="index">
                                <template x-if="item.role === 'output'">
                                <tr class="align-top">
                                    <td class="py-1.5 pr-2">
                                        <input type="hidden" :name="`items[${index}][role]`" :value="item.role">
                                        <select class="form-input min-w-64" :name="`items[${index}][product_variant_id]`" x-model="item.product_variant_id"
                                                x-tom-select="{ options: allOptions, value: item.product_variant_id }" data-placeholder="Cari SKU / nama varian"
                                                :class="errors[`items.${index}.product_variant_id`] && 'form-input-error'"></select>
                                        <p class="mt-1 text-xs text-rose-600" x-show="errors[`items.${index}.product_variant_id`]" x-text="errors[`items.${index}.product_variant_id`]"></p>
                                    </td>
                                    <td class="px-2 py-1.5">
                                        <select class="form-input min-w-40" :name="`items[${index}][to_location_id]`" x-model="item.to_location_id"
                                                x-tom-select="{ options: locationOptions(true), value: item.to_location_id }" data-placeholder="Tanpa bin"
                                                :class="errors[`items.${index}.to_location_id`] && 'form-input-error'">
                                            <option value="">Tanpa bin</option>
                                        </select>
                                        <p class="mt-1 text-xs text-rose-600" x-show="errors[`items.${index}.to_location_id`]" x-text="errors[`items.${index}.to_location_id`]"></p>
                                    </td>
                                    <td class="px-2 py-1.5">
                                        <input type="number" min="1" step="1" class="form-input w-28" :name="`items[${index}][qty]`" x-model="item.qty"
                                               :class="errors[`items.${index}.qty`] && 'form-input-error'">
                                        <p class="mt-1 max-w-56 text-xs text-rose-600" x-show="errors[`items.${index}.qty`]" x-text="errors[`items.${index}.qty`]"></p>
                                    </td>
                                    <td class="px-2 py-1.5">
                                        <input type="text" class="form-input min-w-40" maxlength="255" :name="`items[${index}][notes]`" x-model="item.notes">
                                    </td>
                                    <td class="py-1.5 pl-2">
                                        <button type="button" class="btn-icon hover:bg-rose-50 hover:text-rose-600" title="Hapus baris"
                                                x-show="count('output') > 1" @click="remove(index)">
                                            <x-heroicon-o-trash class="size-5" />
                                        </button>
                                    </td>
                                </tr>
                                </template>
                            </template>
                        </tbody>
                    </table>
                    <button type="button" class="btn-secondary mt-3" @click="items.push(blankOutput())">
                        <x-heroicon-o-plus class="size-5" /> Tambah Hasil
                    </button>
                </div>
            </div>
        @endif

        <div class="sticky bottom-0 flex items-center justify-between gap-3 border-t border-slate-200 bg-slate-50/90 py-4 backdrop-blur">
            <p class="hidden items-center gap-1.5 text-xs text-slate-500 sm:flex">
                <x-heroicon-o-information-circle class="size-4" /> Stok baru berubah setelah dokumen disetujui. Draft masih bisa diubah.
            </p>
            <div class="flex gap-2">
                <a href="{{ $document ? route('inventory.damaged.documents.show', $document) : route('inventory.damaged.index') }}" class="btn-secondary">Batal</a>
                <button type="submit" name="action" value="draft" class="btn-secondary"><x-heroicon-o-document class="size-5" /> Simpan Draft</button>
                @can('damaged.approve')
                    <button type="submit" name="action" value="approve" class="btn-primary"><x-heroicon-o-check class="size-5" /> Simpan & Setujui</button>
                @endcan
            </div>
        </div>
    </form>
</x-layouts.app>
