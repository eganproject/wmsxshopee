<x-layouts.app title="SKU Tanpa Scan QC">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('sales.qc.index') }}" class="hover:text-slate-700">QC Kemas</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">SKU Tanpa Scan</span>
    </x-slot:breadcrumb>

    <x-page-header title="QC Kemas" description="SKU di daftar ini tidak perlu di-scan saat QC kemas (cth. hadiah, bonus, bahan kemasan). Barisnya otomatis dianggap cocok, stok tetap keluar seperti biasa." icon="heroicon-o-viewfinder-circle" />

    <x-sales.qc-tabs />

    @can('sales.qc_settings')
        <form method="POST" action="{{ route('sales.qc.exempt.store') }}" class="card mb-6 grid gap-4 p-4 md:grid-cols-12 md:items-end">
            @csrf
            <div class="md:col-span-6">
                <label for="product_variant_id" class="form-label">Varian <span class="text-rose-500">*</span></label>
                <select id="product_variant_id" name="product_variant_id" class="form-input" required
                        data-remote="{{ route('sales.qc.exempt.variants') }}" data-placeholder="Ketik SKU, barcode atau nama varian">
                    <option value="">Pilih varian</option>
                </select>
            </div>
            <div class="md:col-span-5">
                <label for="reason" class="form-label">Alasan</label>
                <input id="reason" name="reason" value="{{ old('reason') }}" maxlength="255" class="form-input" placeholder="cth. hadiah, tidak ada barcode">
            </div>
            <div class="md:col-span-1">
                <button type="submit" class="btn-primary w-full justify-center"><x-heroicon-o-plus class="size-5" /> Tambah</button>
            </div>
        </form>
    @endcan

    <div class="card">
        <form method="GET" class="flex flex-col gap-3 border-b border-slate-100 p-4 md:flex-row md:items-center">
            <div class="relative flex-1">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-slate-400" />
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari SKU, produk atau alasan..." class="form-input pl-8">
            </div>
            <div class="flex gap-2">
                <button type="submit" class="btn-secondary"><x-heroicon-o-funnel class="size-5" /> Filter</button>
                @if (request()->filled('search'))
                    <a href="{{ route('sales.qc.exempt') }}" class="btn-icon" title="Reset filter"><x-heroicon-o-x-mark class="size-5" /></a>
                @endif
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                    <tr>
                        <th class="h-8 px-4 py-0 font-normal">SKU</th>
                        <th class="h-8 px-4 py-0 font-normal">Produk / Varian</th>
                        <th class="h-8 px-4 py-0 font-normal">Alasan</th>
                        <th class="h-8 px-4 py-0 font-normal">Ditambahkan</th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($rows as $row)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-4 py-2.5 font-mono font-medium text-slate-900">{{ $row->variant?->sku }}</td>
                            <td class="cell-wrap px-4 py-2.5 text-slate-700">{{ $row->variant?->fullName() }}</td>
                            <td class="cell-wrap px-4 py-2.5 text-slate-600">{{ $row->reason ?? '—' }}</td>
                            <td class="px-4 py-2.5 whitespace-nowrap text-slate-600">{{ $row->created_at?->format('d/m/Y H:i') }}
                                @if ($row->creator)<p class="text-xs text-slate-500">{{ $row->creator->name }}</p>@endif
                            </td>
                            <td class="px-4 py-2.5">
                                <div class="flex justify-end">
                                    @can('sales.qc_settings')
                                        <form id="delete-exempt-{{ $row->id }}" method="POST" action="{{ route('sales.qc.exempt.destroy', $row) }}">
                                            @csrf @method('DELETE')
                                        </form>
                                        <button type="button" class="btn-icon hover:bg-rose-50 hover:text-rose-600" title="Hapus dari daftar"
                                                @click="$dispatch('confirm', {
                                                    form: 'delete-exempt-{{ $row->id }}',
                                                    title: 'Wajibkan scan lagi?',
                                                    message: @js("{$row->variant?->sku} akan kembali wajib di-scan saat QC kemas."),
                                                    confirmText: 'Hapus dari daftar',
                                                })">
                                            <x-heroicon-o-trash class="size-5" />
                                        </button>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <x-empty-state colspan="5" icon="heroicon-o-no-symbol" title="Semua SKU wajib di-scan" description="Tambahkan SKU yang tidak perlu di-scan saat QC, cth. hadiah atau bahan kemasan." />
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($rows->hasPages())
            <div class="border-t border-slate-100 px-5 py-4">{{ $rows->links() }}</div>
        @endif
    </div>
</x-layouts.app>
