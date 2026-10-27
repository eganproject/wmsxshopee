@php
    // Satu baris = barang biasa (product_variant_id) atau produk paket (bundle_id);
    // pilihan di form memakai "ref" gabungan: v:<id varian> atau b:<id paket>.
    $blank = ['product_variant_id' => '', 'bundle_id' => '', 'qty' => '', 'price' => '', 'notes' => ''];
    $existing = $order->relationLoaded('looseItems')
        ? $order->looseItems->map(fn ($item) => [
            'product_variant_id' => (string) $item->product_variant_id,
            'qty' => $item->qty,
            'price' => (float) $item->price ?: '',
            'notes' => $item->notes ?? '',
        ])->concat($order->bundles->map(fn ($line) => [
            'bundle_id' => (string) $line->bundle_id,
            'qty' => $line->qty,
            'price' => (float) $line->price ?: '',
            'notes' => $line->notes ?? '',
        ]))
        : collect();
    $items = collect(old('items', $existing->all()))
        ->map(fn ($row) => [...$blank, ...$row])
        ->map(fn ($row) => [...$row, 'ref' => filled($row['bundle_id']) ? "b:{$row['bundle_id']}" : (filled($row['product_variant_id']) ? "v:{$row['product_variant_id']}" : '')])
        ->values();
    $blank['ref'] = '';
    $rowErrors = collect($errors->getMessages())->filter(fn ($messages, $key) => str_starts_with($key, 'items.'))->map(fn ($messages) => $messages[0]);
    $defaultWarehouse = $order->warehouse_id ?? ($warehouses->count() === 1 ? $warehouses->first()->id : '');
@endphp

