{{-- Isi panel "Riwayat Operasi" di daftar pesanan (dimuat lewat fetch). --}}
<div class="space-y-3">
    <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
        <div>
            <p class="font-mono font-medium text-slate-900">{{ $order->number }}</p>
            <p class="text-xs text-slate-500">
                {{ $order->channel_name ?? 'Manual' }}{{ $order->external_number ? ' · '.$order->external_number : '' }}
            </p>
        </div>
        <a href="{{ route('sales.orders.show', $order) }}" class="text-xs font-medium text-brand-600 hover:underline">Buka detail pesanan</a>
    </div>
    @if ($order->marketplaceSyncedAt())
        <p class="flex items-center gap-1.5 text-xs text-slate-500">
            <x-heroicon-o-arrow-path class="size-3.5" /> Terakhir sinkron dari marketplace {{ $order->marketplaceSyncedAt()->format('d/m/Y H:i') }}
        </p>
    @endif
    <x-activity.history :subject="$order" :limit="50" class="mt-0!" />
</div>
