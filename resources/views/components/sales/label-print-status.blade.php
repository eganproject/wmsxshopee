@props(['order'])

{{-- Status cetak label resi di daftar pesanan: sudah dicetak (kapan, berapa kali) atau belum. --}}
@if ($order->isLabelPrinted())
    @php($times = (int) $order->shipping_label_print_count)
    <div {{ $attributes }}>
        <span class="inline-flex cursor-help items-center gap-1 rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[11px] font-medium whitespace-nowrap text-emerald-700"
              data-testid="label-printed"
              data-hint-title="Resi sudah dicetak"
              data-hint="Label resi terakhir dicetak {{ $order->shipping_label_printed_at->translatedFormat('d M Y H:i') }}{{ $times > 1 ? ', sudah '.$times.' kali dicetak' : '' }}.">
            <x-heroicon-o-printer class="size-3.5" /> Resi dicetak {{ $order->shipping_label_printed_at->format('d/m H:i') }}{{ $times > 1 ? ' · '.$times.'x' : '' }}
        </span>
    </div>
@elseif ($order->hasShippingLabel() && $order->hasStatus(...\App\Models\SalesOrder::RESERVING))
    <div {{ $attributes }}>
        <span class="inline-flex cursor-help items-center gap-1 rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[11px] font-medium whitespace-nowrap text-amber-700"
              data-testid="label-unprinted"
              data-hint-title="Resi belum dicetak" data-hint="Pengiriman sudah diatur dan label resi siap, tapi belum pernah dicetak dari Optima." data-hint-next="Centang pesanan lalu klik Cetak Resi.">
            <x-heroicon-o-printer class="size-3.5" /> Belum cetak resi
        </span>
    </div>
@endif
