{{-- Peringatan merah: pembeli mengajukan / sudah membatalkan di marketplace saat pesanan masih diproses gudang. --}}
@if ($order->isMarketplace() && ! $order->hasStatus('shipped', 'cancelled') && $order->marketplaceCancelPending())
    @php($platform = $order->isTiktok() ? 'TikTok' : 'Shopee')
    <div class="mb-5 flex items-start gap-3 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800" data-testid="cancel-request-banner">
        <x-heroicon-o-hand-raised class="mt-0.5 size-5 shrink-0" />
        <div>
            @if ($order->cancelRequested())
                <p class="font-medium">Pembeli mengajukan pembatalan di {{ $platform }}. Tahan pesanan ini.</p>
                <p class="mt-0.5">
                    @switch($order->status)
                        @case('packed') Paket sudah dikemas: jangan diserahkan ke kurir. @break
                        @case('picking') Barang sudah diambil: jangan dikemas. @break
                        @default Jangan di-picking dulu.
                    @endswitch
                    Terima atau tolak pembatalan di Seller Center {{ $platform }}, lalu tekan Sinkronkan ulang supaya status di sini ikut berubah.
                </p>
            @else
                <p class="font-medium">Pesanan ini sudah dibatalkan di {{ $platform }}, tapi belum dibatalkan di gudang.</p>
                <p class="mt-0.5">Jangan diproses. Batalkan pesanan ini di Optima{{ $order->restockReminder() ? ' lalu '.lcfirst($order->restockReminder()) : '' }}</p>
            @endif
        </div>
    </div>
@endif
