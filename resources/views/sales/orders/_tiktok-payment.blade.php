@php
    $payment = $order->tiktok_payment;
    $rows = [
        'original_total_product_price' => ['Harga asli produk', 1],
        'seller_discount' => ['Diskon penjual', -1],
        'platform_discount' => ['Diskon dari TikTok', -1],
        'sub_total' => ['Subtotal produk', 1],
        'original_shipping_fee' => ['Ongkir asli', 1],
        'shipping_fee_seller_discount' => ['Subsidi ongkir dari penjual', -1],
        'shipping_fee_platform_discount' => ['Subsidi ongkir dari TikTok', -1],
        'shipping_fee' => ['Ongkir dibayar pembeli', 1],
        'tax' => ['Pajak', 1],
        'total_amount' => ['Total dibayar pembeli', 1],
    ];
@endphp

<div class="card mt-6">
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-5 py-3">
        <h2 class="flex items-center gap-2 text-base font-semibold text-slate-900">
            <x-heroicon-o-banknotes class="size-5 text-slate-400" /> Rincian Pembayaran TikTok
        </h2>
        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{{ $payment['currency'] ?? 'IDR' }}</span>
    </div>
    <div class="max-w-xl space-y-0.5 p-5 text-sm">
        @foreach ($rows as $key => [$label, $sign])
            @continue(! isset($payment[$key]) || (float) $payment[$key] == 0 && ! in_array($key, ['sub_total', 'total_amount'], true))
            @php($amount = (float) $payment[$key] * ($sign < 0 ? -1 : 1))
            <div @class(['flex justify-between gap-4 py-0.5', 'border-t border-slate-100 pt-1.5 font-medium' => in_array($key, ['sub_total', 'total_amount'], true)])>
                <span class="text-slate-600">{{ $label }}</span>
                <span @class(['tabular-nums', 'text-rose-600' => $amount < 0, 'text-slate-800' => $amount >= 0])>{{ ($amount < 0 ? '− ' : '').$money(abs($amount)) }}</span>
            </div>
        @endforeach
        <p class="pt-2 text-xs text-slate-400">Dari data pembayaran pesanan TikTok. Potongan komisi & biaya TikTok (settlement) belum ditarik.</p>
    </div>
</div>
