@php
    $result = session('import_result');
    $importErrors = $errors->getBag('import');
    $number = fn ($n) => number_format((int) $n, 0, ',', '.');
    $scopes = [
        'all' => ['Semua SKU aktif', 'Semua SKU aktif di Master Produk'],
        'stocked' => ['Ada stok di gudang', 'Hanya SKU yang stoknya > 0'],
        'configured' => ['Sudah diatur', 'Sudah punya min/maks atau lokasi tetap'],
        'empty' => ['Template kosong', 'Hanya judul kolom, isi sendiri'],
    ];
@endphp

<x-layouts.app title="Import SKU per Gudang">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span>Inventori</span>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('inventory.sku-settings.index', ['warehouse' => $warehouse?->id]) }}" class="hover:text-slate-700">SKU per Gudang</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Import</span>
    </x-slot:breadcrumb>

    <x-page-header title="Import SKU per Gudang" description="Ubah stok minimum, stok maksimum dan lokasi tetap banyak SKU sekaligus lewat Excel / CSV. Perubahan ditampilkan dulu sebelum disimpan." icon="heroicon-o-arrow-up-tray">
        <a href="{{ route('inventory.sku-settings.index', ['warehouse' => $warehouse?->id]) }}" class="btn-secondary"><x-heroicon-o-arrow-left class="size-5" /> SKU per Gudang</a>
    </x-page-header>

    <div class="mx-auto max-w-5xl space-y-6">
        @if ($result)
            <div class="card flex flex-col gap-3 border-emerald-200 p-5 sm:flex-row sm:items-center">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-emerald-50 text-emerald-600"><x-heroicon-o-check-circle class="size-6" /></span>
                <div class="min-w-0 flex-1 text-sm">
                    <p class="font-semibold text-slate-900">{{ $result['skus'] ? $number($result['skus']).' SKU per gudang diperbarui' : 'Tidak ada perubahan' }}</p>
                    <p class="text-slate-500">
                        {{ $number($result['changes']) }} nilai diubah dari {{ $number($result['rows']) }} baris file.
                        @if ($result['fields'])
                            Kolom: {{ implode(', ', $result['fields']) }}.
                        @endif
                    </p>
                </div>
                <a href="{{ route('inventory.sku-settings.index', ['warehouse' => $warehouse?->id]) }}" class="btn-secondary">Lihat SKU per Gudang</a>
            </div>
        @endif

        @if ($pending)
            <div class="flex flex-col gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 sm:flex-row sm:items-center">
                <x-heroicon-o-clock class="size-5 shrink-0" />
                <p class="flex-1">Ada file yang sudah dicek tapi perubahannya belum diterapkan.</p>
                <a href="{{ route('inventory.sku-settings.import.preview') }}" class="btn-secondary">Lanjutkan pratinjau</a>
            </div>
        @endif

        @if ($warehouses->isEmpty())
            <div class="card">
                <x-empty-state icon="heroicon-o-building-office-2" title="Belum ada gudang aktif" description="Tambahkan gudang di Master Gudang dulu." />
            </div>
        @else
            {{-- Langkah 1 & 2: pilih kolom lalu unduh template (satu form GET) --}}
            <form method="GET" action="{{ route('inventory.sku-settings.import.template') }}" data-no-loading class="space-y-6" x-data="{ selected: @js($selected) }">
                <section class="card">
                    <div class="flex flex-col gap-3 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex items-start gap-3">
                            <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-slate-900 text-xs font-semibold text-white">1</span>
                            <div>
                                <h2 class="font-semibold text-slate-900">Pilih kolom yang akan diubah</h2>
                                <p class="text-sm text-slate-500">Hanya kolom ini yang masuk ke template dan diperbarui. Kolom lain tidak tersentuh.</p>
                            </div>
                        </div>
                        <span class="ml-10 self-start rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 sm:ml-0 sm:self-center"
                              :class="selected.length ? 'bg-brand-50 text-brand-700 ring-brand-100' : 'bg-slate-50 text-slate-500 ring-slate-200'"
                              x-text="selected.length ? `${selected.length} kolom dipilih` : 'Belum ada kolom'"></span>
                    </div>
                    <div class="divide-y divide-slate-100">
                        @foreach ($fields as $key => $field)
                            <label class="flex cursor-pointer items-start gap-3 px-5 py-3 transition hover:bg-slate-50" :class="selected.includes('{{ $key }}') && 'bg-brand-50/60 hover:bg-brand-50'">
                                <input type="checkbox" name="fields[]" value="{{ $key }}" class="form-checkbox mt-1" x-model="selected">
                                <span class="min-w-0 flex-1">
                                    <span class="flex flex-wrap items-center gap-x-2 text-sm text-slate-800">{{ $field['label'] }} <code class="text-xs text-slate-400">{{ $key }}</code></span>
                                    <span class="block text-xs text-slate-500">{{ $field['hint'] }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </section>

                <section class="card" :class="! selected.length && 'opacity-60'">
                    <div class="flex items-start gap-3 border-b border-slate-100 px-5 py-4">
                        <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-slate-900 text-xs font-semibold text-white">2</span>
                        <div>
                            <h2 class="font-semibold text-slate-900">Unduh template</h2>
                            <p class="text-sm text-slate-500">Template sudah berisi pengaturan saat ini (satu baris per SKU per gudang), jadi cukup ubah nilai yang perlu diganti.</p>
                        </div>
                    </div>
                    <div class="space-y-4 p-5">
                        <div class="max-w-sm">
                            <label for="warehouse" class="form-label">Gudang</label>
                            <select id="warehouse" name="warehouse" class="form-input">
                                @foreach ($warehouses as $option)
                                    <option value="{{ $option->id }}" @selected($warehouse?->id === $option->id)>{{ $option->code }} · {{ $option->name }}</option>
                                @endforeach
                                @if ($warehouses->count() > 1)
                                    <option value="all">Semua gudang ({{ $warehouses->count() }})</option>
                                @endif
                            </select>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            @foreach ($scopes as $value => [$label, $text])
                                <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 px-4 py-3 transition hover:border-slate-300 has-[:checked]:border-slate-900 has-[:checked]:bg-slate-50">
                                    <input type="radio" name="scope" value="{{ $value }}" class="mt-1 accent-slate-900" @checked($value === 'all')>
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
        @endif

        {{-- Langkah 3: unggah file → pratinjau --}}
        <section class="card">
            <div class="flex items-start gap-3 border-b border-slate-100 px-5 py-4">
                <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-slate-900 text-xs font-semibold text-white">3</span>
                <div>
                    <h2 class="font-semibold text-slate-900">Unggah file yang sudah diubah</h2>
                    <p class="text-sm text-slate-500">Semua baris dicek dulu. Anda akan melihat daftar perubahan sebelum ada yang disimpan.</p>
                </div>
            </div>
            <form method="POST" action="{{ route('inventory.sku-settings.import.store') }}" enctype="multipart/form-data" class="space-y-4 p-5">
                @csrf

                @if ($importErrors->any())
                    <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700" role="alert">
                        <p class="flex items-center gap-2 font-medium">
                            <x-heroicon-o-exclamation-triangle class="size-5 shrink-0" />
                            {{ session('import_file') ? session('import_file').' belum' : 'File belum' }} bisa diproses. Perbaiki lalu unggah lagi:
                        </p>
                        <ul class="mt-1.5 max-h-64 space-y-0.5 overflow-y-auto pl-7">
                            @foreach ($importErrors->all() as $message)
                                <li class="list-disc">{{ $message }}</li>
                            @endforeach
                        </ul>
                        @if (count($importErrors->get('rows')) >= 50)
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
                        <p class="mt-1 text-xs text-slate-400">.xlsx atau .csv, maks. 10 MB / {{ $number(\App\Support\SpreadsheetImport::MAX_ROWS) }} baris.</p>
                    @enderror
                </div>

                <ul class="grid gap-2 text-xs text-slate-600 sm:grid-cols-2">
                    <li class="flex gap-2"><x-heroicon-o-table-cells class="size-4 shrink-0 text-slate-400" /> Kolom yang diperbarui dibaca dari judul kolom file (stok_min, stok_maks, lokasi_tetap). Kunci baris: kolom gudang + sku.</li>
                    <li class="flex gap-2"><x-heroicon-o-backspace class="size-4 shrink-0 text-slate-400" /> Sel kosong berarti nilainya dikosongkan. Hapus barisnya bila SKU itu tidak ingin diubah.</li>
                    <li class="flex gap-2"><x-heroicon-o-map-pin class="size-4 shrink-0 text-slate-400" /> <span>Lokasi tetap: kode bin dipisah koma, bin utama dulu. Kapasitas opsional, cth. <code>A01-01-01:50, A01-01-02</code>.</span></li>
                    <li class="flex gap-2"><x-heroicon-o-shield-check class="size-4 shrink-0 text-slate-400" /> Bila ada satu baris salah, tidak ada yang disimpan dan barisnya ditunjukkan.</li>
                </ul>

                <button type="submit" class="btn-primary"><x-heroicon-o-document-magnifying-glass class="size-5" /> Cek & Lihat Perubahan</button>
            </form>
        </section>
    </div>
</x-layouts.app>
