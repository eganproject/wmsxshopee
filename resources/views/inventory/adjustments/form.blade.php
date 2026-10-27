@php
    $blank = ['product_variant_id' => '', 'warehouse_location_id' => '', 'qty' => '', 'notes' => ''];
    $items = collect(old('items', $lines))->map(fn ($row) => [...$blank, ...$row])->values();
    if ($items->isEmpty() && filled($prefill['variant'] ?? null)) {
        $items = collect([[...$blank, 'product_variant_id' => (string) $prefill['variant']]]);
    }
    $rowErrors = collect($errors->getMessages())->filter(fn ($messages, $key) => str_starts_with($key, 'items.'))->map(fn ($messages) => $messages[0]);
    $editing = $adjustment !== null;
    $title = $editing ? "Edit {$adjustment->number}" : 'Buat Penyesuaian Stok';
    $defaultWarehouse = $prefill['warehouse'] ?? ($warehouses->count() === 1 ? $warehouses->first()->id : '');
@endphp

<x-layouts.app :title="$title">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('inventory.adjustments.index') }}" class="hover:text-slate-700">Penyesuaian Stok</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">{{ $editing ? $adjustment->number : 'Baru' }}</span>
    </x-slot:breadcrumb>

    <x-page-header :title="$title" description="Isi angka positif untuk menambah stok, negatif untuk mengurangi. Stok baru berubah setelah penyesuaian disetujui." icon="heroicon-o-adjustments-horizontal">
        <a href="{{ $editing ? route('inventory.adjustments.show', $adjustment) : route('inventory.adjustments.index') }}" class="btn-secondary"><x-heroicon-o-arrow-left class="size-5" /> Kembali</a>
    </x-page-header>

    <x-form-errors />

    <form method="POST" action="{{ $editing ? route('inventory.adjustments.update', $adjustment) : route('inventory.adjustments.store') }}" data-dirty-guard class="space-y-6"
          x-data="{
              warehouse: @js((string) old('warehouse_id', $defaultWarehouse)),
              items: @js($items->isEmpty() ? [$blank] : $items),
              variants: @js($variants),
              locations: @js($locations),
              balances: @js($balances),
              errors: @js($rowErrors),
              scan: '',
              scanError: '',
              blank() { return @js($blank) },
              get variantOptions() {
                  return this.variants.map(v => ({ value: v.id, text: v.label }));
              },
              locationOptions() {
                  return this.locations.filter(l => String(l.warehouse_id) === String(this.warehouse))
                      .map(l => ({ value: l.id, text: l.active ? l.code : `${l.code} (nonaktif)` }));
              },
              systemQty(item) {
                  if (! this.warehouse || ! item.product_variant_id) return null;
                  return this.balances[`${this.warehouse}|${item.product_variant_id}|${item.warehouse_location_id || ''}`] ?? 0;
              },
              afterQty(item) {
                  const current = this.systemQty(item);
                  const qty = parseInt(item.qty, 10);
                  return current === null || isNaN(qty) ? null : current + qty;
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
        @if ($editing)
            @method('PUT')
        @endif

        <div class="card">
            <div class="border-b border-slate-100 px-6 py-4">
                <h2 class="flex items-center gap-2 font-semibold text-slate-900">
                    <x-heroicon-o-document-text class="size-5 text-slate-400" /> Informasi Dokumen
                </h2>
                <p class="mt-0.5 text-sm text-slate-500">{{ $editing ? 'Nomor dokumen: '.$adjustment->number : 'Nomor dokumen dibuat otomatis saat disimpan.' }}</p>
            </div>
            <div class="grid gap-5 p-6 sm:grid-cols-2 lg:grid-cols-4">
                <x-select name="warehouse_id" label="Gudang" tip="Gudang yang stoknya dikoreksi." required x-model="warehouse">
                    <option value="">Pilih gudang</option>
                    @foreach ($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}">{{ $warehouse->code }} · {{ $warehouse->name }}</option>
                    @endforeach
                </x-select>
                <x-input name="adjustment_date" type="date" label="Tanggal" :value="$adjustment?->adjustment_date?->toDateString() ?? now()->toDateString()" :max="now()->toDateString()" required />
                <x-select name="reason" label="Alasan" tip="Penyebab selisih stok. Dipakai untuk filter dan analisis." required>
                    <option value="">Pilih alasan</option>
                    @foreach ($reasons as $value => $label)
                        <option value="{{ $value }}" @selected(old('reason', $adjustment?->reason) === $value)>{{ $label }}</option>
                    @endforeach
                </x-select>
                <x-input name="reference" label="Referensi" tip="Nomor acuan bebas, misalnya nomor berita acara. Muncul di kartu stok." placeholder="opsional" :value="$adjustment?->reference" />
                <x-textarea name="notes" label="Catatan" rows="2" class="sm:col-span-2 lg:col-span-4" placeholder="Jelaskan penyebab selisih supaya penyetuju bisa memutuskan." :value="$adjustment?->notes" />
            </div>
        </div>

        <div class="card">
            <div class="flex flex-col gap-3 border-b border-slate-100 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="flex items-center gap-2 font-semibold text-slate-900">
                        <x-heroicon-o-cube class="size-5 text-slate-400" /> Barang
                    </h2>
                    <p class="mt-0.5 text-sm text-slate-500">Pilih varian atau scan SKU / barcode. Lokasi bin boleh dikosongkan (stok "Tanpa bin" di gudang).</p>
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
                Pilih gudang dulu agar daftar lokasi bin dan stok sistem muncul.
            </p>

            <div class="overflow-x-auto p-6 pt-4">
                <table class="min-w-full text-sm">
                    <thead class="text-left text-[13px] text-slate-500">
                        <tr>
                            <th class="py-2 pr-2">Varian *</th>
                            <th class="px-2 py-2">Lokasi <x-hint text="Bin yang dikoreksi. Lokasi nonaktif hanya bisa dikurangi." /></th>
                            <th class="px-2 py-2 text-right"><span class="whitespace-nowrap">Stok Sistem <x-hint text="Saldo saat ini di lokasi tersebut. Saat disetujui, penyesuaian dihitung dari saldo terbaru." /></span></th>
                            <th class="px-2 py-2">Jumlah (+/-) <span class="whitespace-nowrap">* <x-hint text="Angka positif menambah stok, negatif mengurangi." /></span></th>
                            <th class="px-2 py-2 text-right">Menjadi</th>
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
                                <td class="px-2 py-1.5">
                                    <select class="form-input min-w-40" :name="`items[${index}][warehouse_location_id]`" x-model="item.warehouse_location_id"
                                            x-tom-select="{ options: locationOptions(), value: item.warehouse_location_id }" data-placeholder="Tanpa bin"
                                            :class="errors[`items.${index}.warehouse_location_id`] && 'form-input-error'">
                                        <option value="">Tanpa bin</option>
                                    </select>
                                    <p class="mt-1 text-xs text-rose-600" x-show="errors[`items.${index}.warehouse_location_id`]" x-text="errors[`items.${index}.warehouse_location_id`]"></p>
                                </td>
                                <td class="px-2 py-1.5 pt-3.5 text-right tabular-nums text-slate-600" x-text="systemQty(item) ?? '—'"></td>
                                <td class="px-2 py-1.5">
                                    <input type="number" step="1" class="form-input w-28" :name="`items[${index}][qty]`" x-model="item.qty"
                                           :class="errors[`items.${index}.qty`] && 'form-input-error'">
                                    <p class="mt-1 max-w-48 text-xs text-rose-600" x-show="errors[`items.${index}.qty`]" x-text="errors[`items.${index}.qty`]"></p>
                                </td>
                                <td class="px-2 py-1.5 pt-3.5 text-right font-medium tabular-nums"
                                    :class="afterQty(item) !== null && afterQty(item) < 0 ? 'text-rose-600' : 'text-slate-900'"
                                    x-text="afterQty(item) ?? '—'"></td>
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
                <x-heroicon-o-lock-closed class="size-4" /> Stok belum berubah sampai penyesuaian disetujui.
            </p>
            <div class="flex gap-3">
                <a href="{{ $editing ? route('inventory.adjustments.show', $adjustment) : route('inventory.adjustments.index') }}" class="btn-secondary">Batal</a>
                <button type="submit" name="action" value="draft" class="btn-secondary"><x-heroicon-o-document class="size-5" /> Simpan Draft</button>
                <button type="submit" name="action" value="submit" class="btn-primary"><x-heroicon-o-paper-airplane class="size-5" /> Simpan & Ajukan</button>
            </div>
        </div>
    </form>
</x-layouts.app>
