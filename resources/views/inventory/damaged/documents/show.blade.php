@php
    $inputs = $document->items->where('role', 'input');
    $outputs = $document->items->where('role', 'output');
    $fmt = fn ($value) => number_format((int) $value, 0, ',', '.');
    $effect = [
        'intake' => 'Stok baik berkurang dari lokasi asal, stok rusak gudang bertambah.',
        'supplier_return' => 'Stok rusak gudang berkurang. Barang dikirim ke penerima.',
        'disposal' => 'Stok rusak gudang berkurang. Barang dimusnahkan.',
        'repair' => 'Stok rusak gudang berkurang, stok baik bertambah di lokasi tujuan.',
        'assembly' => 'Bahan dari stok rusak / stok baik berkurang, barang hasil masuk stok baik.',
    ][$document->type] ?? null;
@endphp

<x-layouts.app :title="$document->number">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('inventory.damaged.index') }}" class="hover:text-slate-700">Stok Rusak</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('inventory.damaged.documents.index') }}" class="hover:text-slate-700">Dokumen</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">{{ $document->number }}</span>
    </x-slot:breadcrumb>

    <x-page-header :title="$document->number" :description="$document->typeLabel()" :icon="\App\Models\DamagedDocument::typeMeta($document->type, 'icon')">
        <a href="{{ route('inventory.damaged.documents.index') }}" class="btn-secondary"><x-heroicon-o-arrow-left class="size-5" /> Kembali</a>
        @if ($document->isDraft())
            @can(\App\Models\DamagedDocument::typeMeta($document->type, 'permission'))
                <form id="delete-document" method="POST" action="{{ route('inventory.damaged.documents.destroy', $document) }}">@csrf @method('DELETE')</form>
                <button type="button" class="btn-secondary hover:text-rose-600"
                        @click="$dispatch('confirm', { form: 'delete-document', title: 'Hapus draft?', message: @js("Draft {$document->number} akan dihapus permanen."), confirmText: 'Hapus' })">
                    <x-heroicon-o-trash class="size-5" /> Hapus
                </button>
                <a href="{{ route('inventory.damaged.documents.edit', $document) }}" class="btn-secondary"><x-heroicon-o-pencil-square class="size-5" /> Edit</a>
            @endcan
            @can('damaged.approve')
                <form id="approve-document" method="POST" action="{{ route('inventory.damaged.documents.approve', $document) }}">@csrf</form>
                <button type="button" class="btn-primary"
                        @click="$dispatch('confirm', { form: 'approve-document', title: 'Setujui dokumen?', message: @js($effect.' Setelah ini dokumen tidak bisa diubah.'), confirmText: 'Setujui', variant: 'primary' })">
                    <x-heroicon-o-check class="size-5" /> Setujui
                </button>
            @endcan
        @endif
    </x-page-header>

    @if ($document->isDraft())
        <p class="mb-6 flex items-center gap-2 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-700">
            <x-heroicon-o-clock class="size-5 shrink-0" /> Draft: stok belum berubah sampai dokumen ini disetujui.
        </p>
    @endif

    <div class="card mb-6 grid gap-5 p-5 sm:grid-cols-2 lg:grid-cols-4">
        <div>
            <p class="text-xs text-slate-500">Jenis</p>
            <p class="mt-1 flex flex-wrap gap-1.5"><x-damaged.type-badge :type="$document->type" /> <x-damaged.status-badge :document="$document" /></p>
        </div>
        <div>
            <p class="text-xs text-slate-500">Tanggal</p>
            <p class="mt-1 text-sm font-medium text-slate-900">{{ $document->document_date->translatedFormat('d F Y') }}</p>
        </div>
        <div>
            <p class="text-xs text-slate-500">Gudang</p>
            <p class="mt-1 text-sm font-medium text-slate-900">{{ $document->warehouse->code }} · {{ $document->warehouse->name }}</p>
        </div>
        <div>
            <p class="text-xs text-slate-500">Referensi</p>
            <p class="mt-1 text-sm font-medium text-slate-900">{{ $document->reference ?? '—' }}</p>
        </div>
        @if ($document->type === 'supplier_return')
            <div>
                <p class="text-xs text-slate-500">{{ $document->supplier ? 'Supplier' : 'Penerima' }}</p>
                <p class="mt-1 text-sm font-medium text-slate-900">{{ $document->recipientName() ?? '—' }}</p>
            </div>
        @endif
        <div>
            <p class="text-xs text-slate-500">Mutasi Stok Baik</p>
            @if ($document->movement)
                <a href="{{ route('inventory.movements.show', $document->movement) }}" class="mt-1 inline-block font-mono text-sm font-medium text-brand-600 hover:underline">{{ $document->movement->number }}</a>
            @elseif ($document->isDraft())
                <p class="mt-1 text-sm text-slate-400">Dibuat saat disetujui</p>
            @else
                <p class="mt-1 text-sm text-slate-400">Tidak ada (hanya stok rusak)</p>
            @endif
        </div>
        <div>
            <p class="text-xs text-slate-500">Dibuat oleh</p>
            <p class="mt-1 text-sm text-slate-700">{{ $document->creator?->name ?? '—' }} · {{ $document->created_at->format('d/m/Y H:i') }}</p>
        </div>
        <div>
            <p class="text-xs text-slate-500">Disetujui oleh</p>
            @if ($document->approved_at)
                <p class="mt-1 text-sm text-slate-700">{{ $document->approver?->name ?? '—' }} · {{ $document->approved_at->format('d/m/Y H:i') }}</p>
            @else
                <p class="mt-1 text-sm text-slate-400">Belum disetujui</p>
            @endif
        </div>
        @if ($document->notes)
            <div class="sm:col-span-2 lg:col-span-4">
                <p class="text-xs text-slate-500">Catatan</p>
                <p class="mt-1 text-sm whitespace-pre-line text-slate-700">{{ $document->notes }}</p>
            </div>
        @endif
    </div>

    @foreach (array_filter([[$document->type === 'assembly' ? 'Bahan' : 'Barang', $inputs], $outputs->isNotEmpty() ? ['Hasil Rakit / Campur', $outputs] : null]) as [$heading, $rows])
        <div class="card mb-6">
            <div class="border-b border-slate-100 px-5 py-3">
                <h2 class="font-semibold text-slate-900">{{ $heading }}</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                        <tr>
                            <th class="h-8 px-4 py-0 font-normal">#</th>
                            <th class="h-8 px-4 py-0 font-normal">SKU</th>
                            <th class="h-8 px-4 py-0 font-normal">Produk / Varian</th>
                            <th class="h-8 px-4 py-0 font-normal">{{ $heading === 'Hasil Rakit / Campur' ? 'Masuk ke' : 'Diambil dari' }}</th>
                            <th class="h-8 px-4 py-0 text-right font-normal">Jumlah</th>
                            <th class="h-8 px-4 py-0 font-normal">Keterangan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($rows as $item)
                            <tr>
                                <td class="px-4 py-2.5 text-slate-400">{{ $loop->iteration }}</td>
                                <td class="px-4 py-2.5 font-mono font-medium text-slate-900">{{ $item->variant->sku }}</td>
                                <td class="px-4 py-2.5">
                                    <p class="font-medium text-slate-900">{{ $item->variant->product->name }}</p>
                                    @if ($item->variant->name)
                                        <p class="text-xs text-slate-500">{{ $item->variant->name }}</p>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-slate-700">
                                    @if ($item->isOutput() || $document->type === 'repair')
                                        <span class="text-xs text-slate-500">{{ $item->isOutput() ? 'Stok baik' : 'Stok rusak → stok baik' }}</span>
                                        <span class="font-mono">{{ $item->toLocation?->code ?? 'Tanpa bin' }}</span>
                                    @elseif ($item->source === 'good')
                                        <span class="text-xs text-slate-500">Stok baik</span>
                                        <span class="font-mono">{{ $item->fromLocation?->code ?? 'Tanpa bin' }}</span>
                                        @if ($document->type === 'intake')
                                            <span class="text-xs text-slate-500">→ stok rusak</span>
                                        @endif
                                    @else
                                        <span class="text-xs text-slate-500">Stok rusak gudang</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-right font-semibold tabular-nums text-slate-900">
                                    {{ $fmt($item->qty) }} <span class="text-xs font-normal text-slate-500">{{ $item->variant->product->unit?->code }}</span>
                                </td>
                                <td class="px-4 py-2.5 text-slate-600">{{ $item->notes ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach

    <p class="mt-4 flex items-center gap-1.5 text-xs text-slate-500">
        <x-heroicon-o-lock-closed class="size-4" /> {{ $effect }} {{ $document->isDraft() ? 'Terjadi saat dokumen disetujui, lalu dokumen terkunci.' : 'Dokumen yang sudah disetujui tidak bisa diubah.' }}
    </p>

    <x-activity.history :subject="$document" />
</x-layouts.app>
