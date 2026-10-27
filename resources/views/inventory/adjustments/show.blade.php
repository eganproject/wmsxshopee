@php
    $fmt = fn ($value) => number_format((int) $value, 0, ',', '.');
    $signed = fn ($value) => ($value > 0 ? '+' : ($value < 0 ? '−' : '')).number_format(abs((int) $value), 0, ',', '.');
    $open = in_array($adjustment->status, ['draft', 'pending'], true);
    $notices = [
        'draft' => ['bg-slate-100 text-slate-700', 'heroicon-o-document', 'Draft: stok belum berubah. Ajukan supaya bisa disetujui.'],
        'pending' => ['bg-amber-50 text-amber-700', 'heroicon-o-clock', 'Menunggu approval: stok belum berubah sampai penyesuaian ini disetujui.'],
        'approved' => ['bg-emerald-50 text-emerald-700', 'heroicon-o-check-circle', 'Disetujui: stok sudah disesuaikan dan dokumen terkunci.'],
        'rejected' => ['bg-rose-50 text-rose-700', 'heroicon-o-x-circle', 'Ditolak: stok tidak berubah dan dokumen terkunci.'],
    ][$adjustment->status];
@endphp

<x-layouts.app :title="$adjustment->number">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('inventory.adjustments.index') }}" class="hover:text-slate-700">Penyesuaian Stok</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">{{ $adjustment->number }}</span>
    </x-slot:breadcrumb>

    <x-page-header :title="$adjustment->number" :description="$adjustment->reasonLabel()" icon="heroicon-o-adjustments-horizontal">
        <a href="{{ route('inventory.adjustments.index') }}" class="btn-secondary"><x-heroicon-o-arrow-left class="size-5" /> Kembali</a>
        @if ($adjustment->isDraft())
            @can('adjustments.create')
                <form id="delete-adjustment" method="POST" action="{{ route('inventory.adjustments.destroy', $adjustment) }}">@csrf @method('DELETE')</form>
                <button type="button" class="btn-secondary hover:text-rose-600"
                        @click="$dispatch('confirm', { form: 'delete-adjustment', title: 'Hapus draft?', message: @js("Draft {$adjustment->number} akan dihapus permanen."), confirmText: 'Hapus' })">
                    <x-heroicon-o-trash class="size-5" /> Hapus
                </button>
                <a href="{{ route('inventory.adjustments.edit', $adjustment) }}" class="btn-secondary"><x-heroicon-o-pencil-square class="size-5" /> Edit</a>
                <form method="POST" action="{{ route('inventory.adjustments.submit', $adjustment) }}">
                    @csrf
                    <button type="submit" class="btn-primary"><x-heroicon-o-paper-airplane class="size-5" /> Ajukan</button>
                </form>
            @endcan
        @elseif ($adjustment->isPending())
            @can('adjustments.create')
                <form method="POST" action="{{ route('inventory.adjustments.recall', $adjustment) }}">
                    @csrf
                    <button type="submit" class="btn-secondary" title="Kembalikan ke draft supaya bisa diubah"><x-heroicon-o-arrow-uturn-left class="size-5" /> Tarik ke Draft</button>
                </form>
            @endcan
            @can('adjustments.approve')
                <button type="button" class="btn-secondary hover:text-rose-600" @click="$dispatch('open-reject')">
                    <x-heroicon-o-x-mark class="size-5" /> Tolak
                </button>
                <form id="approve-adjustment" method="POST" action="{{ route('inventory.adjustments.approve', $adjustment) }}">@csrf</form>
                <button type="button" class="btn-primary"
                        @click="$dispatch('confirm', { form: 'approve-adjustment', title: 'Setujui penyesuaian?', message: 'Stok akan ditambah / dikurangi sesuai baris di bawah, dihitung dari saldo terbaru. Setelah ini dokumen tidak bisa diubah.', confirmText: 'Setujui', variant: 'primary' })">
                    <x-heroicon-o-check class="size-5" /> Setujui
                </button>
            @endcan
        @endif
    </x-page-header>

    <x-form-errors />

    <p class="mb-6 flex items-center gap-2 rounded-lg px-4 py-3 text-sm {{ $notices[0] }}">
        <x-dynamic-component :component="$notices[1]" class="size-5 shrink-0" /> {{ $notices[2] }}
    </p>

    @if ($adjustment->isPending())
        @can('adjustments.approve')
            <div x-data="{ open: @js($errors->has('rejection_reason')) }" @open-reject.window="open = true; $nextTick(() => $refs.reason.focus())" x-show="open" x-cloak class="card mb-6 p-5">
                <form method="POST" action="{{ route('inventory.adjustments.reject', $adjustment) }}" class="space-y-3">
                    @csrf
                    <x-textarea name="rejection_reason" label="Alasan penolakan" rows="2" required x-ref="reason" placeholder="Contoh: hitung ulang dulu, selisih terlalu besar." />
                    <div class="flex justify-end gap-2">
                        <button type="button" class="btn-secondary" @click="open = false">Batal</button>
                        <button type="submit" class="btn-danger"><x-heroicon-o-x-mark class="size-5" /> Tolak Penyesuaian</button>
                    </div>
                </form>
            </div>
        @endcan
    @endif

    <div class="card mb-6 grid gap-5 p-5 sm:grid-cols-2 lg:grid-cols-4">
        <div>
            <p class="text-xs text-slate-500">Status</p>
            <p class="mt-1"><x-adjustments.status-badge :adjustment="$adjustment" /></p>
        </div>
        <div>
            <p class="text-xs text-slate-500">Tanggal</p>
            <p class="mt-1 text-sm font-medium text-slate-900">{{ $adjustment->adjustment_date->translatedFormat('d F Y') }}</p>
        </div>
        <div>
            <p class="text-xs text-slate-500">Gudang</p>
            <p class="mt-1 text-sm font-medium text-slate-900">{{ $adjustment->warehouse->code }} · {{ $adjustment->warehouse->name }}</p>
        </div>
        <div>
            <p class="text-xs text-slate-500">Alasan</p>
            <p class="mt-1 text-sm font-medium text-slate-900">{{ $adjustment->reasonLabel() }}</p>
        </div>
        <div>
            <p class="text-xs text-slate-500">Referensi</p>
            <p class="mt-1 text-sm font-medium text-slate-900">{{ $adjustment->reference ?? '—' }}</p>
        </div>
        <div>
            <p class="text-xs text-slate-500">Mutasi <span class="whitespace-nowrap">Stok <x-hint text="Mutasi Penyesuaian yang tercatat di kartu stok saat dokumen disetujui." /></span></p>
            @if ($adjustment->movement)
                @can('inventory.view')
                    <a href="{{ route('inventory.movements.show', $adjustment->movement) }}" class="mt-1 inline-block font-mono text-sm font-medium text-brand-600 hover:underline">{{ $adjustment->movement->number }}</a>
                @else
                    <p class="mt-1 font-mono text-sm font-medium text-slate-700">{{ $adjustment->movement->number }}</p>
                @endcan
            @else
                <p class="mt-1 text-sm text-slate-400">{{ $open ? 'Dibuat saat disetujui' : 'Tidak ada' }}</p>
            @endif
        </div>
        <div>
            <p class="text-xs text-slate-500">Dibuat oleh</p>
            <p class="mt-1 text-sm text-slate-700">{{ $adjustment->creator?->name ?? '—' }} · {{ $adjustment->created_at->format('d/m/Y H:i') }}</p>
        </div>
        <div>
            <p class="text-xs text-slate-500">Diajukan oleh</p>
            @if ($adjustment->submitted_at)
                <p class="mt-1 text-sm text-slate-700">{{ $adjustment->submitter?->name ?? '—' }} · {{ $adjustment->submitted_at->format('d/m/Y H:i') }}</p>
            @else
                <p class="mt-1 text-sm text-slate-400">Belum diajukan</p>
            @endif
        </div>
        @if ($adjustment->approved_at)
            <div>
                <p class="text-xs text-slate-500">Disetujui oleh</p>
                <p class="mt-1 text-sm text-slate-700">{{ $adjustment->approver?->name ?? '—' }} · {{ $adjustment->approved_at->format('d/m/Y H:i') }}</p>
            </div>
        @endif
        @if ($adjustment->rejected_at)
            <div>
                <p class="text-xs text-slate-500">Ditolak oleh</p>
                <p class="mt-1 text-sm text-slate-700">{{ $adjustment->rejecter?->name ?? '—' }} · {{ $adjustment->rejected_at->format('d/m/Y H:i') }}</p>
            </div>
            <div class="sm:col-span-2 lg:col-span-3">
                <p class="text-xs text-slate-500">Alasan ditolak</p>
                <p class="mt-1 text-sm whitespace-pre-line text-rose-700">{{ $adjustment->rejection_reason }}</p>
            </div>
        @endif
        @if ($adjustment->notes)
            <div class="sm:col-span-2 lg:col-span-4">
                <p class="text-xs text-slate-500">Catatan</p>
                <p class="mt-1 text-sm whitespace-pre-line text-slate-700">{{ $adjustment->notes }}</p>
            </div>
        @endif
    </div>

    <div class="card mb-6">
        <div class="border-b border-slate-100 px-5 py-3">
            <h2 class="font-semibold text-slate-900">Barang</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                    <tr>
                        <th class="h-8 px-4 py-0 font-normal">#</th>
                        <th class="h-8 px-4 py-0 font-normal">SKU</th>
                        <th class="h-8 px-4 py-0 font-normal">Produk / Varian</th>
                        <th class="h-8 px-4 py-0 font-normal">Lokasi</th>
                        <th class="h-8 px-4 py-0 text-right font-normal">
                            <span class="whitespace-nowrap">{{ $open ? 'Stok Saat Ini' : 'Stok Sebelum' }} <x-hint :text="$open ? 'Saldo terbaru di lokasi ini. Penyesuaian dihitung dari saldo saat disetujui.' : 'Saldo lokasi tepat sebelum penyesuaian disetujui.'" /></span>
                        </th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Penyesuaian</th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Menjadi</th>
                        <th class="h-8 px-4 py-0 font-normal">Keterangan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($adjustment->items as $item)
                        @php
                            $before = $open ? ($current[$item->id] ?? 0) : $item->qty_before;
                            $after = $before === null ? null : $before + $item->qty;
                        @endphp
                        <tr>
                            <td class="px-4 py-2.5 text-slate-400">{{ $loop->iteration }}</td>
                            <td class="px-4 py-2.5 font-mono font-medium text-slate-900">{{ $item->variant->sku }}</td>
                            <td class="px-4 py-2.5">
                                <p class="font-medium text-slate-900">{{ $item->variant->product->name }}</p>
                                @if ($item->variant->name)
                                    <p class="text-xs text-slate-500">{{ $item->variant->name }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 font-mono text-slate-700">{{ $item->location?->code ?? 'Tanpa bin' }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-600">{{ $before === null ? '—' : $fmt($before) }}</td>
                            <td @class(['px-4 py-2.5 text-right font-semibold tabular-nums', 'text-emerald-600' => $item->qty > 0, 'text-rose-600' => $item->qty < 0])>
                                {{ $signed($item->qty) }} <span class="text-xs font-normal text-slate-500">{{ $item->variant->product->unit?->code }}</span>
                            </td>
                            <td @class(['px-4 py-2.5 text-right font-medium tabular-nums', 'text-rose-600' => $after !== null && $after < 0, 'text-slate-900' => $after === null || $after >= 0])>
                                {{ $after === null ? '—' : $fmt($after) }}
                                @if ($open && $after !== null && $after < 0)
                                    <x-hint text="Saldo saat ini tidak cukup untuk dikurangi. Penyesuaian akan ditolak sistem sampai stoknya cukup." />
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-slate-600">{{ $item->notes ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="border-t border-slate-200 bg-slate-50 text-sm">
                    <tr>
                        <td colspan="5" class="px-4 py-2 text-right text-slate-500">Total</td>
                        <td class="px-4 py-2 text-right font-semibold whitespace-nowrap tabular-nums">
                            <span class="text-emerald-600">{{ $signed($adjustment->items->where('qty', '>', 0)->sum('qty')) ?: '0' }}</span>
                            <span class="text-slate-300">/</span>
                            <span class="text-rose-600">{{ $signed($adjustment->items->where('qty', '<', 0)->sum('qty')) ?: '0' }}</span>
                        </td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <x-activity.history :subject="$adjustment" />
</x-layouts.app>
