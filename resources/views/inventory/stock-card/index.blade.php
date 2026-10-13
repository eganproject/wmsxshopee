@php($fmt = fn ($value) => number_format($value, 0, ',', '.'))

<x-layouts.app title="Kartu Stok">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span>Inventori</span>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Kartu Stok</span>
    </x-slot:breadcrumb>

    <x-page-header title="Kartu Stok" description="Riwayat setiap pergerakan stok satu varian beserta saldo berjalannya." icon="heroicon-o-document-chart-bar">
        <x-inventory.create-menu />
    </x-page-header>

    <x-inventory.tabs />

    <form method="GET" class="card mb-6 grid gap-4 p-4 md:grid-cols-6">
        <div class="md:col-span-3">
            <label for="variant" class="form-label">Varian</label>
            {{-- Varian bisa ribuan: dicari lewat AJAX, hanya pilihan aktif yang dirender. --}}
            <select id="variant" name="variant" class="form-input" onchange="this.form.submit()"
                    data-remote="{{ route('inventory.lookup.variants') }}" data-placeholder="Ketik SKU, barcode atau nama varian">
                <option value="">Pilih varian</option>
                @if ($variant)
                    <option value="{{ $variant->id }}" selected>{{ $variant->displayName() }}</option>
                @endif
            </select>
        </div>
        <div class="md:col-span-3">
            <label for="code" class="form-label">atau scan SKU / barcode</label>
            <div class="relative">
                <x-heroicon-o-qr-code class="pointer-events-none absolute top-1/2 left-3.5 size-5 -translate-y-1/2 text-slate-400" />
                <input id="code" type="search" name="code" class="form-input pl-11" placeholder="Scan lalu Enter"
                       onkeydown="if (event.key === 'Enter') { this.form.variant.value = ''; }">
            </div>
        </div>
        <div class="md:col-span-2">
            <label for="warehouse" class="form-label">Gudang</label>
            <select id="warehouse" name="warehouse" class="form-input" onchange="this.form.location.value = ''; this.form.submit()">
                <option value="">Semua gudang</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}" @selected(request('warehouse') == $warehouse->id)>{{ $warehouse->code }} · {{ $warehouse->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="md:col-span-2">
            <label for="location" class="form-label">Lokasi</label>
            <select id="location" name="location" class="form-input" onchange="this.form.submit()">
                <option value="">Semua lokasi</option>
                @foreach ($locations as $location)
                    <option value="{{ $location->id }}" @selected(request('location') == $location->id)>{{ $location->code }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="from" class="form-label">Dari</label>
            <input id="from" type="date" name="from" value="{{ $from->toDateString() }}" class="form-input">
        </div>
        <div>
            <label for="to" class="form-label">Sampai</label>
            <input id="to" type="date" name="to" value="{{ $to->toDateString() }}" class="form-input">
        </div>
        <div class="flex justify-end gap-2 md:col-span-6">
            <a href="{{ route('inventory.stock-card.index') }}" class="btn-secondary">Reset</a>
            <button type="submit" class="btn-primary"><x-heroicon-o-funnel class="size-5" /> Tampilkan</button>
        </div>
    </form>

    @if (! $variant)
        <div class="card px-5 py-16 text-center">
            <x-heroicon-o-document-chart-bar class="mx-auto size-12 text-slate-300" />
            @if (request()->filled('code'))
                <p class="mt-3 font-medium text-slate-700">SKU atau barcode "{{ request('code') }}" tidak ditemukan</p>
            @else
                <p class="mt-3 font-medium text-slate-700">Pilih varian</p>
                <p class="text-sm text-slate-500">Pilih atau scan varian untuk melihat kartu stoknya.</p>
            @endif
        </div>
    @else
        @php($unit = $variant->product->unit->code)

        <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                ['Saldo Awal', $opening, 'text-slate-900', 'per '.$from->translatedFormat('d M Y')],
                ['Masuk', $totalIn, 'text-emerald-600', 'dalam periode'],
                ['Keluar', $totalOut, 'text-rose-600', 'dalam periode'],
                ['Saldo Akhir', $opening + $totalIn - $totalOut, 'text-slate-900', 'per '.$to->translatedFormat('d M Y')],
            ] as [$label, $value, $color, $caption])
                <div class="card p-5">
                    <p class="text-sm text-slate-500">{{ $label }}</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums {{ $color }}">{{ $fmt($value) }} <span class="text-sm font-normal text-slate-500">{{ $unit }}</span></p>
                    <p class="mt-0.5 text-xs text-slate-400">{{ $caption }}</p>
                </div>
            @endforeach
        </div>

        <div class="card">
            <div class="border-b border-slate-100 px-5 py-4">
                <h2 class="font-semibold text-slate-900">{{ $variant->product->name }}{{ $variant->name ? ' / '.$variant->name : '' }}</h2>
                <p class="mt-0.5 font-mono text-sm text-slate-500">{{ $variant->sku }}{{ $variant->barcode ? ' · '.$variant->barcode : '' }}</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold tracking-wider text-slate-500 uppercase">
                        <tr>
                            <th class="px-5 py-3">Tanggal</th>
                            <th class="px-5 py-3">No. Dokumen</th>
                            <th class="px-5 py-3">Jenis</th>
                            <th class="px-5 py-3">Lokasi</th>
                            <th class="px-5 py-3">Referensi</th>
                            <th class="px-5 py-3 text-right">Masuk</th>
                            <th class="px-5 py-3 text-right">Keluar</th>
                            <th class="px-5 py-3 text-right">Saldo</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr class="bg-slate-50/50">
                            <td colspan="7" class="px-5 py-3 font-medium text-slate-600">Saldo awal</td>
                            <td class="px-5 py-3 text-right font-semibold tabular-nums text-slate-900">{{ $fmt($opening) }}</td>
                        </tr>
                        @forelse ($rows as $row)
                            <tr class="hover:bg-slate-50/60">
                                <td class="px-5 py-3.5 whitespace-nowrap text-slate-600">{{ $row->movement_date->format('d/m/Y') }}</td>
                                <td class="px-5 py-3.5 font-mono">
                                    <a href="{{ route('inventory.movements.show', $row->stock_movement_id) }}" class="font-medium text-brand-600 hover:underline">{{ $row->movement->number }}</a>
                                </td>
                                <td class="px-5 py-3.5"><x-inventory.type-badge :type="$row->type" /></td>
                                <td class="px-5 py-3.5 font-mono text-slate-700">{{ $row->warehouse->code }} · {{ $row->location->code }}</td>
                                <td class="px-5 py-3.5 text-slate-600">{{ $row->movement->reference ?? '—' }}</td>
                                <td class="px-5 py-3.5 text-right tabular-nums text-emerald-600">{{ $row->qty_in ? $fmt($row->qty_in) : '' }}</td>
                                <td class="px-5 py-3.5 text-right tabular-nums text-rose-600">{{ $row->qty_out ? $fmt($row->qty_out) : '' }}</td>
                                <td class="px-5 py-3.5 text-right font-semibold tabular-nums text-slate-900">{{ $fmt($row->balance) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-5 py-10 text-center text-sm text-slate-500">Tidak ada pergerakan stok pada periode ini.</td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if ($rows->isNotEmpty())
                        <tfoot class="border-t border-slate-200 bg-slate-50 font-semibold">
                            <tr>
                                <td colspan="5" class="px-5 py-3 text-slate-600">Total periode</td>
                                <td class="px-5 py-3 text-right tabular-nums text-emerald-600">{{ $fmt($totalIn) }}</td>
                                <td class="px-5 py-3 text-right tabular-nums text-rose-600">{{ $fmt($totalOut) }}</td>
                                <td class="px-5 py-3 text-right tabular-nums text-slate-900">{{ $fmt($opening + $totalIn - $totalOut) }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>
    @endif
</x-layouts.app>
