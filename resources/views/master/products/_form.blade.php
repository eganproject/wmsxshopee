@php
    // 30.00 → 30, supaya rapi di kolom angka yang sempit.
    $decimal = fn ($value) => $value === null ? null : rtrim(rtrim((string) $value, '0'), '.');

    $variants = collect(old('variants', $product->variants->map(fn ($variant) => [
        'id' => $variant->id,
        'sku' => $variant->sku,
        'name' => $variant->name,
        'barcode' => $variant->barcode,
        'weight_gram' => $variant->weight_gram,
        'length_cm' => $decimal($variant->length_cm),
        'width_cm' => $decimal($variant->width_cm),
        'height_cm' => $decimal($variant->height_cm),
        'min_stock' => $variant->min_stock,
        'pcs_per_carton' => $variant->pcs_per_carton,
        'is_active' => $variant->is_active,
    ])->all()))
        ->map(fn ($row) => [
            'id' => $row['id'] ?? null,
            'sku' => $row['sku'] ?? '',
            'name' => $row['name'] ?? '',
            'barcode' => $row['barcode'] ?? '',
            'weight_gram' => $row['weight_gram'] ?? '',
            'length_cm' => $row['length_cm'] ?? '',
            'width_cm' => $row['width_cm'] ?? '',
            'height_cm' => $row['height_cm'] ?? '',
            'min_stock' => $row['min_stock'] ?? '',
            'pcs_per_carton' => $row['pcs_per_carton'] ?? '',
            'is_active' => (bool) ($row['is_active'] ?? true),
        ])
        ->values();

    if ($variants->isEmpty()) {
        $variants->push(['id' => null, 'sku' => '', 'name' => '', 'barcode' => '', 'weight_gram' => '', 'length_cm' => '', 'width_cm' => '', 'height_cm' => '', 'min_stock' => '', 'pcs_per_carton' => '', 'is_active' => true]);
    }

    $images = $product->images->map(fn ($image) => ['id' => $image->id, 'url' => $image->url()])->values();
    $primary = old('primary_image', (string) ($product->images->firstWhere('is_primary', true)?->id ?? ''));
    $removed = collect(old('remove_images', []))->map(fn ($id) => (int) $id)->values();
    $variantErrors = collect($errors->get('variants.*'))->flatten()->unique()->values();
@endphp

