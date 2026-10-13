{{-- Satu baris barang di detail pesanan. $number null = isi dari paket di atasnya. --}}
@php($component = $item->sales_order_bundle_id !== null)
<tr>
    <td class="px-4 py-2.5 text-slate-400">{{ $number }}</td>
    <td @class(['py-2.5 pr-4 font-mono font-medium text-slate-900', 'pl-4' => ! $component, 'pl-8' => $component])>
        @if ($component)<span class="text-slate-300">└</span>@endif {{ $item->variant->sku }}
    </td>
    <td class="px-4 py-2.5">
        <p class="font-medium text-slate-900">{{ $item->variant->product->name }}</p>
        @if ($item->variant->name)
            <p class="text-xs text-slate-500">{{ $item->variant->name }}</p>
        @endif
    </td>
    <td class="px-4 py-2.5 text-right font-semibold tabular-nums text-slate-900">
        {{ number_format($item->qty, 0, ',', '.') }}
        <span class="text-xs font-normal text-slate-500">{{ $item->variant->product->unit->code }}</span>
        @if ($component)
            <p class="text-xs font-normal text-slate-400">{{ $item->bundle_component_qty }} per paket</p>
        @endif
    </td>
    @if ($order->hasStatus('draft'))
        @php($available = $availability[$item->product_variant_id]['available'] ?? 0)
        @php($needed = $order->items->where('product_variant_id', $item->product_variant_id)->sum('qty'))
        <td @class(['px-4 py-2.5 text-right tabular-nums', 'text-rose-600 font-medium' => $available < $needed, 'text-slate-600' => $available >= $needed])>
            {{ number_format($available, 0, ',', '.') }}
        </td>
    @endif
    @if ($showPicks)
        <td class="px-4 py-2.5">
            @foreach ($item->picks as $pick)
                <p class="text-xs whitespace-nowrap"><span @class(['font-mono text-slate-700' => $pick->location, 'text-slate-400' => ! $pick->location])>{{ $pick->location?->code ?? 'Tanpa bin' }}</span> <span class="text-slate-500">× {{ $pick->qty }}</span></p>
            @endforeach
        </td>
    @endif
    @if ($component)
        <td class="px-4 py-2.5 text-right text-slate-400">—</td>
        <td class="px-4 py-2.5 text-right text-slate-400">—</td>
        <td class="px-4 py-2.5 text-xs text-slate-400">Isi paket</td>
    @else
        <td class="px-4 py-2.5 text-right tabular-nums text-slate-600">{{ $money($item->price) }}</td>
        <td class="px-4 py-2.5 text-right tabular-nums text-slate-900">{{ $money($item->qty * $item->price) }}</td>
        <td class="px-4 py-2.5 text-slate-600">{{ $item->notes ?? '—' }}</td>
    @endif
</tr>
