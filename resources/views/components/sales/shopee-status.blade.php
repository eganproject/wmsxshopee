@props(['order'])

{{-- Status pesanan di Shopee + penanda apakah resi sudah discan kurir. --}}
@if ($order->shopee_order_status)
    @php
        $colors = [
            'slate' => 'border-slate-200 text-slate-600',
            'sky' => 'border-sky-200 text-sky-700',
            'violet' => 'border-violet-200 text-violet-700',
            'amber' => 'border-amber-200 text-amber-700',
            'emerald' => 'border-emerald-200 text-emerald-700',
            'rose' => 'border-rose-200 text-rose-700',
        ];
        $color = \App\Models\SalesOrder::SHOPEE_STATUSES[$order->shopee_order_status]['color'] ?? 'slate';
    @endphp
    <div {{ $attributes->class('mt-1 space-y-0.5') }}>
        <span class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] whitespace-nowrap {{ $colors[$color] }}" title="Status di Shopee">
            Shopee: {{ $order->shopeeStatusLabel() }}
        </span>
        @if ($order->status === 'shipped' && $order->shopee_shipped_at)
            <p class="flex items-center gap-1 text-[11px] whitespace-nowrap text-emerald-600" title="Shopee melaporkan paket sudah di tangan kurir">
                <x-heroicon-o-check-circle class="size-3.5" /> Diterima kurir {{ $order->shopee_shipped_at->format('d/m H:i') }}
            </p>
        @elseif ($order->awaitingCourierScan())
            <p @class(['flex items-center gap-1 text-[11px] whitespace-nowrap', 'text-rose-600' => $order->courierScanOverdue(), 'text-amber-600' => ! $order->courierScanOverdue()])
               title="Sudah serah terima {{ $order->shipped_at?->diffForHumans() }}, Shopee belum menerima scan kurir">
                <x-heroicon-o-clock class="size-3.5" /> Belum discan kurir
            </p>
        @elseif ($order->shopeeAhead())
            <p class="flex items-center gap-1 text-[11px] whitespace-nowrap text-rose-600" title="{{ $order->shopee_sync_error }}">
                <x-heroicon-o-exclamation-triangle class="size-3.5" /> Dikirim di Shopee, belum di gudang
            </p>
        @endif
        @if ($order->shipped_automatically)
            <p class="text-[11px] whitespace-nowrap text-slate-500">Dikirim otomatis dari Shopee</p>
        @endif
    </div>
@endif
