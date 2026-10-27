@php
    $blank = ['product_variant_id' => '', 'qty' => 1];
    $existing = $bundle->relationLoaded('items')
        ? $bundle->items->map(fn ($item) => ['product_variant_id' => (string) $item->product_variant_id, 'qty' => $item->qty])
        : collect();
    $items = collect(old('items', $existing->all()))->map(fn ($row) => [...$blank, ...$row])->values();
    $rowErrors = collect($errors->getMessages())->filter(fn ($messages, $key) => str_starts_with($key, 'items.'))->map(fn ($messages) => $messages[0]);
@endphp

<div class="space-y-6">
    <div class="card">
        <div class="border-b border-slate-100 px-6 py-4">
            <h2 class="flex items-center gap-2 font-semibold text-slate-900">
                <x-heroicon-o-gift class="size-5 text-slate-400" /> Informasi Paket
            </h2>
            <p class="mt-0.5 text-sm text-slate-500">SKU paket dipakai saat input atau impor pesanan, dan nanti dicocokkan dengan SKU di Shopee.</p>
        </div>
        <div class="grid gap-5 p-6 sm:grid-cols-2">
            <x-input name="sku" label="SKU Paket" tip="SKU yang dipakai di Shopee / TikTok untuk listing paket. Saat paket terjual, stok barang isinya yang berkurang, bukan stok paket." icon="heroicon-o-hashtag" :value="$bundle->sku" placeholder="cth. PAKET-KAOS-3"
                     hint="Huruf, angka, - dan _. Tidak boleh sama dengan SKU varian." class="uppercase" required />
            <x-input name="name" label="Nama Paket" :value="$bundle->name" placeholder="cth. Paket Hemat 3 Kaos" required />
            <x-input name="barcode" label="Barcode" tip="Kode yang bisa di-scan untuk memilih paket di form pesanan dan QC kemas." icon="heroicon-o-qr-code" :value="$bundle->barcode" hint="Opsional, untuk scan di form pesanan." />
            <x-input name="price" type="number" tip="Harga yang terisi otomatis saat paket dipilih di pesanan manual. Masih bisa diubah per pesanan." label="Harga Jual" :value="$bundle->price !== null ? (float) $bundle->price : ''" min="0" step="any"
                     hint="Harga default saat paket dipilih di pesanan." />
            <x-textarea name="description" label="Deskripsi" :value="$bundle->description" rows="2" class="sm:col-span-2" />
        </div>
    </div>

    <div class="card" x-data="{ items: @js($items->isEmpty() ? [$blank] : $items), variants: @js($variants), errors: @js($rowErrors) }">
        <div class="border-b border-slate-100 px-6 py-4">
            <h2 class="flex items-center gap-2 font-semibold text-slate-900">
                <x-heroicon-o-cube class="size-5 text-slate-400" /> Isi Paket
            </h2>
            <p class="mt-0.5 text-sm text-slate-500">
                Varian gudang dan jumlahnya untuk 1 paket. Stok paket dihitung dari isi ini.
                @if ($bundle->exists)
                    Perubahan isi hanya berlaku untuk pesanan baru.
                @endif
            </p>
        </div>

        @error('items')
            <p class="mx-6 mt-4 flex items-center gap-1 text-sm text-rose-600"><x-heroicon-o-exclamation-triangle class="size-4" /> {{ $message }}</p>
        @enderror

        <div class="overflow-x-auto p-6 pt-4">
            <table class="min-w-full text-sm">
                <thead class="text-left text-[13px] text-slate-500">
                    <tr>
                        <th class="py-2 pr-2 font-normal">Barang <span class="whitespace-nowrap">* <x-hint text="Varian (SKU) yang menjadi isi paket. Stok varian inilah yang dicadangkan dan dikurangi saat paket dipesan." /></span></th>
                        <th class="px-2 py-2 text-right font-normal">Jumlah per Paket <span class="whitespace-nowrap">* <x-hint text="Berapa pcs varian ini dalam satu paket. Contoh paket isi 3 kaos: isi 3." /></span></th>
                        <th class="py-2 pl-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(item, index) in items" :key="index">
                        <tr class="align-top">
                            <td class="w-full py-1.5 pr-2">
                                <select class="form-input min-w-72" :name="`items[${index}][product_variant_id]`" x-model="item.product_variant_id"
                                        x-tom-select="{ options: variants.map(v => ({ value: v.id, text: v.label })), value: item.product_variant_id }"
                                        data-placeholder="Cari SKU / nama barang"
                                        :class="errors[`items.${index}.product_variant_id`] && 'form-input-error'"></select>
                                <p class="mt-1 text-xs text-rose-600" x-show="errors[`items.${index}.product_variant_id`]" x-text="errors[`items.${index}.product_variant_id`]"></p>
                            </td>
                            <td class="px-2 py-1.5">
                                <input type="number" min="1" step="1" class="form-input w-28 text-right" :name="`items[${index}][qty]`" x-model="item.qty"
                                       :class="errors[`items.${index}.qty`] && 'form-input-error'">
                                <p class="mt-1 max-w-40 text-xs text-rose-600" x-show="errors[`items.${index}.qty`]" x-text="errors[`items.${index}.qty`]"></p>
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
            <button type="button" class="btn-secondary mt-3" @click="items.push({ ...@js($blank) })">
                <x-heroicon-o-plus class="size-5" /> Tambah Barang
            </button>
        </div>
    </div>

    <x-active-toggle :checked="$bundle->is_active" title="Status Paket" description="Paket nonaktif tidak bisa dipilih di pesanan baru." />
</div>
