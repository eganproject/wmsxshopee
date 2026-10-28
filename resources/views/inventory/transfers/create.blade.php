@php
    $blank = ['product_variant_id' => '', 'from_location_id' => '', 'to_location_id' => '', 'qty' => '', 'notes' => ''];
    $items = collect(old('items', $prefill))->map(fn ($row) => [...$blank, ...$row])->values();
    $rowErrors = collect($errors->getMessages())->filter(fn ($messages, $key) => str_starts_with($key, 'items.'))->map(fn ($messages) => $messages[0]);
@endphp

<x-layouts.app title="Buat Transfer Antar Gudang">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('inventory.transfers.index') }}" class="hover:text-slate-700">Transfer Antar Gudang</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Buat Transfer</span>
    </x-slot:breadcrumb>

    <x-page-header title="Buat Transfer Antar Gudang" description="Stok keluar dari gudang asal saat disimpan dan masuk ke gudang tujuan setelah diterima." icon="heroicon-o-truck">
        <a href="{{ route('inventory.transfers.index') }}" class="btn-secondary"><x-heroicon-o-arrow-left class="size-5" /> Kembali</a>
    </x-page-header>

    @if ($heldOrder ?? null)
        <div class="mb-6 flex items-start gap-2 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800">
            <x-heroicon-o-information-circle class="mt-0.5 size-5 shrink-0 text-sky-500" />
            <p>Diisi dari kekurangan pesanan <a href="{{ route('sales.orders.show', $heldOrder) }}" class="font-medium underline underline-offset-2">{{ $heldOrder->number }}</a>. Pesanan dikonfirmasi otomatis begitu stoknya cukup: saat transfer dikirim bila asalnya gudang cadangan gudang tujuan, atau saat transfer diterima.</p>
        </div>
    @endif

    <form method="POST" action="{{ route('inventory.transfers.store') }}" data-dirty-guard class="space-y-6"
          x-data="{
              from: @js((string) old('from_warehouse_id', $from ?? '')),
              to: @js((string) old('to_warehouse_id', $to ?? '')),
              items: @js($items->isEmpty() ? [$blank] : $items),
              variants: @js($variants),
              locations: @js($locations),
              defaults: @js($defaults),
              errors: @js($rowErrors),
              scan: '',
              scanError: '',
              splitNote: '',
              blank() { return @js($blank) },
              variant(id) { return this.variants.find(v => String(v.id) === String(id)); },
              per(item) { return this.variant(item.product_variant_id)?.per || null; },
              cartonText(item) {
                  const per = this.per(item), qty = parseInt(item.qty) || 0;
                  if (! per || ! qty) return '';
                  const koli = Math.floor(qty / per), pcs = qty % per;
                  return [koli ? `${koli} koli` : '', pcs ? `${pcs} pcs` : ''].filter(Boolean).join(' + ') + ` (isi ${per}/koli)`;
              },
              addCarton(item, n) { const per = this.per(item); if (per) { item.qty = Math.max(0, (parseInt(item.qty) || 0) + n * per) || ''; this.spread(item); } },
              locationOptions(warehouse, activeOnly) {
                  return this.locations.filter(l => String(l.warehouse_id) === String(warehouse) && (! activeOnly || l.active)).map(l => ({ value: l.id, text: l.code }));
              },
              get variantOptions() { return this.variants.map(v => ({ value: v.id, text: v.label })); },
              fixedBins(item) { return window.Optima.fixedBinsFor(this.defaults, this.to, item.product_variant_id); },
              fillDefault(item) {
                  if (! this.to) return;
                  const bins = this.fixedBins(item);
                  if (! item.to_location_id && bins.length) item.to_location_id = bins[0].id;
                  this.spread(item);
              },
              spread(item) {
                  // Bin utama di gudang tujuan penuh (sesuai kapasitas): kelebihan dipindah ke bin cadangan.
                  if (! item.product_variant_id || ! this.to) return;
                  const bins = this.fixedBins(item);
                  if (bins.length < 2) return;
                  const changed = window.Optima.spreadToFixedBins(this.items, item, {
                      bins,
                      qtyKey: 'qty',
                      locKey: 'to_location_id',
                      same: r => String(r.product_variant_id) === String(item.product_variant_id) && String(r.from_location_id) === String(item.from_location_id),
                      make: (loc, qty) => ({ ...this.blank(), product_variant_id: String(item.product_variant_id), from_location_id: item.from_location_id, to_location_id: loc, qty, notes: item.notes }),
                  });
                  if (changed) this.splitNote = 'Bin utama di gudang tujuan penuh, sisa barang disarankan ke bin cadangan (lihat Inventori > Lokasi Tetap SKU).';
              },
              addScanned() {
                  const code = this.scan.trim().toUpperCase();
                  if (! code) return;
                  const variant = this.variants.find(v => v.sku === code || (v.barcode && v.barcode.toUpperCase() === code));
                  if (! variant) { this.scanError = `SKU / barcode ${code} tidak ditemukan.`; return; }
                  this.scanError = '';
                  let row = this.items.find(i => ! i.product_variant_id);
                  if (row) { row.product_variant_id = String(variant.id); } else { row = { ...this.blank(), product_variant_id: String(variant.id) }; this.items.push(row); }
                  this.fillDefault(row);
                  this.scan = '';
              },
          }">
        @csrf

        <div class="card">
            <div class="border-b border-slate-100 px-6 py-4">
                <h2 class="flex items-center gap-2 font-semibold text-slate-900"><x-heroicon-o-document-text class="size-5 text-slate-400" /> Informasi Transfer</h2>
                <p class="mt-0.5 text-sm text-slate-500">Nomor transfer dibuat otomatis saat disimpan.</p>
            </div>
            <div class="grid gap-5 p-6 sm:grid-cols-2 lg:grid-cols-4">
                <x-select name="from_warehouse_id" label="Dari Gudang" tip="Gudang pengirim, biasanya gudang besar (cadangan). Stoknya langsung berkurang saat transfer disimpan." required x-model="from">
                    <option value="">Pilih gudang asal</option>
                    @foreach ($sources as $warehouse)
                        <option value="{{ $warehouse->id }}">{{ $warehouse->code }} · {{ $warehouse->name }}</option>
                    @endforeach
                </x-select>
                <x-select name="to_warehouse_id" label="Ke Gudang" tip="Gudang penerima. Stok baru masuk setelah transfer dikonfirmasi diterima di gudang ini." required x-model="to">
                    <option value="">Pilih gudang tujuan</option>
                    @foreach ($destinations as $warehouse)
                        <option value="{{ $warehouse->id }}">{{ $warehouse->code }} · {{ $warehouse->name }}</option>
                    @endforeach
                </x-select>
                <x-input name="transfer_date" type="date" label="Tanggal Kirim" :value="now()->toDateString()" :max="now()->toDateString()" required />
                <x-input name="reference" label="Referensi" tip="Nomor acuan, misalnya nomor surat jalan antar gudang." placeholder="cth. no. surat jalan" />
                <x-textarea name="notes" label="Catatan" rows="2" class="sm:col-span-2 lg:col-span-4" />
            </div>
        </div>

        <div class="card">
            <div class="flex flex-col gap-3 border-b border-slate-100 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="flex items-center gap-2 font-semibold text-slate-900"><x-heroicon-o-cube class="size-5 text-slate-400" /> Barang</h2>
                    <p class="mt-0.5 text-sm text-slate-500">Jumlah dalam pcs. Untuk SKU yang punya isi per koli, pakai tombol + koli. Lokasi tujuan otomatis terisi bin utama lokasi tetap SKU; bila bin utama penuh, sisanya ke bin cadangan.</p>
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

            <p class="mx-6 mt-4 rounded-lg bg-sky-50 px-3 py-2 text-sm text-sky-700" x-show="splitNote" x-text="splitNote" x-cloak></p>

            <p class="mx-6 mt-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-700" x-show="! from || ! to" x-cloak>
                Pilih gudang asal dan tujuan dulu agar daftar lokasi muncul.
            </p>

            <div class="overflow-x-auto p-6 pt-4">
                <table class="min-w-full text-sm">
                    <thead class="text-left text-[13px] text-slate-500">
                        <tr>
                            <th class="py-2 pr-2">Varian *</th>
                            <th class="px-2 py-2">Lokasi <span class="whitespace-nowrap">Asal <x-hint text="Bin di gudang asal tempat barang diambil." /></span></th>
                            <th class="px-2 py-2">Lokasi <span class="whitespace-nowrap">Tujuan <x-hint text="Bin di gudang tujuan tempat barang akan ditaruh. Bisa diubah lagi saat barang diterima." /></span></th>
                            <th class="px-2 py-2">Jumlah (pcs) <span class="whitespace-nowrap">* <x-hint text="Jumlah pcs yang dikirim. Tombol + koli / − koli menambah atau mengurangi satu koli sesuai Isi per Koli produk." /></span></th>
                            <th class="px-2 py-2">Keterangan</th>
                            <th class="py-2 pl-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(item, index) in items" :key="index">
                            <tr class="align-top">
                                <td class="py-1.5 pr-2">
                                    <select class="form-input min-w-64" :name="`items[${index}][product_variant_id]`" x-model="item.product_variant_id" @change="$nextTick(() => fillDefault(item))"
                                            x-tom-select="{ options: variantOptions, value: item.product_variant_id }" data-placeholder="Cari SKU / nama varian"
                                            :class="errors[`items.${index}.product_variant_id`] && 'form-input-error'"></select>
                                    <p class="mt-1 text-xs text-rose-600" x-show="errors[`items.${index}.product_variant_id`]" x-text="errors[`items.${index}.product_variant_id`]"></p>
                                </td>
                                <td class="px-2 py-1.5">
                                    <select class="form-input min-w-40" :name="`items[${index}][from_location_id]`" x-model="item.from_location_id"
                                            x-tom-select="{ options: locationOptions(from, false), value: item.from_location_id }" data-placeholder="Tanpa bin"
                                            :class="errors[`items.${index}.from_location_id`] && 'form-input-error'">
                                        <option value="">Tanpa bin</option>
                                    </select>
                                    <p class="mt-1 text-xs text-rose-600" x-show="errors[`items.${index}.from_location_id`]" x-text="errors[`items.${index}.from_location_id`]"></p>
                                </td>
                                <td class="px-2 py-1.5">
                                    <select class="form-input min-w-40" :name="`items[${index}][to_location_id]`" x-model="item.to_location_id"
                                            x-tom-select="{ options: locationOptions(to, true), value: item.to_location_id }" data-placeholder="Tanpa bin"
                                            :class="errors[`items.${index}.to_location_id`] && 'form-input-error'">
                                        <option value="">Tanpa bin</option>
                                    </select>
                                    <p class="mt-1 text-xs text-rose-600" x-show="errors[`items.${index}.to_location_id`]" x-text="errors[`items.${index}.to_location_id`]"></p>
                                </td>
                                <td class="px-2 py-1.5">
                                    <div class="flex items-center gap-1">
                                        <input type="number" step="1" min="1" class="form-input w-28" :name="`items[${index}][qty]`" x-model="item.qty" @change="spread(item)"
                                               :class="errors[`items.${index}.qty`] && 'form-input-error'">
                                        <template x-if="per(item)">
                                            <div class="flex gap-1">
                                                <button type="button" class="btn-secondary px-2 text-xs" @click="addCarton(item, 1)" title="Tambah 1 koli">+ koli</button>
                                                <button type="button" class="btn-secondary px-2 text-xs" @click="addCarton(item, -1)" title="Kurangi 1 koli">− koli</button>
                                            </div>
                                        </template>
                                    </div>
                                    <p class="mt-1 text-xs text-slate-500" x-show="cartonText(item)" x-text="cartonText(item)"></p>
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
                <x-heroicon-o-information-circle class="size-4" /> Stok gudang asal langsung berkurang; barang tercatat dalam perjalanan sampai diterima.
            </p>
            <div class="flex gap-3">
                <a href="{{ route('inventory.transfers.index') }}" class="btn-secondary">Batal</a>
                <button type="submit" class="btn-primary"><x-heroicon-o-truck class="size-5" /> Kirim Transfer</button>
            </div>
        </div>
    </form>
</x-layouts.app>
