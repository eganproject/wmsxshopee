<x-layouts.app title="Stock Opname">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span>Inventori</span>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Stock Opname</span>
    </x-slot:breadcrumb>

    <x-page-header title="Stock Opname" description="Hitung fisik per gudang atau zona, bandingkan dengan saldo sistem, lalu posting selisihnya." icon="heroicon-o-clipboard-document-check">
        <x-reports.export-button />
        @can('opname.create')
            <a href="{{ route('inventory.counts.create') }}" class="btn-primary"><x-heroicon-o-plus class="size-5" /> Opname Baru</a>
        @endcan
    </x-page-header>

    <div class="card">
        <form method="GET" class="flex flex-col gap-3 border-b border-slate-100 p-4 lg:flex-row lg:items-center">
            <div class="relative flex-1">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-slate-400" />
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari no. opname..." class="form-input pl-8">
            </div>
            <select name="status" class="form-input lg:w-44" onchange="this.form.submit()">
                <option value="">Semua status</option>
                @foreach (\App\Models\StockCount::STATUSES as $value => $meta)
                    <option value="{{ $value }}" @selected(request('status') === $value)>{{ $meta['label'] }}</option>
                @endforeach
            </select>
            <select name="warehouse" class="form-input lg:w-40" onchange="this.form.submit()">
                <option value="">Semua gudang</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}" @selected(request('warehouse') == $warehouse->id)>{{ $warehouse->code }}</option>
                @endforeach
            </select>
            <div class="flex gap-2">
                <button type="submit" class="btn-secondary"><x-heroicon-o-funnel class="size-5" /> Filter</button>
                @if (request()->hasAny(['search', 'status', 'warehouse']))
                    <a href="{{ route('inventory.counts.index') }}" class="btn-icon" title="Reset filter"><x-heroicon-o-x-mark class="size-5" /></a>
                @endif
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                    <tr>
                        <th class="h-8 px-4 py-0 font-normal">No.</th>
                        <th class="h-8 px-4 py-0 font-normal">Tanggal</th>
                        <th class="h-8 px-4 py-0 font-normal">Gudang</th>
                        <th class="h-8 px-4 py-0 font-normal"><span class="whitespace-nowrap">Cakupan <x-hint text="Bagian gudang yang dihitung: seluruh gudang, satu zona, atau hanya stok tanpa bin." /></span></th>
                        <th class="h-8 px-4 py-0 text-right font-normal"><span class="whitespace-nowrap">Dihitung <x-hint text="Jumlah baris yang sudah diisi hasil hitung dibanding total baris di lembar." /></span></th>
                        <th class="h-8 px-4 py-0 font-normal">Dibuat oleh</th>
                        <th class="h-8 px-4 py-0 font-normal"><span class="whitespace-nowrap">Status <x-hint text="Sedang Dihitung: stok belum berubah. Diposting: selisih sudah dicatat sebagai Penyesuaian." /></span></th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($counts as $count)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-4 py-2.5 font-mono font-medium text-slate-900">
                                <a href="{{ route('inventory.counts.show', $count) }}" class="hover:text-brand-600">{{ $count->number }}</a>
                            </td>
                            <td class="px-4 py-2.5 whitespace-nowrap text-slate-600">{{ $count->count_date->format('d/m/Y') }}</td>
                            <td class="px-4 py-2.5 text-slate-600">{{ $count->warehouse->code }}</td>
                            <td class="px-4 py-2.5 text-slate-600">{{ $count->scopeLabel() }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-700">{{ $count->counted_count }} / {{ $count->items_count }} baris</td>
                            <td class="px-4 py-2.5 text-slate-600">{{ $count->creator?->name ?? '—' }}</td>
                            <td class="px-4 py-2.5"><x-inventory.count-status :status="$count->status" /></td>
                            <td class="px-4 py-2.5">
                                <div class="flex justify-end gap-1">
                                    <a href="{{ route('inventory.counts.sheet', $count) }}" target="_blank" class="btn-icon" title="Cetak lembar hitung">
                                        <x-heroicon-o-printer class="size-5" />
                                    </a>
                                    <a href="{{ route('inventory.counts.show', $count) }}" class="btn-icon" title="{{ $count->isDraft() ? 'Isi hasil hitung' : 'Lihat detail' }}">
                                        <x-dynamic-component :component="$count->isDraft() ? 'heroicon-o-pencil-square' : 'heroicon-o-eye'" class="size-5" />
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <x-empty-state colspan="8" icon="heroicon-o-clipboard-document-check" title="Belum ada stock opname" description="Buat lembar opname untuk satu gudang, satu zona, atau stok tanpa bin.">
                            @can('opname.create')
                                <a href="{{ route('inventory.counts.create') }}" class="btn-primary"><x-heroicon-o-plus class="size-5" /> Opname Baru</a>
                            @endcan
                        </x-empty-state>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($counts->hasPages())
            <div class="border-t border-slate-100 px-5 py-4">{{ $counts->links() }}</div>
        @endif
    </div>
</x-layouts.app>
