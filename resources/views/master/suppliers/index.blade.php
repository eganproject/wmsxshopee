<x-layouts.app title="Master Supplier">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span>Master Data</span>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Supplier</span>
    </x-slot:breadcrumb>

    <x-page-header title="Master Supplier" description="Pemasok barang yang dipakai di penerimaan barang." icon="heroicon-o-building-storefront">
        <x-reports.export-button />
        @can('suppliers.create')
            <a href="{{ route('master.suppliers.create') }}" class="btn-primary">
                <x-heroicon-o-plus class="size-5" /> Tambah Supplier
            </a>
        @endcan
    </x-page-header>

    <div class="card">
        <form method="GET" class="flex flex-col gap-3 border-b border-slate-100 p-4 md:flex-row md:items-center">
            <div class="relative flex-1">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-slate-400" />
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari kode, nama, kontak atau kota..." class="form-input pl-8">
            </div>
            <select name="status" class="form-input md:w-40" onchange="this.form.submit()">
                <option value="">Semua status</option>
                <option value="active" @selected(request('status') === 'active')>Aktif</option>
                <option value="inactive" @selected(request('status') === 'inactive')>Nonaktif</option>
            </select>
            <div class="flex gap-2">
                <button type="submit" class="btn-secondary"><x-heroicon-o-funnel class="size-5" /> Filter</button>
                @if (request()->hasAny(['search', 'status']))
                    <a href="{{ route('master.suppliers.index') }}" class="btn-icon" title="Reset filter"><x-heroicon-o-x-mark class="size-5" /></a>
                @endif
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                    <tr>
                        <th class="h-8 px-4 py-0 font-normal">Supplier</th>
                        <th class="h-8 px-4 py-0 font-normal">Kontak</th>
                        <th class="h-8 px-4 py-0 font-normal">Kota</th>
                        <th class="h-8 px-4 py-0 font-normal">Termin</th>
                        <th class="h-8 px-4 py-0 font-normal text-right">Penerimaan</th>
                        <th class="h-8 px-4 py-0 font-normal">Status</th>
                        <th class="h-8 px-4 py-0 font-normal text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($suppliers as $supplier)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-4 py-2.5">
                                <p class="font-medium text-slate-900">{{ $supplier->name }}</p>
                                <p class="font-mono text-xs text-slate-500">{{ $supplier->code }}</p>
                            </td>
                            <td class="px-4 py-2.5 text-slate-600">
                                {{ $supplier->contact_name ?? '—' }}
                                @if ($supplier->phone)
                                    <p class="text-xs text-slate-400">{{ $supplier->phone }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-slate-600">{{ $supplier->city ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-slate-600">{{ $supplier->paymentTermLabel() ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-600">{{ number_format($supplier->receipts_count, 0, ',', '.') }}</td>
                            <td class="px-4 py-2.5"><x-status-badge :active="$supplier->is_active" /></td>
                            <td class="px-4 py-2.5">
                                <div class="flex items-center justify-end gap-1">
                                    @can('suppliers.edit')
                                        <a href="{{ route('master.suppliers.edit', $supplier) }}" class="btn-icon" title="Edit">
                                            <x-heroicon-o-pencil-square class="size-5" />
                                        </a>
                                    @endcan
                                    @can('suppliers.delete')
                                        <form id="delete-{{ $supplier->id }}" method="POST" action="{{ route('master.suppliers.destroy', $supplier) }}">
                                            @csrf @method('DELETE')
                                        </form>
                                        <button type="button" class="btn-icon hover:bg-rose-50 hover:text-rose-600" title="Hapus"
                                                @click="$dispatch('confirm', {
                                                    form: 'delete-{{ $supplier->id }}',
                                                    title: 'Hapus supplier?',
                                                    message: @js("Supplier {$supplier->name} akan dihapus permanen."),
                                                    confirmText: 'Hapus',
                                                })">
                                            <x-heroicon-o-trash class="size-5" />
                                        </button>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-5 py-16 text-center">
                                <x-heroicon-o-building-storefront class="mx-auto size-12 text-slate-300" />
                                <p class="mt-3 font-medium text-slate-700">Belum ada supplier</p>
                                <p class="text-sm text-slate-500">Tambahkan pemasok sebelum mencatat penerimaan barang.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($suppliers->hasPages())
            <div class="border-t border-slate-100 px-5 py-4">{{ $suppliers->links() }}</div>
        @endif
    </div>
</x-layouts.app>
