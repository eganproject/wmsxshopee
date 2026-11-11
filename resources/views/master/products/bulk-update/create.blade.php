@php
    $result = session('bulk_result');
    $bulkErrors = $errors->getBag('bulk');
    $variantKeys = collect($fields)->where('level', \App\Services\ProductBulkUpdater::LEVEL_VARIANT)->keys();
    $groupInfo = [
        'product' => ['title' => 'Data Produk', 'text' => 'Berlaku untuk seluruh varian dalam satu produk.', 'icon' => 'heroicon-o-cube'],
        'variant' => ['title' => 'Data Varian / SKU', 'text' => 'Diisi per SKU varian. Produk tanpa varian memakai SKU induk.', 'icon' => 'heroicon-o-squares-2x2'],
    ];
@endphp

<x-layouts.app title="Update Massal Produk">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('master.products.index') }}" class="hover:text-slate-700">Master Produk</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Update Massal</span>
    </x-slot:breadcrumb>

    <x-page-header title="Update Massal Produk" description="Ubah kolom tertentu untuk banyak produk sekaligus lewat Excel / CSV. Perubahan ditampilkan dulu sebelum disimpan." icon="heroicon-o-pencil-square">
        <a href="{{ route('master.products.index', $filters) }}" class="btn-secondary"><x-heroicon-o-arrow-left class="size-5" /> Master Produk</a>
    </x-page-header>

    <div class="mx-auto max-w-5xl space-y-6">
        @if ($result)
            <div class="card flex flex-col gap-3 border-emerald-200 p-5 sm:flex-row sm:items-center">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-emerald-50 text-emerald-600"><x-heroicon-o-check-circle class="size-6" /></span>
                <div class="min-w-0 flex-1 text-sm">
                    <p class="font-semibold text-slate-900">
                        {{ $result['products'] ? number_format($result['products'], 0, ',', '.').' produk diperbarui' : 'Tidak ada perubahan' }}
                    </p>
                    <p class="text-slate-500">
                        {{ number_format($result['changes'], 0, ',', '.') }} nilai diubah dari {{ number_format($result['rows'], 0, ',', '.') }} baris file.
                        @if ($result['fields'])
                            Kolom: {{ implode(', ', $result['fields']) }}.
                        @endif
                        Riwayatnya tercatat di log aktivitas tiap produk.
                    </p>
                </div>
                <a href="{{ route('master.products.index') }}" class="btn-secondary">Lihat Master Produk</a>
            </div>
        @endif

        @if ($pending)
            <div class="flex flex-col gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 sm:flex-row sm:items-center">
                <x-heroicon-o-clock class="size-5 shrink-0" />
                <p class="flex-1">Ada file yang sudah dicek tapi perubahannya belum diterapkan.</p>
                <a href="{{ route('master.products.bulk-update.preview') }}" class="btn-secondary">Lanjutkan pratinjau</a>
            </div>
        @endif

        {{-- Langkah 1 & 2: pilih kolom lalu unduh template (satu form GET) --}}
        <form method="GET" action="{{ route('master.products.bulk-update.template') }}" data-no-loading class="space-y-6"
              x-data="{
                  selected: @js($selected),
                  variantKeys: @js($variantKeys),
                  groups: @js(collect($groups)->map(fn ($g) => array_keys($g))),
                  get variantLevel() { return this.selected.some((f) => this.variantKeys.includes(f)) },
                  allIn(group) { return this.groups[group].every((f) => this.selected.includes(f)) },
                  toggleGroup(group) {
                      const all = this.allIn(group);
                      this.selected = all
                          ? this.selected.filter((f) => ! this.groups[group].includes(f))
                          : [...new Set([...this.selected, ...this.groups[group]])];
                  },
              }">
            @foreach ($filters as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach

            <section class="card">
                <div class="flex flex-col gap-3 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-start gap-3">
                        <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-slate-900 text-xs font-semibold text-white">1</span>
                        <div>
                            <h2 class="font-semibold text-slate-900">Pilih kolom yang akan diubah</h2>
                            <p class="text-sm text-slate-500">Hanya kolom ini yang masuk ke template dan diperbarui. Kolom lain tidak tersentuh.</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 pl-10 sm:pl-0">
                        <span class="rounded-full px-2.5 py-0.5 text-xs font-medium ring-1"
                              :class="selected.length ? 'bg-brand-50 text-brand-700 ring-brand-100' : 'bg-slate-50 text-slate-500 ring-slate-200'"
                              x-text="selected.length ? `${selected.length} kolom dipilih` : 'Belum ada kolom'"></span>
                        <button type="button" class="btn-secondary" x-show="selected.length" x-cloak @click="selected = []">Kosongkan</button>
                    </div>
                </div>

                <div class="grid gap-5 p-5 lg:grid-cols-2">
                    @foreach ($groups as $level => $groupFields)
                        <div class="rounded-xl border border-slate-200">
                            <div class="flex items-start justify-between gap-3 border-b border-slate-100 bg-slate-50 px-4 py-2.5">
                                <div class="min-w-0">
                                    <p class="flex items-center gap-2 text-sm font-medium text-slate-900">
                                        <x-dynamic-component :component="$groupInfo[$level]['icon']" class="size-4 text-slate-500" />
                                        {{ $groupInfo[$level]['title'] }}
                                    </p>
                                    <p class="mt-0.5 text-xs text-slate-500">{{ $groupInfo[$level]['text'] }}</p>
                                </div>
                                <button type="button" class="shrink-0 text-xs text-slate-600 hover:text-slate-900" @click="toggleGroup('{{ $level }}')"
                                        x-text="allIn('{{ $level }}') ? 'Batal pilih' : 'Pilih semua'">Pilih semua</button>
                            </div>
                            <div class="divide-y divide-slate-100">
                                @foreach ($groupFields as $key => $field)
                                    <label class="flex cursor-pointer items-start gap-3 px-4 py-2.5 transition hover:bg-slate-50"
                                           :class="selected.includes('{{ $key }}') && 'bg-brand-50/60 hover:bg-brand-50'">
                                        <input type="checkbox" name="fields[]" value="{{ $key }}" class="form-checkbox mt-1" x-model="selected">
                                        <span class="min-w-0 flex-1">
                                            <span class="flex flex-wrap items-center gap-x-2 text-sm text-slate-800">
                                                {{ $field['label'] }} <code class="text-xs text-slate-400">{{ $key }}</code>
                                            </span>
                                            <span class="block text-xs text-slate-500">{{ $field['hint'] }}</span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="flex items-start gap-2 border-t border-slate-100 px-5 py-3 text-xs text-slate-500" x-show="selected.length" x-cloak>
                    <x-heroicon-o-table-cells class="size-4 shrink-0 text-slate-400" />
                    <p x-show="variantLevel">Template berisi <strong class="font-medium text-slate-700">satu baris per SKU varian</strong> dengan kunci kolom <code>sku</code>. Kolom data produk diulang di tiap varian dan harus sama untuk varian satu produk.</p>
                    <p x-show="! variantLevel">Template berisi <strong class="font-medium text-slate-700">satu baris per produk</strong> dengan kunci kolom <code>sku_induk</code>.</p>
                </div>
            </section>

            <section class="card" :class="! selected.length && 'opacity-60'">
                <div class="flex items-start gap-3 border-b border-slate-100 px-5 py-4">
                    <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-slate-900 text-xs font-semibold text-white">2</span>
                    <div>
                        <h2 class="font-semibold text-slate-900">Unduh template</h2>
                        <p class="text-sm text-slate-500">Template sudah berisi data saat ini, jadi cukup ubah nilai yang perlu diganti.</p>
                    </div>
                </div>
                <div class="space-y-4 p-5">
                    @php
                        $scopes = array_filter([
                            'all' => ['Semua produk', number_format($counts['all'], 0, ',', '.').' produk'],
                            'filtered' => $filters ? ['Sesuai filter Master Produk', number_format($counts['filtered'], 0, ',', '.').' produk · '.collect($filters)->map(fn ($v, $k) => match ($k) { 'status' => $v === 'active' ? 'Aktif' : 'Nonaktif', default => $v })->implode(', ')] : null,
                            'empty' => ['Template kosong', 'Hanya judul kolom, isi SKU sendiri'],
                        ]);
                    @endphp
                    <div @class(["grid gap-3", "sm:grid-cols-3" => count($scopes) === 3, "sm:grid-cols-2" => count($scopes) === 2])>
                        @foreach ($scopes as $value => [$label, $text])
                            <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 px-4 py-3 transition hover:border-slate-300 has-[:checked]:border-slate-900 has-[:checked]:bg-slate-50">
                                <input type="radio" name="scope" value="{{ $value }}" class="mt-1 accent-slate-900" @checked($value === ($filters ? 'filtered' : 'all'))>
                                <span class="min-w-0">
                                    <span class="block text-sm font-medium text-slate-900">{{ $label }}</span>
                                    <span class="block text-xs text-slate-500">{{ $text }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="submit" name="format" value="xlsx" class="btn-primary" :disabled="! selected.length">
                            <x-heroicon-o-arrow-down-tray class="size-5" /> Unduh Excel
                        </button>
                        <button type="submit" name="format" value="csv" class="btn-secondary" :disabled="! selected.length">
                            <x-heroicon-o-arrow-down-tray class="size-5" /> CSV
                        </button>
                        <p class="text-xs text-slate-500" x-show="! selected.length">Pilih minimal satu kolom di langkah 1.</p>
                    </div>
                </div>
            </section>
        </form>

        {{-- Langkah 3: unggah file → pratinjau --}}
        <section class="card">
            <div class="flex items-start gap-3 border-b border-slate-100 px-5 py-4">
                <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-slate-900 text-xs font-semibold text-white">3</span>
                <div>
                    <h2 class="font-semibold text-slate-900">Unggah file yang sudah diubah</h2>
                    <p class="text-sm text-slate-500">Semua baris dicek dulu. Anda akan melihat daftar perubahan sebelum ada yang disimpan.</p>
                </div>
            </div>
            <form method="POST" action="{{ route('master.products.bulk-update.store') }}" enctype="multipart/form-data" class="space-y-4 p-5">
                @csrf

                @if ($bulkErrors->any())
                    <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700" role="alert">
                        <p class="flex items-center gap-2 font-medium">
                            <x-heroicon-o-exclamation-triangle class="size-5 shrink-0" />
                            {{ session('bulk_file') ? session('bulk_file').' belum' : 'File belum' }} bisa diproses. Perbaiki lalu unggah lagi:
                        </p>
                        <ul class="mt-1.5 max-h-64 space-y-0.5 overflow-y-auto pl-7">
                            @foreach ($bulkErrors->all() as $message)
                                <li class="list-disc">{{ $message }}</li>
                            @endforeach
                        </ul>
                        @if (count($bulkErrors->get('rows')) >= 50)
                            <p class="mt-1.5 pl-7 text-xs">Hanya 50 kesalahan pertama yang ditampilkan.</p>
                        @endif
                    </div>
                @endif

                <div>
                    <label for="file" class="form-label">File <span class="text-rose-500">*</span></label>
                    <input id="file" name="file" type="file" required accept=".xlsx,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                           @class(['form-input file:mr-3 file:border-0 file:bg-transparent file:text-sm file:font-medium', 'form-input-error' => $errors->has('file')])>
                    @error('file')
                        <p class="mt-1 flex items-center gap-1 text-xs text-rose-600"><x-heroicon-o-exclamation-triangle class="size-4 shrink-0" /> {{ $message }}</p>
                    @else
                        <p class="mt-1 text-xs text-slate-400">.xlsx atau .csv, maks. 10 MB / {{ number_format(\App\Support\SpreadsheetImport::MAX_ROWS, 0, ',', '.') }} baris.</p>
                    @enderror
                </div>

                <ul class="grid gap-2 text-xs text-slate-600 sm:grid-cols-2">
                    <li class="flex gap-2"><x-heroicon-o-table-cells class="size-4 shrink-0 text-slate-400" /> Kolom yang diperbarui dibaca dari judul kolom file, jadi file bisa langsung diunggah tanpa memilih ulang.</li>
                    <li class="flex gap-2"><x-heroicon-o-backspace class="size-4 shrink-0 text-slate-400" /> Sel kosong berarti nilainya dikosongkan. Hapus barisnya bila produk itu tidak ingin diubah.</li>
                    <li class="flex gap-2"><x-heroicon-o-lock-closed class="size-4 shrink-0 text-slate-400" /> SKU hanya kunci pencarian dan tidak ikut berubah. Produk baru tidak dibuat di sini.</li>
                    <li class="flex gap-2"><x-heroicon-o-shield-check class="size-4 shrink-0 text-slate-400" /> Bila ada satu baris salah, tidak ada yang disimpan dan barisnya ditunjukkan.</li>
                </ul>

                <button type="submit" class="btn-primary"><x-heroicon-o-document-magnifying-glass class="size-5" /> Cek & Lihat Perubahan</button>
            </form>
        </section>
    </div>
</x-layouts.app>
