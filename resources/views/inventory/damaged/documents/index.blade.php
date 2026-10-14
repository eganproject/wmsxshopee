<x-layouts.app title="Dokumen Barang Rusak">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('inventory.damaged.index') }}" class="hover:text-slate-700">Stok Rusak</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Dokumen</span>
    </x-slot:breadcrumb>

    <x-page-header title="Dokumen Barang Rusak" description="Pindah ke rusak, retur ke supplier, pemusnahan, perbaikan dan rakit / campur." icon="heroicon-o-document-text">
        <x-reports.export-button />
        <x-damaged.create-menu :params="request()->only('warehouse')" />
    </x-page-header>

    <x-damaged.tabs />

    <div class="card">
        <form method="GET" class="flex flex-col gap-3 border-b border-slate-100 p-4 lg:flex-row lg:items-center">
            <div class="relative flex-1 lg:min-w-64">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-slate-400" />
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari no. dokumen, referensi atau penerima..." class="form-input pl-8">
            </div>
            <select name="type" class="form-input lg:w-44" onchange="this.form.submit()">
                <option value="">Semua jenis</option>
                @foreach ($types as $value => $meta)
                    <option value="{{ $value }}" @selected(request('type') === $value)>{{ $meta['label'] }}</option>
                @endforeach
            </select>
            <select name="status" class="form-input lg:w-36" onchange="this.form.submit()">
                <option value="">Semua status</option>
                @foreach (\App\Models\DamagedDocument::STATUSES as $value => $meta)
                    <option value="{{ $value }}" @selected(request('status') === $value)>{{ $meta['label'] }}</option>
                @endforeach
            </select>
            <select name="warehouse" class="form-input lg:w-36" onchange="this.form.submit()">
                <option value="">Semua gudang</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}" @selected(request('warehouse') == $warehouse->id)>{{ $warehouse->code }}</option>
                @endforeach
            </select>
            <input type="date" name="from" value="{{ request('from') }}" class="form-input lg:w-36" title="Dari tanggal">
            <input type="date" name="to" value="{{ request('to') }}" class="form-input lg:w-36" title="Sampai tanggal">
            <div class="flex gap-2">
                <button type="submit" class="btn-secondary"><x-heroicon-o-funnel class="size-5" /> Filter</button>
                @if (request()->hasAny(['search', 'type', 'status', 'warehouse', 'from', 'to']))
                    <a href="{{ route('inventory.damaged.documents.index') }}" class="btn-icon" title="Reset filter"><x-heroicon-o-x-mark class="size-5" /></a>
                @endif
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                    <tr>
                        <th class="h-8 px-4 py-0 font-normal">No.</th>
                        <th class="h-8 px-4 py-0 font-normal">Tanggal</th>
                        <th class="h-8 px-4 py-0 font-normal">Jenis</th>
                        <th class="h-8 px-4 py-0 font-normal">Gudang</th>
                        <th class="h-8 px-4 py-0 font-normal">Penerima</th>
                        <th class="h-8 px-4 py-0 font-normal">Referensi</th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Baris</th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Total Qty</th>
                        <th class="h-8 px-4 py-0 font-normal">Status</th>
                        <th class="h-8 px-4 py-0 font-normal">Dibuat oleh</th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($documents as $document)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-4 py-2.5 font-mono font-medium text-slate-900">
                                <a href="{{ route('inventory.damaged.documents.show', $document) }}" class="hover:text-brand-600">{{ $document->number }}</a>
                            </td>
                            <td class="px-4 py-2.5 whitespace-nowrap text-slate-600">{{ $document->document_date->format('d/m/Y') }}</td>
                            <td class="px-4 py-2.5"><x-damaged.type-badge :type="$document->type" /></td>
                            <td class="px-4 py-2.5 text-slate-600">{{ $document->warehouse->code }}</td>
                            <td class="px-4 py-2.5 text-slate-700">{{ $document->recipientName() ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-slate-600">{{ $document->reference ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-700">{{ $document->items_count }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-700">{{ number_format((int) $document->items_sum_qty, 0, ',', '.') }}</td>
                            <td class="px-4 py-2.5"><x-damaged.status-badge :document="$document" /></td>
                            <td class="px-4 py-2.5 text-slate-600">{{ $document->creator?->name ?? '—' }}</td>
                            <td class="px-4 py-2.5">
                                <div class="flex justify-end gap-1">
                                    @if ($document->isDraft())
                                        @can(\App\Models\DamagedDocument::typeMeta($document->type, 'permission'))
                                            <a href="{{ route('inventory.damaged.documents.edit', $document) }}" class="btn-icon" title="Edit">
                                                <x-heroicon-o-pencil-square class="size-5" />
                                            </a>
                                        @endcan
                                    @endif
                                    <a href="{{ route('inventory.damaged.documents.show', $document) }}" class="btn-icon" title="Lihat detail">
                                        <x-heroicon-o-eye class="size-5" />
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <x-empty-state colspan="11" icon="heroicon-o-document-text" title="Belum ada dokumen barang rusak" description="Alokasikan stok rusak lewat tombol Catat Dokumen." :ignore="['warehouse']" />
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($documents->hasPages())
            <div class="border-t border-slate-100 px-5 py-4">{{ $documents->links() }}</div>
        @endif
    </div>
</x-layouts.app>
