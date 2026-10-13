<x-layouts.app :title="$movement->number">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('inventory.movements.index') }}" class="hover:text-slate-700">Mutasi Stok</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">{{ $movement->number }}</span>
    </x-slot:breadcrumb>

    <x-page-header :title="$movement->number" :description="$movement->typeLabel()" :icon="\App\Models\StockMovement::typeMeta($movement->type, 'icon')">
        <a href="{{ route('inventory.movements.index') }}" class="btn-secondary"><x-heroicon-o-arrow-left class="size-5" /> Kembali</a>
    </x-page-header>

    <div class="card mb-6 grid gap-5 p-6 sm:grid-cols-2 lg:grid-cols-4">
        <div>
            <p class="text-xs font-medium tracking-wider text-slate-500 uppercase">Jenis</p>
            <p class="mt-1"><x-inventory.type-badge :type="$movement->type" /></p>
        </div>
        <div>
            <p class="text-xs font-medium tracking-wider text-slate-500 uppercase">Tanggal</p>
            <p class="mt-1 text-sm font-medium text-slate-900">{{ $movement->movement_date->translatedFormat('d F Y') }}</p>
        </div>
        <div>
            <p class="text-xs font-medium tracking-wider text-slate-500 uppercase">Gudang</p>
            <p class="mt-1 text-sm font-medium text-slate-900">{{ $movement->warehouse->code }} · {{ $movement->warehouse->name }}</p>
        </div>
        <div>
            <p class="text-xs font-medium tracking-wider text-slate-500 uppercase">Referensi</p>
            <p class="mt-1 text-sm font-medium text-slate-900">{{ $movement->reference ?? '—' }}</p>
        </div>
        <div>
            <p class="text-xs font-medium tracking-wider text-slate-500 uppercase">Dibuat oleh</p>
            <p class="mt-1 text-sm text-slate-700">{{ $movement->creator?->name ?? '—' }} · {{ $movement->created_at->format('d/m/Y H:i') }}</p>
        </div>
        @if ($movement->notes)
            <div class="sm:col-span-2 lg:col-span-3">
                <p class="text-xs font-medium tracking-wider text-slate-500 uppercase">Catatan</p>
                <p class="mt-1 text-sm whitespace-pre-line text-slate-700">{{ $movement->notes }}</p>
            </div>
        @endif
    </div>

    <div class="card">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs font-semibold tracking-wider text-slate-500 uppercase">
                    <tr>
                        <th class="px-5 py-3">#</th>
                        <th class="px-5 py-3">SKU</th>
                        <th class="px-5 py-3">Produk / Varian</th>
                        @if (in_array($movement->type, ['out', 'transfer']))
                            <th class="px-5 py-3">Lokasi Asal</th>
                        @endif
                        @if ($movement->type !== 'out')
                            <th class="px-5 py-3">{{ $movement->type === 'adjustment' ? 'Lokasi' : 'Lokasi Tujuan' }}</th>
                        @endif
                        <th class="px-5 py-3 text-right">Jumlah</th>
                        <th class="px-5 py-3">Keterangan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($movement->items as $item)
                        <tr>
                            <td class="px-5 py-3.5 text-slate-400">{{ $loop->iteration }}</td>
                            <td class="px-5 py-3.5 font-mono font-medium text-slate-900">{{ $item->variant->sku }}</td>
                            <td class="px-5 py-3.5">
                                <p class="font-medium text-slate-900">{{ $item->variant->product->name }}</p>
                                @if ($item->variant->name)
                                    <p class="text-xs text-slate-500">{{ $item->variant->name }}</p>
                                @endif
                            </td>
                            @if (in_array($movement->type, ['out', 'transfer']))
                                <td class="px-5 py-3.5 font-mono text-slate-700">{{ $item->fromLocation->code }}</td>
                            @endif
                            @if ($movement->type !== 'out')
                                <td class="px-5 py-3.5 font-mono text-slate-700">{{ $item->toLocation->code }}</td>
                            @endif
                            <td @class([
                                'px-5 py-3.5 text-right font-semibold tabular-nums',
                                'text-emerald-600' => $movement->type === 'adjustment' && $item->qty > 0,
                                'text-rose-600' => $movement->type === 'adjustment' && $item->qty < 0,
                                'text-slate-900' => $movement->type !== 'adjustment',
                            ])>
                                {{ $movement->type === 'adjustment' && $item->qty > 0 ? '+' : '' }}{{ number_format($item->qty, 0, ',', '.') }}
                                <span class="text-xs font-normal text-slate-500">{{ $item->variant->product->unit->code }}</span>
                            </td>
                            <td class="px-5 py-3.5 text-slate-600">{{ $item->notes ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <p class="mt-4 flex items-center gap-1.5 text-xs text-slate-500">
        <x-heroicon-o-lock-closed class="size-4" /> Mutasi yang sudah disimpan tidak bisa diubah. Untuk koreksi, catat penyesuaian stok.
    </p>
</x-layouts.app>
