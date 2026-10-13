@php($defaultWarehouse = $warehouses->count() === 1 ? $warehouses->first()->id : '')

<x-layouts.app title="Stock Opname Baru">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('inventory.counts.index') }}" class="hover:text-slate-700">Stock Opname</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Baru</span>
    </x-slot:breadcrumb>

    <x-page-header title="Stock Opname Baru" description="Lembar hitung dibuat dari saldo stok saat ini di cakupan yang dipilih." icon="heroicon-o-clipboard-document-check">
        <a href="{{ route('inventory.counts.index') }}" class="btn-secondary"><x-heroicon-o-arrow-left class="size-5" /> Kembali</a>
    </x-page-header>

    <form method="POST" action="{{ route('inventory.counts.store') }}"
          x-data="{
              warehouse: @js((string) old('warehouse_id', $defaultWarehouse)),
              scope: @js(old('scope', 'all')),
              zone: @js((string) old('warehouse_zone_id', '')),
              zones: @js($zones),
              get zoneOptions() {
                  return this.zones.filter(z => String(z.warehouse_id) === String(this.warehouse)).map(z => ({ value: z.id, text: z.text }));
              },
          }">
        @csrf
        <div class="card">
            <div class="border-b border-slate-100 px-5 py-3">
                <h2 class="flex items-center gap-2 text-base font-semibold text-slate-900"><x-heroicon-o-document-text class="size-5 text-slate-400" /> Informasi Opname</h2>
                <p class="mt-0.5 text-sm text-slate-500">Nomor dibuat otomatis, cth. {{ \App\Models\StockCount::PREFIX.now()->format('ym') }}-001.</p>
            </div>
            <div class="grid gap-x-6 gap-y-4 p-5 sm:grid-cols-3">
                <x-select name="warehouse_id" label="Gudang" required x-model="warehouse">
                    <option value="">Pilih gudang</option>
                    @foreach ($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}">{{ $warehouse->code }} · {{ $warehouse->name }}</option>
                    @endforeach
                </x-select>
                <x-input name="count_date" type="date" label="Tanggal Opname" :value="now()->toDateString()" :max="now()->toDateString()" required />
                <div></div>

                <div class="sm:col-span-3">
                    <p class="form-label">Cakupan <span class="text-rose-500">*</span></p>
                    <div class="grid gap-2 sm:grid-cols-3">
                        @foreach ([
                            'all' => ['heroicon-o-building-office-2', 'Seluruh gudang', 'Semua bin dan stok tanpa bin.'],
                            'zone' => ['heroicon-o-squares-2x2', 'Satu zona', 'Hanya bin di zona yang dipilih.'],
                            'unbinned' => ['heroicon-o-inbox-stack', 'Stok tanpa bin', 'Hitung stok yang belum punya bin, sekalian taruh ke bin.'],
                        ] as $value => [$icon, $title, $text])
                            <label class="flex cursor-pointer gap-3 rounded-lg border p-3 transition"
                                   :class="scope === @js($value) ? 'border-slate-500 bg-slate-50' : 'border-slate-200 hover:bg-slate-50'">
                                <input type="radio" name="scope" value="{{ $value }}" x-model="scope" class="mt-0.5 size-4 border-slate-300 text-brand-600">
                                <span>
                                    <span class="flex items-center gap-1.5 text-sm font-medium text-slate-900"><x-dynamic-component :component="$icon" class="size-4 text-slate-400" /> {{ $title }}</span>
                                    <span class="mt-0.5 block text-xs text-slate-500">{{ $text }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                    @error('scope')
                        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <div x-show="scope === 'zone'" x-cloak>
                    <label class="form-label" for="warehouse_zone_id">Zona <span class="text-rose-500">*</span></label>
                    <select id="warehouse_zone_id" name="warehouse_zone_id" class="form-input {{ $errors->has('warehouse_zone_id') ? 'form-input-error' : '' }}" x-model="zone"
                            x-tom-select="{ options: zoneOptions, value: zone }" data-placeholder="Pilih zona"></select>
                    @error('warehouse_zone_id')
                        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <x-textarea name="notes" label="Catatan" rows="2" class="sm:col-span-3" />
            </div>
        </div>

        <div class="sticky bottom-0 mt-6 flex items-center justify-between gap-3 border-t border-slate-200 bg-white/90 py-3 backdrop-blur">
            <p class="hidden items-center gap-1.5 text-xs text-slate-500 sm:flex">
                <x-heroicon-o-information-circle class="size-4" /> Stok belum berubah sampai opname diposting.
            </p>
            <div class="flex gap-2">
                <a href="{{ route('inventory.counts.index') }}" class="btn-secondary">Batal</a>
                <button type="submit" class="btn-primary"><x-heroicon-o-clipboard-document-list class="size-5" /> Buat Lembar Hitung</button>
            </div>
        </div>
    </form>
</x-layouts.app>
