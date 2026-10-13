@php
    $needsFrom = in_array($type, ['out', 'transfer']);
    $needsTo = $type !== 'out';
    $blank = ['product_variant_id' => '', 'from_location_id' => '', 'to_location_id' => '', 'qty' => '', 'notes' => ''];
    $items = collect(old('items', []))->map(fn ($row) => [...$blank, ...$row])->values();
    $rowErrors = collect($errors->getMessages())->filter(fn ($messages, $key) => str_starts_with($key, 'items.'))->map(fn ($messages) => $messages[0]);
    $hints = [
        'in' => 'Tambah stok ke lokasi bin, misalnya saat barang datang dari supplier.',
        'out' => 'Kurangi stok dari lokasi bin, misalnya barang rusak, sampel atau pengiriman manual.',
        'transfer' => 'Pindahkan stok dari satu lokasi bin ke lokasi lain di gudang yang sama.',
        'adjustment' => 'Koreksi selisih stok. Isi angka positif untuk menambah, negatif untuk mengurangi.',
    ];
@endphp

<x-layouts.app :title="'Catat '.$meta['label']">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('inventory.movements.index') }}" class="hover:text-slate-700">Mutasi Stok</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">{{ $meta['label'] }}</span>
    </x-slot:breadcrumb>

    <x-page-header :title="'Catat '.$meta['label']" :description="$hints[$type]" :icon="$meta['icon']">
        <a href="{{ route('inventory.movements.index') }}" class="btn-secondary"><x-heroicon-o-arrow-left class="size-5" /> Kembali</a>
    </x-page-header>

    <form method="POST" action="{{ route('inventory.movements.store') }}" class="space-y-6"
          x-data="{
              warehouse: @js((string) old('warehouse_id', $warehouses->count() === 1 ? $warehouses->first()->id : '')),
              items: @js($items->isEmpty() ? [$blank] : $items),
              variants: @js($variants),
              locations: @js($locations),
              errors: @js($rowErrors),
              scan: '',
              scanError: '',
              blank() { return @js($blank) },
              locationsFor(activeOnly) {
                  return this.locations.filter(l => String(l.warehouse_id) === String(this.warehouse) && (! activeOnly || l.active));
              },
              get variantOptions() {
                  return this.variants.map(v => ({ value: v.id, text: v.label }));
              },
              locationOptions(activeOnly) {
                  return this.locationsFor(activeOnly).map(l => ({ value: l.id, text: l.code }));
              },
              addScanned() {
                  const code = this.scan.trim().toUpperCase();
                  if (! code) return;
                  const variant = this.variants.find(v => v.sku === code || (v.barcode && v.barcode.toUpperCase() === code));
                  if (! variant) { this.scanError = `SKU / barcode ${code} tidak ditemukan.`; return; }
                  this.scanError = '';
                  const empty = this.items.find(i => ! i.product_variant_id);
                  if (empty) { empty.product_variant_id = String(variant.id); } else { this.items.push({ ...this.blank(), product_variant_id: String(variant.id) }); }
                  this.scan = '';
              },
          }">
        @csrf
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
                <x-input name="movement_date" type="date" label="Tanggal" :value="now()->toDateString()" :max="now()->toDateString()" required />
                <x-input name="reference" label="Referensi" placeholder="{{ $type === 'in' ? 'cth. No. surat jalan / PO' : 'opsional' }}" />
                <x-textarea name="notes" label="Catatan" rows="2" class="sm:col-span-3" />
            </div>
        </div>

        <div class="card">
            <div class="flex flex-col gap-3 border-b border-slate-100 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="flex items-center gap-2 font-semibold text-slate-900">
                        <x-heroicon-o-cube class="size-5 text-slate-400" /> Barang
                    </h2>
                    <p class="mt-0.5 text-sm text-slate-500">Pilih varian atau scan SKU / barcode untuk menambah baris. Lokasi bin boleh dikosongkan (stok tercatat "Tanpa bin" di gudang).</p>
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
                Pilih gudang dulu agar daftar lokasi bin muncul.
            </p>

            <div class="overflow-x-auto p-6 pt-4">
                <table class="min-w-full text-sm">
                    <thead class="text-left text-xs font-semibold tracking-wider text-slate-500 uppercase">
                        <tr>
                            <th class="py-2 pr-2">Varian *</th>
                            @if ($needsFrom)
                                <th class="px-2 py-2">Lokasi Asal</th>
                            @endif
                            @if ($needsTo)
                                <th class="px-2 py-2">{{ $type === 'adjustment' ? 'Lokasi' : 'Lokasi Tujuan' }}</th>
                            @endif
                            <th class="px-2 py-2">{{ $type === 'adjustment' ? 'Jumlah (+/-)' : 'Jumlah' }} *</th>
                            <th class="px-2 py-2">Keterangan</th>
                            <th class="py-2 pl-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(item, index) in items" :key="index">
                            <tr class="align-top">
                                <td class="py-1.5 pr-2">
                                    <select class="form-input min-w-64" :name="`items[${index}][product_variant_id]`" x-model="item.product_variant_id"
                                            x-tom-select="{ options: variantOptions, value: item.product_variant_id }" data-placeholder="Cari SKU / nama varian"
                                            :class="errors[`items.${index}.product_variant_id`] && 'form-input-error'"></select>
                                    <p class="mt-1 text-xs text-rose-600" x-show="errors[`items.${index}.product_variant_id`]" x-text="errors[`items.${index}.product_variant_id`]"></p>
                                </td>
                                @if ($needsFrom)
                                    <td class="px-2 py-1.5">
                                        <select class="form-input min-w-40" :name="`items[${index}][from_location_id]`" x-model="item.from_location_id"
                                                x-tom-select="{ options: locationOptions(false), value: item.from_location_id }" data-placeholder="Tanpa bin"
                                                :class="errors[`items.${index}.from_location_id`] && 'form-input-error'">
                                            <option value="">Tanpa bin</option>
                                        </select>
                                        <p class="mt-1 text-xs text-rose-600" x-show="errors[`items.${index}.from_location_id`]" x-text="errors[`items.${index}.from_location_id`]"></p>
                                    </td>
                                @endif
                                @if ($needsTo)
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
                                    <input type="number" step="1" class="form-input w-28" :name="`items[${index}][qty]`" x-model="item.qty"
                                           {{ $type === 'adjustment' ? '' : 'min=1' }} :class="errors[`items.${index}.qty`] && 'form-input-error'">
                                    <p class="mt-1 max-w-48 text-xs text-rose-600" x-show="errors[`items.${index}.qty`]" x-text="errors[`items.${index}.qty`]"></p>
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
                </table>
                <button type="button" class="btn-secondary mt-3" @click="items.push(blank())">
                    <x-heroicon-o-plus class="size-5" /> Tambah Baris
                </button>
            </div>
        </div>

        <div class="sticky bottom-0 flex items-center justify-between gap-3 border-t border-slate-200 bg-slate-50/90 py-4 backdrop-blur">
            <p class="hidden items-center gap-1.5 text-xs text-slate-500 sm:flex">
                <x-heroicon-o-lock-closed class="size-4" /> Setelah disimpan, mutasi tidak bisa diubah.
            </p>
            <div class="flex gap-3">
                <a href="{{ route('inventory.movements.index') }}" class="btn-secondary">Batal</a>
                <button type="submit" class="btn-primary"><x-heroicon-o-check class="size-5" /> Simpan {{ $meta['label'] }}</button>
            </div>
        </div>
    </form>
</x-layouts.app>
