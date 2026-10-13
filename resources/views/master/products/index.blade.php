<x-layouts.app title="Master Produk">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span>Master Data</span>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Produk</span>
    </x-slot:breadcrumb>

    <x-page-header title="Master Produk" description="SKU internal, varian, barcode, berat dan dimensi barang." icon="heroicon-o-cube">
        @can('products.create')
            <a href="{{ route('master.products.create') }}" class="btn-primary">
                <x-heroicon-o-plus class="size-5" /> Tambah Produk
            </a>
        @endcan
    </x-page-header>

    <x-master.product-tabs />

    <div class="card">
        <form method="GET" class="flex flex-col gap-3 border-b border-slate-100 p-4 md:flex-row md:items-center">
            <div class="relative flex-1">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute top-1/2 left-3.5 size-5 -translate-y-1/2 text-slate-400" />
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari nama, SKU, merek atau scan barcode..." class="form-input pl-11">
            </div>
            <select name="category" class="form-input md:w-48" onchange="this.form.submit()">
                <option value="">Semua kategori</option>
                @foreach ($categories as $category)
                    <option value="{{ $category }}" @selected(request('category') === $category)>{{ $category }}</option>
                @endforeach
            </select>
            <select name="status" class="form-input md:w-40" onchange="this.form.submit()">
                <option value="">Semua status</option>
                <option value="active" @selected(request('status') === 'active')>Aktif</option>
                <option value="inactive" @selected(request('status') === 'inactive')>Nonaktif</option>
            </select>
            <div class="flex gap-2">
                <button type="submit" class="btn-secondary"><x-heroicon-o-funnel class="size-5" /> Filter</button>
                @if (request()->hasAny(['search', 'category', 'status']))
                    <a href="{{ route('master.products.index') }}" class="btn-icon" title="Reset filter"><x-heroicon-o-x-mark class="size-5" /></a>
                @endif
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs font-semibold tracking-wider text-slate-500 uppercase">
                    <tr>
                        <th class="px-5 py-3">Produk</th>
                        <th class="px-5 py-3">Kategori / Merek</th>
                        <th class="px-5 py-3 text-center">Varian</th>
                        <th class="px-5 py-3">Satuan</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($products as $product)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-5 py-3.5">
                                <div class="flex items-center gap-3">
                                    @if ($product->primaryImage)
                                        <img src="{{ $product->primaryImage->url() }}" alt="{{ $product->name }}" loading="lazy"
                                             class="size-11 shrink-0 rounded-lg object-cover ring-1 ring-slate-200">
                                    @else
                                        <span class="flex size-11 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-400">
                                            <x-heroicon-o-photo class="size-5" />
                                        </span>
                                    @endif
                                    <div class="min-w-0">
                                        <p class="font-medium text-slate-900">{{ $product->name }}</p>
                                        <p class="font-mono text-xs text-slate-500">{{ $product->sku }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-3.5 text-slate-600">
                                {{ $product->category ?? '—' }}
                                @if ($product->brand)
                                    <p class="text-xs text-slate-400">{{ $product->brand }}</p>
                                @endif
                            </td>
                            <td class="px-5 py-3.5 text-center">
                                @if ($product->has_variants)
                                    <span class="rounded-full bg-brand-50 px-2.5 py-0.5 text-xs font-medium text-brand-700 ring-1 ring-brand-100">{{ $product->variants_count }} varian</span>
                                @else
                                    <span class="text-xs text-slate-400">Tanpa varian</span>
                                @endif
                            </td>
                            <td class="px-5 py-3.5 font-mono text-xs text-slate-600">{{ $product->unit?->code }}</td>
                            <td class="px-5 py-3.5"><x-status-badge :active="$product->is_active" /></td>
                            <td class="px-5 py-3.5">
                                <div class="flex items-center justify-end gap-1">
                                    @can('products.edit')
                                        <a href="{{ route('master.products.edit', $product) }}" class="btn-icon" title="Edit">
                                            <x-heroicon-o-pencil-square class="size-5" />
                                        </a>
                                    @endcan
                                    @can('products.delete')
                                        <form id="delete-{{ $product->id }}" method="POST" action="{{ route('master.products.destroy', $product) }}">
                                            @csrf @method('DELETE')
                                        </form>
                                        <button type="button" class="btn-icon hover:bg-rose-50 hover:text-rose-600" title="Hapus"
                                                @click="$dispatch('confirm', {
                                                    form: 'delete-{{ $product->id }}',
                                                    title: 'Hapus produk?',
                                                    message: @js("Produk {$product->name} beserta varian dan gambarnya akan dihapus permanen."),
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
                            <td colspan="6" class="px-5 py-16 text-center">
                                <x-heroicon-o-cube class="mx-auto size-12 text-slate-300" />
                                <p class="mt-3 font-medium text-slate-700">Belum ada produk</p>
                                <p class="text-sm text-slate-500">Tambahkan produk beserta SKU dan variannya.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($products->hasPages())
            <div class="border-t border-slate-100 px-5 py-4">{{ $products->links() }}</div>
        @endif
    </div>
</x-layouts.app>