<div class="space-y-6"
     x-data="{
         items: @js($items->isEmpty() ? [$blank] : $items),
         variants: @js($variants),
         errors: @js($rowErrors),
         shippingFee: @js((string) old('shipping_fee', (float) $order->shipping_fee ?: '')),
         discount: @js((string) old('discount', (float) $order->discount ?: '')),
         delivery: @js(old('delivery_type', $order->delivery_type ?? 'courier')),
         scan: '',
         scanError: '',
         get variantOptions() {
             return this.variants.map(v => ({ value: v.ref, text: v.label }));
         },
         choose(item) {
             const option = this.variants.find(v => v.ref === item.ref);
             if (option && option.price && ! item.price) item.price = option.price;
         },
         idOf(item, kind) {
             return item.ref && item.ref.startsWith(kind + ':') ? item.ref.slice(2) : '';
         },
         lineTotal(item) {
             return (parseInt(item.qty) || 0) * (parseFloat(item.price) || 0);
         },
         totals() {
             const qty = this.items.reduce((total, item) => total + (parseInt(item.qty) || 0), 0);
             const subtotal = this.items.reduce((total, item) => total + this.lineTotal(item), 0);
             const discount = parseFloat(this.discount) || 0;
             const shipping = this.delivery === 'courier' ? (parseFloat(this.shippingFee) || 0) : 0;
             return { qty, subtotal, discount, shipping, grand: Math.max(0, subtotal - discount) + shipping };
         },
         money(value) {
             return new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 }).format(value);
         },
         addScanned() {
             const code = this.scan.trim().toUpperCase();
             if (! code) return;
             const variant = this.variants.find(v => v.sku === code || (v.barcode && v.barcode.toUpperCase() === code));
             if (! variant) { this.scanError = `SKU / barcode ${code} tidak ditemukan.`; return; }
             this.scanError = '';
             this.scan = '';
             const existing = this.items.find(i => i.ref === variant.ref);
             if (existing) { existing.qty = (parseInt(existing.qty) || 0) + 1; return; }
             const empty = this.items.find(i => ! i.ref);
             if (empty) { empty.ref = variant.ref; empty.qty = empty.qty || 1; this.choose(empty); return; }
             const row = { ...@js($blank), ref: variant.ref, qty: 1 };
             this.choose(row);
             this.items.push(row);
         },
     }">
    <div class="card">
        <div class="border-b border-slate-100 px-5 py-3">
            <h2 class="flex items-center gap-2 text-base font-semibold text-slate-900">
                <x-heroicon-o-document-text class="size-5 text-slate-400" /> Informasi Pesanan
            </h2>
            <p class="mt-0.5 text-sm text-slate-500">
                @if ($order->number)
                    Nomor <span class="font-mono font-medium text-slate-700">{{ $order->number }}</span>
                @else
                    Nomor dibuat otomatis saat disimpan, cth. {{ \App\Models\SalesOrder::PREFIX.now()->format('ym') }}-001.
                @endif
            </p>
        </div>
        <div class="grid gap-x-6 gap-y-4 p-5 sm:grid-cols-3">
            <x-input name="order_date" type="date" tip="Tanggal pesanan diterima dari pembeli." label="Tanggal Pesanan" :value="$order->order_date?->toDateString()" :max="now()->toDateString()" required />
            <x-select name="warehouse_id" label="Gudang Pengirim" tip="Gudang yang menyiapkan dan mengirim pesanan. Stok gudang ini yang dicadangkan saat pesanan dikonfirmasi." required>
                <option value="">Pilih gudang</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}" @selected((string) old('warehouse_id', $defaultWarehouse) === (string) $warehouse->id)>{{ $warehouse->code }} · {{ $warehouse->name }}</option>
                @endforeach
            </x-select>
            @php($channel = old('channel_name', $order->channel_name))
            <x-select name="channel_name" label="Saluran Penjualan" tip="Asal pesanan, misalnya WhatsApp atau Toko Offline. Dipakai untuk filter dan laporan penjualan." data-create data-placeholder="Pilih atau ketik baru"
                      hint="cth. WhatsApp, Toko Offline. Pesanan Shopee masuk otomatis dari API.">
                <option value="">Tanpa saluran</option>
                @foreach ($channels->concat(['WhatsApp', 'Instagram', 'TikTok', 'Toko Offline', 'Reseller'])->push($channel)->filter()->reject(fn ($name) => $name === 'Lainnya')->unique()->sort()->push('Lainnya') as $name)
                    <option value="{{ $name }}" @selected($channel === $name)>{{ $name }}</option>
                @endforeach
            </x-select>
            <x-input name="customer_name" label="Nama Pembeli" :value="$order->customer_name" required maxlength="150" />
            <x-input name="customer_phone" label="Telepon / WhatsApp" :value="$order->customer_phone" maxlength="30" placeholder="08xx" />
            <x-input name="external_number" label="No. Referensi" tip="Nomor nota / invoice dari luar Optima. Sistem menolak nomor yang sama di saluran penjualan yang sama, jadi pesanan tidak tercatat dua kali." :value="$order->external_number" placeholder="cth. INV-0012" maxlength="100"
                     hint="Opsional: no. nota, invoice atau pesanan marketplace. Mencegah input ganda." />
            <x-textarea name="notes" label="Catatan" :value="$order->notes" rows="2" class="sm:col-span-3" />
        </div>
    </div>

    <div class="card">
        <div class="border-b border-slate-100 px-5 py-3">
            <h2 class="flex items-center gap-2 text-base font-semibold text-slate-900">
                <x-heroicon-o-truck class="size-5 text-slate-400" /> Pengiriman
            </h2>
            <p class="mt-0.5 text-sm text-slate-500">Pilih Diambil Pembeli untuk penjualan di tempat; tidak perlu kurir, resi maupun serah terima.</p>
        </div>
        <div class="space-y-4 p-5">
            <div class="grid gap-3 sm:grid-cols-2">
                @foreach (\App\Models\SalesOrder::DELIVERY_TYPES as $value => $type)
                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border px-4 py-3 transition"
                           :class="delivery === @js($value) ? 'border-brand-500 bg-brand-50/60 ring-1 ring-brand-500' : 'border-slate-200 hover:border-slate-300'">
                        <input type="radio" name="delivery_type" value="{{ $value }}" x-model="delivery" class="mt-1 text-brand-600 focus:ring-brand-500">
                        <span>
                            <span class="flex items-center gap-1.5 text-sm font-medium text-slate-900">
                                <x-dynamic-component :component="$type['icon']" class="size-4 text-slate-400" /> {{ $type['label'] }}
                            </span>
                            <span class="block text-xs text-slate-500">{{ $type['description'] }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
            @error('delivery_type')
                <p class="text-xs text-rose-600">{{ $message }}</p>
            @enderror
            <div class="grid gap-x-6 gap-y-4 sm:grid-cols-3" x-show="delivery === 'courier'" x-cloak>
                <x-textarea name="shipping_address" label="Alamat Kirim" :value="$order->shipping_address" rows="2" class="sm:col-span-3" />
                <x-select name="courier_id" label="Kurir" tip="Jasa kirim yang membawa paket. Daftar kurir diatur di Master Data → Kurir.">
                    <option value="">Belum ditentukan</option>
                    @foreach ($couriers as $courier)
                        <option value="{{ $courier->id }}" @selected((string) old('courier_id', $order->courier_id) === (string) $courier->id)>{{ $courier->name }}</option>
                    @endforeach
                </x-select>
                <x-input name="courier_service" label="Layanan" tip="Jenis layanan kurir, misalnya REG atau YES." :value="$order->courier_service" placeholder="cth. REG" maxlength="50" />
                <div>
                    <label for="shipping_fee" class="form-label"><span class="whitespace-nowrap">Ongkir <x-hint text="Ongkos kirim yang dibayar pembeli. Ikut dihitung di total pesanan." /></span></label>
                    <input id="shipping_fee" name="shipping_fee" type="number" min="0" step="any" x-model="shippingFee" placeholder="0"
                           @class(['form-input text-right', 'form-input-error' => $errors->has('shipping_fee')])>
                    @error('shipping_fee')
                        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="border-b border-slate-100 px-5 py-3">
            <h2 class="flex items-center gap-2 text-base font-semibold text-slate-900">
                <x-heroicon-o-banknotes class="size-5 text-slate-400" /> Pembayaran
            </h2>
            <p class="mt-0.5 text-sm text-slate-500">Catatan pembayaran dari pembeli. Tidak memengaruhi stok.</p>
        </div>
        <div class="grid gap-x-6 gap-y-4 p-5 sm:grid-cols-3">
            <x-select name="payment_method" label="Metode Bayar">
                <option value="">Belum ditentukan</option>
                @foreach (\App\Models\SalesOrder::PAYMENT_METHODS as $value => $label)
                    <option value="{{ $value }}" @selected(old('payment_method', $order->payment_method) === $value)>{{ $label }}</option>
                @endforeach
            </x-select>
            <x-select name="payment_status" label="Status Bayar" tip="Catatan apakah pembeli sudah lunas. Hanya informasi, tidak memengaruhi stok.">
                <option value="">Belum dicatat</option>
                @foreach (\App\Models\SalesOrder::PAYMENT_STATUSES as $value => $status)
                    <option value="{{ $value }}" @selected(old('payment_status', $order->payment_status) === $value)>{{ $status['label'] }}</option>
                @endforeach
            </x-select>
            <div>
                <label for="discount" class="form-label">Diskon <span class="whitespace-nowrap">(Rp) <x-hint text="Potongan harga untuk seluruh pesanan, mengurangi total." /></span></label>
                <input id="discount" name="discount" type="number" min="0" step="any" x-model="discount" placeholder="0"
                       @class(['form-input text-right', 'form-input-error' => $errors->has('discount')])>
                @error('discount')
                    <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                @enderror
            </div>
        </div>
    </div>

    <div class="card">
        <div class="flex flex-col gap-3 border-b border-slate-100 px-5 py-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="flex items-center gap-2 text-base font-semibold text-slate-900">
                    <x-heroicon-o-cube class="size-5 text-slate-400" /> Barang Dipesan
                </h2>
                <p class="mt-0.5 text-sm text-slate-500">Scan SKU / barcode: barang baru menambah baris, barang yang sama menambah jumlah. Produk paket otomatis dipecah jadi isinya saat disimpan.</p>
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

        <div class="overflow-x-auto p-5 pt-4">
            <table class="min-w-full text-sm">
                <thead class="text-left text-[13px] text-slate-500">
                    <tr>
                        <th class="py-2 pr-2 font-normal">Barang *</th>
                        <th class="px-2 py-2 text-right font-normal">Jumlah <span class="whitespace-nowrap">* <x-hint text="Jumlah yang dipesan. Stok dicadangkan sejumlah ini saat pesanan dikonfirmasi." /></span></th>
                        <th class="px-2 py-2 text-right font-normal">Harga <span class="whitespace-nowrap">Satuan <x-hint text="Harga jual per pcs / paket. Untuk produk paket terisi otomatis dari Harga Jual paket." /></span></th>
                        <th class="px-2 py-2 text-right font-normal">Subtotal</th>
                        <th class="px-2 py-2 font-normal">Keterangan</th>
                        <th class="py-2 pl-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(item, index) in items" :key="index">
                        <tr class="align-top">
                            <td class="py-1.5 pr-2">
                                <select class="form-input min-w-72" x-model="item.ref" @change="choose(item)"
                                        x-tom-select="{ options: variantOptions, value: item.ref }" data-placeholder="Cari SKU / nama barang / paket"
                                        :class="(errors[`items.${index}.product_variant_id`] || errors[`items.${index}.bundle_id`]) && 'form-input-error'"></select>
                                <input type="hidden" :name="`items[${index}][product_variant_id]`" :value="idOf(item, 'v')">
                                <input type="hidden" :name="`items[${index}][bundle_id]`" :value="idOf(item, 'b')">
                                <p class="mt-1 text-xs text-rose-600" x-show="errors[`items.${index}.product_variant_id`] || errors[`items.${index}.bundle_id`]"
                                   x-text="errors[`items.${index}.product_variant_id`] || errors[`items.${index}.bundle_id`]"></p>
                            </td>
                            <td class="px-2 py-1.5">
                                <input type="number" min="1" step="1" class="form-input w-24 text-right" :name="`items[${index}][qty]`" x-model="item.qty"
                                       :class="errors[`items.${index}.qty`] && 'form-input-error'">
                                <p class="mt-1 max-w-40 text-xs text-rose-600" x-show="errors[`items.${index}.qty`]" x-text="errors[`items.${index}.qty`]"></p>
                            </td>
                            <td class="px-2 py-1.5">
                                <input type="number" min="0" step="any" class="form-input w-32 text-right" :name="`items[${index}][price]`" x-model="item.price" placeholder="0"
                                       :class="errors[`items.${index}.price`] && 'form-input-error'">
                            </td>
                            <td class="px-2 py-1.5 text-right">
                                <span class="inline-block pt-1.5 tabular-nums text-slate-700" x-text="money(lineTotal(item))"></span>
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
                        <td class="py-2 pr-2 text-right">Total qty</td>
                        <td class="px-2 py-2 text-right font-medium tabular-nums text-slate-700" x-text="totals().qty"></td>
                        <td class="px-2 py-2 text-right">Subtotal</td>
                        <td class="px-2 py-2 text-right font-medium tabular-nums text-slate-700" x-text="money(totals().subtotal)"></td>
                        <td colspan="2"></td>
                    </tr>
                    <tr x-show="totals().discount > 0" x-cloak>
                        <td colspan="3" class="px-2 py-1 text-right">Diskon</td>
                        <td class="px-2 py-1 text-right tabular-nums text-rose-600" x-text="'-' + money(totals().discount)"></td>
                        <td colspan="2"></td>
                    </tr>
                    <tr x-show="totals().shipping > 0" x-cloak>
                        <td colspan="3" class="px-2 py-1 text-right">Ongkir</td>
                        <td class="px-2 py-1 text-right tabular-nums text-slate-700" x-text="money(totals().shipping)"></td>
                        <td colspan="2"></td>
                    </tr>
                    <tr>
                        <td colspan="3" class="px-2 py-1 text-right">Total</td>
                        <td class="px-2 py-1 text-right font-semibold tabular-nums text-slate-900" x-text="money(totals().grand)"></td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
            <button type="button" class="btn-secondary mt-3" @click="items.push({ ...@js($blank) })">
                <x-heroicon-o-plus class="size-5" /> Tambah Baris
            </button>
        </div>
    </div>
</div>
