<x-layouts.app title="QC Kemas">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span>Penjualan</span>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">QC Kemas</span>
    </x-slot:breadcrumb>

    <x-page-header title="QC Kemas" description="Scan resi atau nomor SO, lalu scan setiap barang. Paket ditutup otomatis bila semua barang cocok." icon="heroicon-o-viewfinder-circle" />

    <form method="POST" action="{{ route('sales.qc.lookup') }}" class="card mb-6 p-5">
        @csrf
        <label for="code" class="form-label">Scan Resi / No. SO / No. Pesanan</label>
        <div class="flex gap-2">
            <div class="relative flex-1">
                <x-heroicon-o-qr-code class="pointer-events-none absolute top-1/2 left-3 size-5 -translate-y-1/2 text-slate-400" />
                <input id="code" name="code" data-auto-scan autofocus autocomplete="off" required maxlength="100"
                       class="form-input h-12 pl-10 font-mono text-lg" placeholder="Arahkan scanner ke resi...">
            </div>
            <button type="submit" class="btn-primary h-12 px-5">Buka</button>
        </div>
    </form>

    <div class="card">
        <div class="border-b border-slate-100 px-5 py-3">
            <h2 class="text-base font-semibold text-slate-900">Antrean QC <span class="ml-1 text-sm font-normal text-slate-400">{{ number_format($queue->total(), 0, ',', '.') }}</span></h2>
            <p class="mt-0.5 text-sm text-slate-500">Pesanan yang picking-nya sudah selesai dan menunggu dicek.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                    <tr>
                        <th class="h-8 px-4 py-0 font-normal">No.</th>
                        <th class="h-8 px-4 py-0 font-normal">Pembeli</th>
                        <th class="h-8 px-4 py-0 font-normal">Kurir / Resi</th>
                        <th class="h-8 px-4 py-0 font-normal">Pengiriman</th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Qty</th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($queue as $order)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-4 py-2.5 font-mono font-medium text-slate-900">{{ $order->number }}
                                @if ($order->external_number)<p class="text-xs font-normal text-slate-500">{{ $order->external_number }}</p>@endif
                            </td>
                            <td class="px-4 py-2.5 text-slate-700">{{ $order->customer_name }}</td>
                            <td class="px-4 py-2.5">
                                <p class="text-slate-700">{{ $order->courier?->name ?? '—' }}</p>
                                <p class="font-mono text-xs text-slate-500">{{ $order->tracking_number ?? 'Belum ada resi' }}</p>
                            </td>
                            <td class="px-4 py-2.5 text-slate-600">{{ $order->shippingMethodLabel() ?? ($order->shopeeShipmentArranged() ? 'Diatur di Shopee' : '—') }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-700">{{ number_format((int) $order->items_sum_qty, 0, ',', '.') }}</td>
                            <td class="px-4 py-2.5 text-right">
                                <a href="{{ route('sales.qc.show', $order) }}" class="btn-secondary"><x-heroicon-o-viewfinder-circle class="size-5" /> QC</a>
                            </td>
                        </tr>
                    @empty
                        <x-empty-state colspan="6" icon="heroicon-o-viewfinder-circle" title="Tidak ada antrean QC" description="Pesanan muncul di sini setelah picking selesai." />
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($queue->hasPages())
            <div class="border-t border-slate-100 px-5 py-4">{{ $queue->links() }}</div>
        @endif
    </div>
</x-layouts.app>
