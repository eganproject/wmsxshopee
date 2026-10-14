<x-layouts.app :title="'Manifest '.$handover->number">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('sales.handovers.index') }}" class="hover:text-slate-700">Serah Terima</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">{{ $handover->number }}</span>
    </x-slot:breadcrumb>

    <x-page-header :title="'Manifest '.$handover->number"
                   :description="$handover->warehouse->code.' · '.($handover->courier?->name ?? 'Tanpa kurir').' · '.$handover->created_at->format('d/m/Y')"
                   icon="heroicon-o-paper-airplane">
        <a href="{{ route('sales.handovers.manifest', $handover) }}" target="_blank" class="btn-secondary"><x-heroicon-o-printer class="size-5" /> Cetak Manifest</a>
        <a href="{{ route('sales.handovers.index') }}" class="btn-secondary"><x-heroicon-o-arrow-left class="size-5" /> Kembali</a>
    </x-page-header>

    <div class="card mb-6 grid gap-5 p-5 sm:grid-cols-4">
        <div><p class="text-xs text-slate-500">Status</p><p class="mt-1 text-sm font-medium text-slate-900">{{ $handover->isOpen() ? 'Hari ini, masih bisa bertambah' : 'Selesai' }}</p></div>
        <div><p class="text-xs text-slate-500">Scan Terakhir</p><p class="mt-1 text-sm font-medium text-slate-900">{{ ($handover->closed_at ?? $handover->orders->max('shipped_at'))?->format('d/m/Y H:i') ?? '—' }}</p></div>
        <div><p class="text-xs text-slate-500">Dibuat Oleh</p><p class="mt-1 text-sm font-medium text-slate-900">{{ $handover->creator?->name ?? '—' }}</p></div>
        <div><p class="text-xs text-slate-500">Jumlah Paket</p><p class="mt-1 text-sm font-medium text-slate-900">{{ $handover->orders->count() }}</p></div>
    </div>

    <div class="card">
        <div class="border-b border-slate-100 px-5 py-3"><h2 class="text-base font-semibold text-slate-900">Paket Diserahkan</h2></div>
        <div class="divide-y divide-slate-100 text-sm">
            @forelse ($handover->orders->sortByDesc('shipped_at') as $order)
                <a href="{{ route('sales.orders.show', $order) }}" class="flex items-center justify-between gap-3 px-5 py-2.5 hover:bg-slate-50/60">
                    <span>
                        <span class="font-mono font-medium text-slate-900">{{ $order->tracking_number ?? $order->number }}</span>
                        <span class="block text-xs text-slate-500">{{ $order->number }} · {{ $order->customer_name }} · {{ $order->courier?->name ?? '—' }}</span>
                    </span>
                    <span class="text-xs text-slate-500">{{ $order->shipped_at?->format('H:i') }}</span>
                </a>
            @empty
                <p class="px-5 py-4 text-slate-500">Belum ada paket yang di-scan.</p>
            @endforelse
        </div>
    </div>
</x-layouts.app>
