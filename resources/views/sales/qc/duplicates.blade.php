<x-layouts.app title="Scan Resi Ganda">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('sales.qc.index') }}" class="hover:text-slate-700">QC Kemas</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Scan Resi Ganda</span>
    </x-slot:breadcrumb>

    <x-page-header title="QC Kemas" description="Resi yang di-scan lagi di QC kemas atau serah terima padahal sudah lolos QC / sudah dikirim. Cek kemungkinan paket dobel atau label tercetak ulang." icon="heroicon-o-viewfinder-circle">
        <x-reports.export-button />
    </x-page-header>

    <x-sales.qc-tabs />

    <div class="card">
        <form method="GET" class="flex flex-col gap-3 border-b border-slate-100 p-4 lg:flex-row lg:items-center">
            <div class="relative flex-1">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-slate-400" />
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari resi atau no. SO..." class="form-input pl-8">
            </div>
            <select name="context" class="form-input lg:w-44" onchange="this.form.submit()" aria-label="Tempat scan">
                <option value="">Semua tempat scan</option>
                @foreach (\App\Models\ScanDuplicateAttempt::CONTEXTS as $key => $label)
                    <option value="{{ $key }}" @selected(request('context') === $key)>{{ $label }}</option>
                @endforeach
            </select>
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
                @if (request()->hasAny(['search', 'context', 'warehouse', 'from', 'to']))
                    <a href="{{ route('sales.qc.duplicates') }}" class="btn-icon" title="Reset filter"><x-heroicon-o-x-mark class="size-5" /></a>
                @endif
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                    <tr>
                        <th class="h-8 px-4 py-0 font-normal">Waktu</th>
                        <th class="h-8 px-4 py-0 font-normal">Kode Di-scan</th>
                        <th class="h-8 px-4 py-0 font-normal">Tempat Scan</th>
                        <th class="h-8 px-4 py-0 font-normal">Pesanan</th>
                        <th class="h-8 px-4 py-0 font-normal"><span class="whitespace-nowrap">Status Saat Itu <x-hint text="Status pesanan ketika resinya di-scan ulang." /></span></th>
                        <th class="h-8 px-4 py-0 font-normal">Gudang</th>
                        <th class="h-8 px-4 py-0 font-normal">Oleh</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($rows as $row)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-4 py-2.5 whitespace-nowrap text-slate-600">{{ $row->created_at->format('d/m/Y H:i:s') }}</td>
                            <td class="px-4 py-2.5 font-mono font-medium text-slate-900">{{ $row->code }}</td>
                            <td class="px-4 py-2.5 text-slate-700">{{ $row->contextLabel() }}</td>
                            <td class="px-4 py-2.5 font-mono">
                                @if ($row->order)
                                    @can('sales.view')
                                        <a href="{{ route('sales.orders.show', $row->order) }}" class="text-brand-700 hover:underline">{{ $row->order->number }}</a>
                                    @else
                                        {{ $row->order->number }}
                                    @endcan
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-slate-700">{{ \App\Models\SalesOrder::STATUSES[$row->order_status]['label'] ?? $row->order_status }}</td>
                            <td class="px-4 py-2.5 text-slate-600">{{ $row->warehouse?->code ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-slate-600">{{ $row->user?->name ?? '—' }}</td>
                        </tr>
                    @empty
                        <x-empty-state colspan="7" icon="heroicon-o-document-duplicate" title="Tidak ada scan ganda" description="Belum ada resi yang di-scan ulang pada rentang tanggal ini." />
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($rows->hasPages())
            <div class="border-t border-slate-100 px-5 py-4">{{ $rows->links() }}</div>
        @endif
    </div>
</x-layouts.app>
