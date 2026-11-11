<x-layouts.app title="Substitusi SKU">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('sales.qc.index') }}" class="hover:text-slate-700">QC Kemas</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Substitusi SKU</span>
    </x-slot:breadcrumb>

    <x-page-header title="QC Kemas" description="Riwayat barang pesanan yang diganti SKU lain saat QC, beserta alasan dan petugasnya." icon="heroicon-o-viewfinder-circle">
        <x-reports.export-button />
    </x-page-header>

    <x-sales.qc-tabs />

    <div class="card">
        <form method="GET" class="flex flex-col gap-3 border-b border-slate-100 p-4 lg:flex-row lg:items-center">
            <div class="relative flex-1">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-slate-400" />
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari no. SO, resi, SKU atau alasan..." class="form-input pl-8">
            </div>
            <select name="warehouse" class="form-input lg:w-48" onchange="this.form.submit()" aria-label="Gudang">
                <option value="">Semua gudang</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}" @selected(request('warehouse') == $warehouse->id)>{{ $warehouse->code }} · {{ $warehouse->name }}</option>
                @endforeach
            </select>
            <input type="date" name="from" value="{{ $from->toDateString() }}" class="form-input lg:w-40" title="Dari tanggal">
            <input type="date" name="to" value="{{ $to->toDateString() }}" class="form-input lg:w-40" title="Sampai tanggal">
            <div class="flex gap-2">
                <button type="submit" class="btn-secondary"><x-heroicon-o-funnel class="size-5" /> Filter</button>
                @if (request()->hasAny(['search', 'warehouse', 'from', 'to']))
                    <a href="{{ route('sales.qc.substitutions') }}" class="btn-icon" title="Reset filter"><x-heroicon-o-x-mark class="size-5" /></a>
                @endif
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                    <tr>
                        <th class="h-8 px-4 py-0 font-normal">Waktu</th>
                        <th class="h-8 px-4 py-0 font-normal">Pesanan</th>
                        <th class="h-8 px-4 py-0 font-normal">SKU Asal</th>
                        <th class="h-8 px-4 py-0 font-normal">SKU Pengganti</th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Qty</th>
                        <th class="h-8 px-4 py-0 font-normal">Alasan</th>
                        <th class="h-8 px-4 py-0 font-normal">Oleh</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($rows as $row)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-4 py-2.5 whitespace-nowrap text-slate-600">{{ $row->created_at->format('d/m/Y H:i') }}</td>
                            <td class="px-4 py-2.5 font-mono">
                                @can('sales.view')
                                    <a href="{{ route('sales.orders.show', $row->order) }}" class="font-medium text-brand-700 hover:underline">{{ $row->order?->number }}</a>
                                @else
                                    {{ $row->order?->number }}
                                @endcan
                                <p class="text-xs text-slate-500">{{ $row->order?->tracking_number ?? '—' }} · {{ $row->order?->warehouse?->code }}</p>
                            </td>
                            <td class="px-4 py-2.5"><p class="font-mono text-slate-700">{{ $row->fromVariant?->sku }}</p><p class="text-xs text-slate-500">{{ $row->fromVariant?->fullName() }}</p></td>
                            <td class="px-4 py-2.5"><p class="font-mono font-medium text-slate-900">{{ $row->toVariant?->sku }}</p><p class="text-xs text-slate-500">{{ $row->toVariant?->fullName() }}</p></td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-700">{{ $row->qty }}</td>
                            <td class="cell-wrap px-4 py-2.5 text-slate-700">{{ $row->reason }}</td>
                            <td class="px-4 py-2.5 text-slate-600">{{ $row->user?->name ?? '—' }}</td>
                        </tr>
                    @empty
                        <x-empty-state colspan="7" icon="heroicon-o-arrows-right-left" title="Belum ada substitusi" description="Penggantian SKU saat QC akan tercatat di sini." />
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($rows->hasPages())
            <div class="border-t border-slate-100 px-5 py-4">{{ $rows->links() }}</div>
        @endif
    </div>
</x-layouts.app>
