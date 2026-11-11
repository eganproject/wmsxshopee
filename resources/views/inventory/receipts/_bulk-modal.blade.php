{{-- Modal Input Banyak penerimaan barang: pilih banyak barang, atau tempel SKU + jumlah dari Excel.
 Isian di sini tidak punya name, jadi tidak ikut terkirim; hasilnya ditambahkan ke tabel barang. --}}
<div x-show="bulk.open" x-cloak @keydown.escape.window="bulk.open = false">
    <div x-show="bulk.open" x-transition.opacity class="fixed inset-0 z-[70] bg-black/40"></div>
    <div x-show="bulk.open" class="fixed inset-0 z-[80] flex items-start justify-center overflow-y-auto p-4 sm:items-center">
        <div x-show="bulk.open" x-transition @click.outside="bulk.open = false" role="dialog" aria-modal="true" aria-labelledby="bulk-title"
             class="flex max-h-[90vh] w-full max-w-3xl flex-col rounded-xl border border-slate-200 bg-white shadow-xl">
            <div class="flex items-start justify-between gap-4 border-b border-slate-100 px-5 py-4">
                <div>
                    <h3 id="bulk-title" class="text-base font-semibold text-slate-900">Input Banyak Barang</h3>
                    <p class="mt-0.5 text-sm text-slate-500">Barang yang sudah ada di daftar akan ditambah jumlahnya. Lokasi bin otomatis dari lokasi tetap di SKU per Gudang.</p>
                </div>
                <button type="button" class="btn-icon" title="Tutup" @click="bulk.open = false"><x-heroicon-o-x-mark class="size-5" /></button>
            </div>

            <div class="flex gap-1 border-b border-slate-100 px-5 text-sm">
                @foreach (['pick' => ['Pilih Barang', 'heroicon-o-check-circle'], 'paste' => ['Tempel dari Excel', 'heroicon-o-clipboard-document-list']] as $tab => [$label, $icon])
                    <button type="button" class="-mb-px flex items-center gap-1.5 border-b-2 px-3 py-2.5 font-medium transition"
                            :class="bulk.tab === '{{ $tab }}' ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-700'"
                            @click="bulk.tab = '{{ $tab }}'; $nextTick(() => ('{{ $tab }}' === 'paste' ? $refs.bulkText : $refs.bulkQuery)?.focus())">
                        <x-dynamic-component :component="$icon" class="size-4" /> {{ $label }}
                    </button>
                @endforeach
            </div>

            {{-- Pilih Barang --}}
            <div x-show="bulk.tab === 'pick'" class="flex min-h-0 flex-1 flex-col" @keydown.enter.prevent>
                <div class="flex flex-col gap-2 px-5 pt-4 sm:flex-row sm:items-center">
                    <div class="relative flex-1">
                        <x-heroicon-o-magnifying-glass class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-slate-400" />
                        <input type="search" x-ref="bulkQuery" x-model.debounce.150ms="bulk.query" class="form-input pl-8" placeholder="Cari SKU, barcode atau nama barang">
                    </div>
                    <label class="flex items-center gap-2 text-sm whitespace-nowrap text-slate-600">
                        Diterima semua
                        <input type="number" min="0" step="1" x-model="bulk.all" @input="setAllQty()" class="form-input w-24 text-right" placeholder="1">
                    </label>
                </div>
                <div class="mt-3 min-h-0 flex-1 overflow-y-auto border-y border-slate-100">
                    <table class="min-w-full text-sm">
                        <thead class="sticky top-0 bg-slate-50 text-left text-[13px] text-slate-500">
                            <tr>
                                <th class="w-10 py-2 pl-5">
                                    <input type="checkbox" class="form-checkbox" title="Pilih semua hasil" @change="pickAllResults()"
                                           :checked="bulkResults.length && bulkResults.every(v => bulk.picked[v.id] !== undefined)">
                                </th>
                                <th class="py-2 pr-2 font-normal">Barang</th>
                                <th class="py-2 pr-5 text-right font-normal">Diterima Baik</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="variant in bulkResults" :key="variant.id">
                                <tr class="cursor-pointer border-t border-slate-100 hover:bg-slate-50" :class="bulk.picked[variant.id] !== undefined && 'bg-brand-50/60'" @click="togglePick(variant)">
                                    <td class="py-2 pl-5"><input type="checkbox" class="form-checkbox pointer-events-none" :checked="bulk.picked[variant.id] !== undefined"></td>
                                    <td class="py-2 pr-2">
                                        <span class="font-mono text-xs text-slate-500" x-text="variant.sku"></span>
                                        <span class="block text-slate-800" x-text="variant.label"></span>
                                    </td>
                                    <td class="py-1.5 pr-5 text-right" @click.stop>
                                        <input type="number" min="0" step="1" class="form-input ml-auto w-24 text-right" placeholder="1"
                                               :value="bulk.picked[variant.id] ?? ''"
                                               @input="bulk.picked[variant.id] = $event.target.value">
                                    </td>
                                </tr>
                            </template>
                            <tr x-show="! bulkResults.length">
                                <td colspan="3" class="px-5 py-8 text-center text-slate-500">Barang tidak ditemukan.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="px-5 pt-2 text-xs text-slate-400" x-show="bulkResults.length >= 100">Menampilkan 100 barang pertama. Persempit pencarian untuk barang lain.</p>
                <div class="flex items-center justify-between gap-3 px-5 py-4">
                    <p class="text-sm text-slate-600"><span class="font-semibold" x-text="pickedCount"></span> barang dipilih</p>
                    <div class="flex gap-2">
                        <button type="button" class="btn-secondary" @click="bulk.open = false">Batal</button>
                        <button type="button" class="btn-primary" :disabled="! pickedCount" @click="applyPicked()">
                            <x-heroicon-o-plus class="size-5" /> Tambahkan
                        </button>
                    </div>
                </div>
            </div>

            {{-- Tempel dari Excel --}}
            <div x-show="bulk.tab === 'paste'" x-cloak class="flex min-h-0 flex-1 flex-col">
                <div class="px-5 pt-4">
                    <p class="text-sm text-slate-600">
                        Salin kolom dari Excel lalu tempel. Urutan kolom:
                        <span class="font-medium">SKU / barcode</span>, <span class="font-medium">Diterima Baik</span>,
                        <span class="text-slate-500">Dipesan (opsional)</span>, <span class="text-slate-500">Rusak (opsional)</span>.
                        Jumlah kosong dianggap 1.
                    </p>
                    <textarea x-ref="bulkText" x-model.debounce.200ms="bulk.text" rows="6" class="form-input mt-2 font-mono text-xs"
                              placeholder="KAOS-M&#9;10&#10;KAOS-L&#9;12&#9;12&#10;8991003, 5, 6, 1"
                              @keydown.ctrl.enter.prevent="pasteValid.length && applyPaste()" @keydown.meta.enter.prevent="pasteValid.length && applyPaste()"></textarea>
                </div>
                <div class="mt-3 min-h-0 flex-1 overflow-y-auto border-y border-slate-100" x-show="pasteRows.length">
                    <table class="min-w-full text-sm">
                        <thead class="sticky top-0 bg-slate-50 text-left text-[13px] text-slate-500">
                            <tr>
                                <th class="py-2 pl-5 font-normal">Barang</th>
                                <th class="px-2 py-2 text-right font-normal">Diterima</th>
                                <th class="px-2 py-2 text-right font-normal">Dipesan</th>
                                <th class="py-2 pr-5 pl-2 text-right font-normal">Rusak</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="row in pasteRows" :key="row.line">
                                <tr class="border-t border-slate-100" :class="row.error && 'bg-rose-50/60'">
                                    <td class="py-2 pl-5">
                                        <span class="font-mono text-xs" :class="row.error ? 'text-rose-600' : 'text-slate-500'" x-text="row.code"></span>
                                        <span class="block" :class="row.error ? 'text-rose-600' : 'text-slate-800'" x-text="row.error || row.variant.label"></span>
                                    </td>
                                    <td class="px-2 py-2 text-right tabular-nums" x-text="Number.isNaN(row.received) ? '?' : row.received"></td>
                                    <td class="px-2 py-2 text-right tabular-nums text-slate-500" x-text="row.ordered === '' ? '—' : (Number.isNaN(row.ordered) ? '?' : row.ordered)"></td>
                                    <td class="py-2 pr-5 pl-2 text-right tabular-nums text-slate-500" x-text="row.damaged === '' ? '—' : (Number.isNaN(row.damaged) ? '?' : row.damaged)"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <div class="flex items-center justify-between gap-3 px-5 py-4">
                    <p class="text-sm text-slate-600">
                        <span class="font-semibold" x-text="pasteValid.length"></span> barang siap ditambahkan
                        <span class="text-rose-600" x-show="pasteRows.length > pasteValid.length" x-text="`· ${pasteRows.length - pasteValid.length} baris dilewati`"></span>
                    </p>
                    <div class="flex gap-2">
                        <button type="button" class="btn-secondary" @click="bulk.open = false">Batal</button>
                        <button type="button" class="btn-primary" :disabled="! pasteValid.length" @click="applyPaste()">
                            <x-heroicon-o-plus class="size-5" /> Tambahkan
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
