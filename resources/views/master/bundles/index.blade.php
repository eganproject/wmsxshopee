<x-layouts.app title="Produk Paket">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span>Master Data</span>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Produk Paket</span>
    </x-slot:breadcrumb>

    <x-page-header title="Master Produk" description="Produk paket: satu SKU jual berisi beberapa barang gudang. Stoknya dihitung dari isi paket." icon="heroicon-o-cube">
        <x-reports.export-button />
        @can('products.create')
            <a href="{{ route('master.bundles.create') }}" class="btn-primary">
                <x-heroicon-o-plus class="size-5" /> Tambah Paket
            </a>
        @endcan
    </x-page-header>

    <x-master.product-tabs />

    <div class="card" x-data="{ selected: [] }">
        <form method="GET" class="flex flex-col gap-3 border-b border-slate-100 p-4 md:flex-row md:items-center">
            <div class="relative flex-1">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute top-1/2 left-3.5 size-5 -translate-y-1/2 text-slate-400" />
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari SKU paket, nama, SKU isi atau scan barcode..." class="form-input pl-11">
            </div>
            @if ($warehouses->count() > 1)
                <select name="warehouse" class="form-input md:w-56" onchange="this.form.submit()" title="Gudang untuk menghitung stok tersedia">
                    @foreach ($warehouses as $option)
                        <option value="{{ $option->id }}" @selected($warehouse?->id === $option->id)>Stok di {{ $option->code }}</option>
                    @endforeach
                </select>
            @endif
            <select name="status" class="form-input md:w-40" onchange="this.form.submit()">
                <option value="">Semua status</option>
                <option value="active" @selected(request('status') === 'active')>Aktif</option>
                <option value="inactive" @selected(request('status') === 'inactive')>Nonaktif</option>
            </select>
            <div class="flex gap-2">
                <button type="submit" class="btn-secondary"><x-heroicon-o-funnel class="size-5" /> Filter</button>
                @if (request()->hasAny(['search', 'status', 'warehouse']))
                    <a href="{{ route('master.bundles.index') }}" class="btn-icon" title="Reset filter"><x-heroicon-o-x-mark class="size-5" /></a>
                @endif
            </div>
        </form>

        <x-labels.selection-bar route="labels.products" param="bundles" />

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs font-semibold tracking-wider text-slate-500 uppercase">
                    <tr>
                        <x-labels.select-all :ids="$bundles->pluck('id')" />
                        <th class="px-5 py-3">Paket</th>
                        <th class="px-5 py-3">Isi per Paket</th>
                        <th class="px-5 py-3 text-right">Harga Jual</th>
                        <th class="px-5 py-3 text-right" title="Jumlah paket yang bisa dipenuhi dari stok tersedia isi paket">
                            Tersedia{{ $warehouse && $warehouses->count() > 1 ? ' di '.$warehouse->code : '' }}
                        </th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($bundles as $bundle)
                        <tr class="align-top hover:bg-slate-50/60">
                            <td class="w-10 py-3.5 pr-0 pl-4"><input type="checkbox" class="form-checkbox block" value="{{ $bundle->id }}" x-model="selected" aria-label="Pilih"></td>
                            <td class="px-5 py-3.5">
                                <p class="font-mono font-medium text-slate-900">{{ $bundle->sku }}</p>
                                <p class="text-slate-600">{{ $bundle->name }}</p>
                                @if ($bundle->barcode)
                                    <p class="flex items-center gap-1 text-xs text-slate-400"><x-heroicon-o-qr-code class="size-3.5" /> {{ $bundle->barcode }}</p>
                                @endif
                            </td>
                            <td class="px-5 py-3.5">
                                @foreach ($bundle->items as $item)
                                    <p class="text-xs whitespace-nowrap">
                                        <span class="font-medium tabular-nums text-slate-900">{{ $item->qty }}×</span>
                                        <span class="font-mono text-slate-700">{{ $item->variant->sku }}</span>
                                        <span class="text-slate-500">{{ $item->variant->product->name }}{{ $item->variant->name ? ' / '.$item->variant->name : '' }}</span>
                                    </p>
                                @endforeach
                            </td>
                            <td class="px-5 py-3.5 text-right tabular-nums text-slate-700">Rp {{ number_format((float) $bundle->price, 0, ',', '.') }}</td>
                            <td class="px-5 py-3.5 text-right">
                                @php($count = $available[$bundle->id] ?? 0)
                                <span @class(['font-semibold tabular-nums', 'text-slate-900' => $count > 0, 'text-rose-600' => $count <= 0])>{{ number_format($count, 0, ',', '.') }}</span>
                                <span class="text-xs text-slate-500">paket</span>
                            </td>
                            <td class="px-5 py-3.5"><x-status-badge :active="$bundle->is_active" /></td>
                            <td class="px-5 py-3.5">
                                <div class="flex items-center justify-end gap-1">
                                    <a href="{{ route('labels.products', ['bundles' => [$bundle->id]]) }}" class="btn-icon" title="Cetak label">
                                        <x-heroicon-o-qr-code class="size-5" />
                                    </a>
                                    @can('products.edit')
                                        <a href="{{ route('master.bundles.edit', $bundle) }}" class="btn-icon" title="Edit">
                                            <x-heroicon-o-pencil-square class="size-5" />
                                        </a>
                                    @endcan
                                    @can('products.delete')
                                        <form id="delete-{{ $bundle->id }}" method="POST" action="{{ route('master.bundles.destroy', $bundle) }}">
                                            @csrf @method('DELETE')
                                        </form>
                                        <button type="button" class="btn-icon hover:bg-rose-50 hover:text-rose-600" title="Hapus"
                                                @click="$dispatch('confirm', {
                                                    form: 'delete-{{ $bundle->id }}',
                                                    title: 'Hapus paket?',
                                                    message: @js("Paket {$bundle->name} akan dihapus permanen. Stok barang isinya tidak berubah."),
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
                                <x-heroicon-o-gift class="mx-auto size-12 text-slate-300" />
                                <p class="mt-3 font-medium text-slate-700">Belum ada produk paket</p>
                                <p class="text-sm text-slate-500">Buat paket seperti "Beli 3 Kaos" yang isinya beberapa varian gudang.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($bundles->hasPages())
            <div class="border-t border-slate-100 px-5 py-4">{{ $bundles->links() }}</div>
        @endif
    </div>
</x-layouts.app>