<div class="space-y-6">
    <div class="card">
        <div class="border-b border-slate-100 px-6 py-4">
            <h2 class="flex items-center gap-2 font-semibold text-slate-900">
                <x-heroicon-o-cube class="size-5 text-slate-400" /> Informasi Produk
            </h2>
        </div>
        <div class="grid gap-5 p-6 sm:grid-cols-2">
            <x-input name="sku" label="SKU Induk" tip="Kode unik produk di gudang. Untuk produk tanpa varian, SKU ini sekaligus SKU stok yang dicocokkan dengan SKU di Shopee / TikTok." icon="heroicon-o-hashtag" :value="$product->sku" placeholder="cth. KAOS-POLOS"
                     hint="SKU internal. Huruf, angka, - dan _. Otomatis huruf kapital." class="uppercase" required />
            <x-input name="name" label="Nama Produk" :value="$product->name" placeholder="cth. Kaos Polos Cotton Combed 30s" required />
            <x-input name="category" label="Kategori" tip="Pengelompokan bebas untuk filter dan laporan, misalnya Pakaian atau Aksesoris." icon="heroicon-o-tag" :value="$product->category" list="product-categories" placeholder="cth. Pakaian" />
            <datalist id="product-categories">
                @foreach ($categories as $category)
                    <option value="{{ $category }}"></option>
                @endforeach
            </datalist>
            <x-input name="brand" label="Merek" :value="$product->brand" placeholder="opsional" />
            <x-select name="unit_id" label="Satuan" tip="Satuan hitung stok yang tampil di samping angka, misalnya PCS." required>
                <option value="">Pilih satuan</option>
                @foreach ($units as $unit)
                    <option value="{{ $unit->id }}" @selected((string) old('unit_id', $product->unit_id) === (string) $unit->id)>{{ $unit->code }} · {{ $unit->name }}</option>
                @endforeach
            </x-select>
            <x-textarea name="description" label="Deskripsi" :value="$product->description" rows="4" class="sm:col-span-2" />
        </div>
    </div>

    {{-- Gambar --}}
    <div class="card"
         x-data="{
             images: @js($images),
             removed: @js($removed),
             primary: @js($primary),
             uploads: [],
             max: {{ $maxImages }},
             pick(event) {
                 this.uploads.forEach((upload) => URL.revokeObjectURL(upload.url));
                 this.uploads = Array.from(event.target.files).map((file) => ({ name: file.name, url: URL.createObjectURL(file) }));
                 if (this.primary.startsWith('new-') && ! this.uploads[this.primary.slice(4)]) this.primary = '';
             },
             get count() { return this.images.filter((image) => ! this.removed.includes(image.id)).length + this.uploads.length },
         }">
        <div class="border-b border-slate-100 px-6 py-4">
            <h2 class="flex items-center gap-2 font-semibold text-slate-900">
                <x-heroicon-o-photo class="size-5 text-slate-400" /> Gambar Produk
            </h2>
            <p class="mt-0.5 text-sm text-slate-500">JPG, PNG atau WEBP, maks. 2 MB per gambar, maks. {{ $maxImages }} gambar. Pilih satu sebagai gambar utama.</p>
        </div>
        <div class="space-y-4 p-6">
            @foreach (collect($errors->get('images'))->merge($errors->get('images.*'))->flatten()->unique() as $message)
                <p class="flex items-center gap-1 text-sm text-rose-600"><x-heroicon-o-exclamation-triangle class="size-4" /> {{ $message }}</p>
            @endforeach

            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-5">
                <template x-for="image in images" :key="image.id">
                    <div class="group relative overflow-hidden rounded-xl ring-1 ring-slate-200"
                         :class="{ 'opacity-40': removed.includes(image.id), 'ring-2 ring-brand-500': primary == image.id }">
                        <img :src="image.url" alt="" class="aspect-square w-full object-cover">
                        <div class="flex items-center justify-between gap-1 bg-white px-2 py-1.5 text-xs">
                            <label class="flex cursor-pointer items-center gap-1 text-slate-600" x-show="! removed.includes(image.id)">
                                <input type="radio" name="primary_image" :value="image.id" x-model="primary"> Utama
                            </label>
                            <span x-show="removed.includes(image.id)" class="text-rose-600">Akan dihapus</span>
                            <button type="button" class="btn-icon p-1" :title="removed.includes(image.id) ? 'Batal hapus' : 'Hapus gambar'"
                                    @click="removed.includes(image.id) ? removed.splice(removed.indexOf(image.id), 1) : removed.push(image.id); if (primary == image.id) primary = ''">
                                <x-heroicon-o-trash class="size-4" x-show="! removed.includes(image.id)" />
                                <x-heroicon-o-arrow-uturn-left class="size-4" x-show="removed.includes(image.id)" />
                            </button>
                        </div>
                    </div>
                </template>

                <template x-for="(upload, index) in uploads" :key="upload.url">
                    <div class="relative overflow-hidden rounded-xl ring-1 ring-slate-200" :class="{ 'ring-2 ring-brand-500': primary === `new-${index}` }">
                        <img :src="upload.url" :alt="upload.name" class="aspect-square w-full object-cover">
                        <span class="absolute top-2 left-2 rounded-full bg-emerald-500 px-2 py-0.5 text-[10px] font-semibold text-white">Baru</span>
                        <div class="bg-white px-2 py-1.5 text-xs">
                            <label class="flex cursor-pointer items-center gap-1 text-slate-600">
                                <input type="radio" name="primary_image" :value="`new-${index}`" x-model="primary"> Utama
                            </label>
                        </div>
                    </div>
                </template>

                <label class="flex aspect-square cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-slate-300 text-slate-500 transition hover:border-brand-400 hover:text-brand-600">
                    <x-heroicon-o-arrow-up-tray class="size-6" />
                    <span class="text-xs font-medium" x-text="uploads.length ? 'Ganti pilihan' : 'Unggah gambar'"></span>
                    <input type="file" name="images[]" accept="image/jpeg,image/png,image/webp" multiple class="sr-only" @change="pick($event)">
                </label>
            </div>

            <template x-for="id in removed" :key="id">
                <input type="hidden" name="remove_images[]" :value="id">
            </template>
            <p class="text-xs" :class="count > max ? 'text-rose-600' : 'text-slate-500'" x-text="`${count} dari ${max} gambar`"></p>
        </div>
    </div>

    {{-- Varian, barcode, berat & dimensi --}}
    <div class="card"
         x-data="{
             hasVariants: @js((bool) old('has_variants', $product->has_variants)),
             variants: @js($variants),
             blank() { return { id: null, sku: '', name: '', barcode: '', weight_gram: '', length_cm: '', width_cm: '', height_cm: '', min_stock: '', pcs_per_carton: '', is_active: true } },
         }">
        <div class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-100 px-6 py-4">
            <div>
                <h2 class="flex items-center gap-2 font-semibold text-slate-900">
                    <x-heroicon-o-rectangle-stack class="size-5 text-slate-400" /> Varian, Barcode &amp; Dimensi
                </h2>
                <p class="mt-0.5 text-sm text-slate-500">Berat dan dimensi dipakai untuk ongkir dan sinkron ke Shopee; stok minimum memicu peringatan di laporan.</p>
            </div>
            <label class="flex cursor-pointer items-center gap-2 text-sm font-medium text-slate-700">
                <input type="hidden" name="has_variants" value="0">
                <input type="checkbox" name="has_variants" value="1" class="size-4 rounded border-slate-300 text-brand-600" x-model="hasVariants">
                Produk ini punya varian
            </label>
        </div>

        <div class="space-y-4 p-6">
            @error('variants')
                <p class="flex items-center gap-1 text-sm text-rose-600"><x-heroicon-o-exclamation-triangle class="size-4" /> {{ $message }}</p>
            @enderror
            @foreach ($variantErrors as $message)
                <p class="flex items-center gap-1 text-sm text-rose-600"><x-heroicon-o-exclamation-triangle class="size-4" /> {{ $message }}</p>
            @endforeach

            {{-- Tanpa varian: satu varian default, SKU mengikuti SKU induk --}}
            <template x-if="! hasVariants">
                <div class="grid gap-5 sm:grid-cols-4">
                    <input type="hidden" name="variants[0][id]" :value="variants[0].id ?? ''">
                    <div class="sm:col-span-2">
                        <label class="form-label" for="variant-barcode"><span class="whitespace-nowrap">Barcode <x-hint title="Barcode" text="Kode yang tertera di kemasan (EAN / UPC) atau kode internal. Bisa di-scan menggantikan SKU saat QC kemas, penerimaan dan transfer." /></span></label>
                        <input id="variant-barcode" type="text" class="form-input" name="variants[0][barcode]" x-model="variants[0].barcode" placeholder="EAN / UPC / kode internal">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="form-label" for="variant-weight">Berat <span class="whitespace-nowrap">(gram) <x-hint text="Berat satu pcs termasuk kemasan, dalam gram. Dipakai untuk perkiraan ongkir dan sinkron ke Shopee." /></span></label>
                        <input id="variant-weight" type="number" min="0" class="form-input" name="variants[0][weight_gram]" x-model="variants[0].weight_gram">
                    </div>
                    <div>
                        <label class="form-label" for="variant-length">Panjang <span class="whitespace-nowrap">(cm) <x-hint text="Dimensi paket satu pcs. Dipakai Shopee untuk menghitung berat volume." /></span></label>
                        <input id="variant-length" type="number" min="0" step="0.01" class="form-input" name="variants[0][length_cm]" x-model="variants[0].length_cm">
                    </div>
                    <div>
                        <label class="form-label" for="variant-width">Lebar (cm)</label>
                        <input id="variant-width" type="number" min="0" step="0.01" class="form-input" name="variants[0][width_cm]" x-model="variants[0].width_cm">
                    </div>
                    <div>
                        <label class="form-label" for="variant-height">Tinggi (cm)</label>
                        <input id="variant-height" type="number" min="0" step="0.01" class="form-input" name="variants[0][height_cm]" x-model="variants[0].height_cm">
                    </div>
                    <div>
                        <label class="form-label" for="variant-min-stock">Stok <span class="whitespace-nowrap">Minimum <x-hint title="Stok Minimum" text="Batas peringatan stok menipis. Bila stok tersedia sama atau di bawah angka ini, SKU muncul di laporan Stok Minimum dan notifikasi. Kosongkan bila tidak perlu." /></span></label>
                        <input id="variant-min-stock" type="number" min="0" class="form-input" name="variants[0][min_stock]" x-model="variants[0].min_stock" placeholder="Kosong = tanpa peringatan">
                    </div>
                    <div>
                        <label class="form-label" for="variant-carton">Isi per Koli <span class="whitespace-nowrap">(pcs) <x-hint title="Isi per Koli" text="Jumlah pcs dalam satu koli / karton. Saran transfer dari gudang besar dibulatkan ke atas per koli. Kosongkan bila barang tidak dikirim per koli." /></span></label>
                        <input id="variant-carton" type="number" min="1" class="form-input" name="variants[0][pcs_per_carton]" x-model="variants[0].pcs_per_carton" placeholder="Kosong = tidak dijual per koli">
                    </div>
                </div>
            </template>

            {{-- Dengan varian: tiap varian punya SKU sendiri --}}
            <template x-if="hasVariants">
                <div class="space-y-3">
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="text-left text-[13px] text-slate-500">
                                <tr>
                                    <th class="py-2 pr-2">Nama Varian *</th>
                                    <th class="px-2 py-2">SKU Varian <span class="whitespace-nowrap">* <x-hint text="Kode unik tiap varian. Stok dicatat per SKU varian, dan SKU ini yang dicocokkan dengan SKU di Shopee / TikTok." /></span></th>
                                    <th class="px-2 py-2"><span class="whitespace-nowrap">Barcode <x-hint title="Barcode" text="Kode yang tertera di kemasan (EAN / UPC) atau kode internal. Bisa di-scan menggantikan SKU saat QC kemas, penerimaan dan transfer." /></span></th>
                                    <th class="px-2 py-2">Berat <span class="whitespace-nowrap">(g) <x-hint text="Berat satu pcs termasuk kemasan, dalam gram. Dipakai untuk perkiraan ongkir dan sinkron ke Shopee." /></span></th>
                                    <th class="px-2 py-2">P × L × T <span class="whitespace-nowrap">(cm) <x-hint text="Panjang × lebar × tinggi paket satu pcs, dalam cm." /></span></th>
                                    <th class="px-2 py-2">Stok <span class="whitespace-nowrap">Min. <x-hint title="Stok Minimum" text="Batas peringatan stok menipis. Bila stok tersedia sama atau di bawah angka ini, SKU muncul di laporan Stok Minimum dan notifikasi. Kosongkan bila tidak perlu." /></span></th>
                                    <th class="px-2 py-2"><span class="whitespace-nowrap">Isi/Koli <x-hint title="Isi per Koli" text="Jumlah pcs dalam satu koli / karton. Saran transfer dari gudang besar dibulatkan ke atas per koli. Kosongkan bila barang tidak dikirim per koli." /></span></th>
                                    <th class="px-2 py-2 text-center"><span class="whitespace-nowrap">Aktif <x-hint text="Varian nonaktif tidak bisa dipilih di transaksi baru, tapi riwayat stoknya tetap tersimpan." /></span></th>
                                    <th class="py-2 pl-2"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="(variant, index) in variants" :key="index">
                                    <tr class="align-top">
                                        <td class="py-1.5 pr-2">
                                            <input type="hidden" :name="`variants[${index}][id]`" :value="variant.id ?? ''">
                                            <input type="text" class="form-input min-w-36" placeholder="cth. Merah / XL" :name="`variants[${index}][name]`" x-model="variant.name">
                                        </td>
                                        <td class="px-2 py-1.5"><input type="text" class="form-input min-w-36 uppercase" placeholder="cth. KAOS-MRH-XL" :name="`variants[${index}][sku]`" x-model="variant.sku"></td>
                                        <td class="px-2 py-1.5"><input type="text" class="form-input min-w-32" :name="`variants[${index}][barcode]`" x-model="variant.barcode"></td>
                                        <td class="px-2 py-1.5"><input type="number" min="0" class="form-input w-24" :name="`variants[${index}][weight_gram]`" x-model="variant.weight_gram"></td>
                                        <td class="px-2 py-1.5">
                                            <div class="flex gap-1">
                                                <input type="number" min="0" step="0.01" class="form-input w-20" placeholder="P" :name="`variants[${index}][length_cm]`" x-model="variant.length_cm">
                                                <input type="number" min="0" step="0.01" class="form-input w-20" placeholder="L" :name="`variants[${index}][width_cm]`" x-model="variant.width_cm">
                                                <input type="number" min="0" step="0.01" class="form-input w-20" placeholder="T" :name="`variants[${index}][height_cm]`" x-model="variant.height_cm">
                                            </div>
                                        </td>
                                        <td class="px-2 py-1.5"><input type="number" min="0" class="form-input w-24" :name="`variants[${index}][min_stock]`" x-model="variant.min_stock"></td>
                                        <td class="px-2 py-1.5"><input type="number" min="1" class="form-input w-24" :name="`variants[${index}][pcs_per_carton]`" x-model="variant.pcs_per_carton"></td>
                                        <td class="px-2 py-1.5 text-center">
                                            <input type="hidden" :name="`variants[${index}][is_active]`" :value="variant.is_active ? 1 : 0">
                                            <input type="checkbox" class="mt-2.5 size-4 rounded border-slate-300 text-brand-600" x-model="variant.is_active">
                                        </td>
                                        <td class="py-1.5 pl-2">
                                            <button type="button" class="btn-icon hover:bg-rose-50 hover:text-rose-600" title="Hapus varian"
                                                    x-show="variants.length > 1" @click="variants.splice(index, 1)">
                                                <x-heroicon-o-trash class="size-5" />
                                            </button>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                    <button type="button" class="btn-secondary" @click="variants.push(blank())">
                        <x-heroicon-o-plus class="size-5" /> Tambah Varian
                    </button>
                </div>
            </template>

            <p x-show="! hasVariants && variants.length > 1" class="text-xs text-amber-600">
                Hanya varian pertama yang disimpan bila produk dibuat tanpa varian.
            </p>
        </div>
    </div>

    <x-active-toggle :checked="$product->is_active" title="Status Produk" description="Produk nonaktif tidak bisa dipakai untuk transaksi baru." />

    <div class="card p-6">
        <x-textarea name="notes" label="Catatan" :value="$product->notes" />
    </div>
</div>
