@php
    use App\Support\ShopeeIncome;
    $income = $order->shopee_income;
    $sections = ShopeeIncome::sections($income);
    $funded = ShopeeIncome::shopeeFunded($income);
    $items = $income['items'] ?? [];
    $signed = fn ($value) => ($value < 0 ? '− ' : '').$money(abs($value));
@endphp

<div class="card mt-6">
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-5 py-3">
        <h2 class="flex items-center gap-2 text-base font-semibold text-slate-900">
            <x-heroicon-o-banknotes class="size-5 text-slate-400" /> Rincian Pendapatan Shopee
        </h2>
        @if ($order->shopee_income_final)
            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs text-emerald-700"><x-heroicon-o-check-circle class="size-4" /> Final</span>
        @else
            <span class="rounded-full bg-amber-50 px-2 py-0.5 text-xs text-amber-700" title="Angka bisa berubah sampai pesanan Selesai di Shopee">Perkiraan</span>
        @endif
    </div>

    <div class="grid gap-6 p-5 lg:grid-cols-2">
        <div class="space-y-4 text-sm">
            @foreach ($sections as $title => $rows)
                <div>
                    <p class="mb-1 text-xs font-medium tracking-wide text-slate-500 uppercase">{{ $title }}</p>
                    @foreach ($rows as $row)
                        <div class="flex justify-between gap-4 py-0.5">
                            <span class="text-slate-600">{{ $row['label'] }}</span>
                            <span @class(['tabular-nums', 'text-rose-600' => $row['amount'] < 0, 'text-slate-800' => $row['amount'] >= 0])>{{ $signed($row['amount']) }}</span>
                        </div>
                    @endforeach
                </div>
            @endforeach

            <div class="flex items-center justify-between gap-4 border-t border-slate-200 pt-3">
                <span class="font-medium text-slate-900">Dana diterima penjual</span>
                <span class="text-lg font-semibold tabular-nums text-emerald-600">{{ $money($order->shopee_escrow_amount ?? 0) }}</span>
            </div>
            <p class="text-xs text-slate-400">
                Angka dari Shopee (escrow). Total biaya Shopee {{ $money(ShopeeIncome::fees($income)) }}.
                @if ($order->shopee_income_synced_at) Diperbarui {{ $order->shopee_income_synced_at->diffForHumans() }}. @endif
            </p>
        </div>

        <div class="space-y-4 text-sm">
            @if ($items)
                <div>
                    <p class="mb-1 text-xs font-medium tracking-wide text-slate-500 uppercase">Harga per produk</p>
                    <table class="min-w-full">
                        <thead class="text-left text-xs text-slate-500">
                            <tr>
                                <th class="py-1 font-normal">Produk</th>
                                <th class="py-1 text-right font-normal">Qty</th>
                                <th class="py-1 text-right font-normal">Harga asli</th>
                                <th class="py-1 text-right font-normal">Setelah diskon</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($items as $item)
                                <tr>
                                    <td class="py-1.5 pr-2">
                                        <p class="text-slate-800">{{ $item['item_name'] ?? 'Item '.($item['item_id'] ?? '') }}</p>
                                        <p class="font-mono text-xs text-slate-400">{{ $item['model_sku'] ?? $item['item_sku'] ?? '' }}@if (filled($item['model_name'] ?? null)) · {{ $item['model_name'] }}@endif</p>
                                        @php($vouchers = (float) ($item['discount_from_voucher_seller'] ?? 0) + (float) ($item['discount_from_voucher_shopee'] ?? 0))
                                        @if ($vouchers)
                                            <p class="text-xs text-slate-400">Voucher {{ $money($vouchers) }}</p>
                                        @endif
                                    </td>
                                    <td class="py-1.5 text-right tabular-nums text-slate-700">{{ $item['quantity_purchased'] ?? 1 }}</td>
                                    <td class="py-1.5 text-right tabular-nums text-slate-500 {{ (float) ($item['discounted_price'] ?? 0) < (float) ($item['original_price'] ?? 0) ? 'line-through' : '' }}">{{ $money($item['original_price'] ?? 0) }}</td>
                                    <td class="py-1.5 text-right tabular-nums text-slate-800">{{ $money($item['discounted_price'] ?? $item['original_price'] ?? 0) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($funded)
                <div>
                    <p class="mb-1 text-xs font-medium tracking-wide text-slate-500 uppercase">Ditanggung Shopee (tidak memotong pendapatan)</p>
                    @foreach ($funded as $row)
                        <div class="flex justify-between gap-4 py-0.5">
                            <span class="text-slate-600">{{ $row['label'] }}</span>
                            <span class="tabular-nums text-slate-800">{{ $money($row['amount']) }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
