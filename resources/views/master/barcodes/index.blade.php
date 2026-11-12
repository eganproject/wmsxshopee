<x-layouts.app title="Barcode Alias">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span>Master Data</span>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Barcode</span>
    </x-slot:breadcrumb>

    <x-page-header title="Master Produk" description="Satu SKU bisa punya banyak barcode. Scan barcode alias dikenali sama seperti SKU dan barcode utama di QC, penerimaan, opname dan aplikasi." icon="heroicon-o-cube">
        <x-reports.export-button />
        @can('products.edit')
            <a href="{{ route('master.barcodes.import') }}" class="btn-secondary"><x-heroicon-o-arrow-up-tray class="size-5" /> Impor</a>
        @endcan
    </x-page-header>

    <x-master.product-tabs />
    <x-master.barcode-tabs :open-misses="$openMisses" />

    @can('products.edit')
        <form method="POST" action="{{ route('master.barcodes.store') }}" class="card mb-6 grid gap-4 p-4 md:grid-cols-12 md:items-end">
            @csrf
            <div class="md:col-span-5">
                <label for="product_variant_id" class="form-label">Varian <span class="text-rose-500">*</span></label>
                <select id="product_variant_id" name="product_variant_id" class="form-input" required
                        data-remote="{{ route('master.barcodes.lookup') }}" data-placeholder="Ketik SKU, barcode atau nama varian">
                    <option value="">Pilih varian</option>
                </select>
            </div>
            <div class="md:col-span-3">
                <label for="barcode" class="form-label">Barcode Alias <span class="text-rose-500">*</span> <x-hint text="Huruf, angka, - dan _ (maks. 50). Tidak boleh sama dengan SKU, barcode utama, paket, atau alias lain." /></label>
                <input id="barcode" name="barcode" value="{{ old('barcode') }}" required maxlength="50" autocomplete="off" class="form-input font-mono" placeholder="Scan barcode">
            </div>
            <div class="md:col-span-3">
                <label for="notes" class="form-label">Catatan</label>
                <input id="notes" name="notes" value="{{ old('notes') }}" maxlength="255" class="form-input" placeholder="cth. barcode supplier B">
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
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari barcode, SKU atau nama produk..." class="form-input pl-8">
            </div>
            <div class="flex gap-2">
                <button type="submit" class="btn-secondary"><x-heroicon-o-funnel class="size-5" /> Filter</button>
                @if (request()->filled('search'))
                    <a href="{{ route('master.barcodes.index') }}" class="btn-icon" title="Reset filter"><x-heroicon-o-x-mark class="size-5" /></a>
                @endif
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                    <tr>
                        <th class="h-8 px-4 py-0 font-normal">Barcode Alias</th>
                        <th class="h-8 px-4 py-0 font-normal">SKU</th>
                        <th class="h-8 px-4 py-0 font-normal">Produk / Varian</th>
                        <th class="h-8 px-4 py-0 font-normal"><span class="whitespace-nowrap">Barcode Utama <x-hint text="Barcode di Master Produk. Alias adalah barcode tambahan selain ini." /></span></th>
                        <th class="h-8 px-4 py-0 font-normal">Catatan</th>
                        <th class="h-8 px-4 py-0 font-normal">Ditambahkan</th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($aliases as $alias)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-4 py-2.5 font-mono font-medium text-slate-900">{{ $alias->barcode }}</td>
                            <td class="px-4 py-2.5 font-mono text-slate-700">{{ $alias->variant?->sku }}</td>
                            <td class="cell-wrap px-4 py-2.5 text-slate-700">{{ $alias->variant?->fullName() }}</td>
                            <td class="px-4 py-2.5 font-mono text-slate-500">{{ $alias->variant?->barcode ?? '—' }}</td>
                            <td class="cell-wrap px-4 py-2.5 text-slate-600">{{ $alias->notes ?? '—' }}</td>
                            <td class="px-4 py-2.5 whitespace-nowrap text-slate-600">
                                {{ $alias->created_at?->format('d/m/Y H:i') }}
                                @if ($alias->creator)<p class="text-xs text-slate-500">{{ $alias->creator->name }}</p>@endif
                            </td>
                            <td class="px-4 py-2.5">
                                <div class="flex justify-end">
                                    @can('products.edit')
                                        <form id="delete-alias-{{ $alias->id }}" method="POST" action="{{ route('master.barcodes.destroy', $alias) }}">
                                            @csrf @method('DELETE')
                                        </form>
                                        <button type="button" class="btn-icon hover:bg-rose-50 hover:text-rose-600" title="Hapus"
                                                @click="$dispatch('confirm', {
                                                    form: 'delete-alias-{{ $alias->id }}',
                                                    title: 'Hapus barcode alias?',
                                                    message: @js("Barcode {$alias->barcode} tidak akan dikenali lagi sebagai {$alias->variant?->sku}."),
                                                    confirmText: 'Hapus',
                                                })">
                                            <x-heroicon-o-trash class="size-5" />
                                        </button>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <x-empty-state colspan="7" icon="heroicon-o-qr-code" title="Belum ada barcode alias" description="Tambahkan barcode tambahan untuk SKU yang punya lebih dari satu barcode (cth. beda supplier)." />
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($aliases->hasPages())
            <div class="border-t border-slate-100 px-5 py-4">{{ $aliases->links() }}</div>
        @endif
    </div>
</x-layouts.app>
