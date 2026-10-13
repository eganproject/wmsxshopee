<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Label Pengiriman · {{ config('app.name') }}</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <style>
        @page { size: 100mm 150mm; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 24px; font: 12px/1.35 system-ui, -apple-system, "Segoe UI", sans-serif; color: #111; background: #f3f3f3; }
        .toolbar { max-width: 100mm; margin: 0 auto 16px; display: flex; justify-content: flex-end; gap: 8px; }
        .toolbar button { font: inherit; padding: 6px 14px; border-radius: 6px; border: 1px solid #ccc; background: #fff; cursor: pointer; }
        .toolbar button.primary { background: #ee4d2d; border-color: #ee4d2d; color: #fff; }
        .label { width: 100mm; height: 150mm; margin: 0 auto 16px; background: #fff; border: 1px solid #111; display: flex; flex-direction: column; overflow: hidden; }
        .row { border-bottom: 1px solid #111; padding: 2.5mm 3mm; }
        .head { display: flex; justify-content: space-between; align-items: center; gap: 3mm; }
        .courier { font-size: 18px; font-weight: 800; letter-spacing: .02em; }
        .method { border: 1.5px solid #111; border-radius: 3px; padding: 1mm 2.5mm; font-weight: 800; text-transform: uppercase; }
        .bars { height: 18mm; }
        .bars svg { width: 100%; height: 100%; display: block; }
        .resi { text-align: center; font: 700 15px ui-monospace, SFMono-Regular, Menlo, monospace; margin-top: 1mm; letter-spacing: .05em; }
        .no-resi { text-align: center; padding: 6mm 0; color: #666; }
        .cap { font-size: 9px; text-transform: uppercase; letter-spacing: .06em; color: #555; }
        .name { font-size: 14px; font-weight: 700; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; }
        .grid > div + div { border-left: 1px solid #111; }
        .items { flex: 1; overflow: hidden; }
        .items table { width: 100%; border-collapse: collapse; font-size: 10.5px; }
        .items td { padding: .6mm 0; vertical-align: top; }
        .items td.qty { text-align: right; white-space: nowrap; padding-left: 2mm; font-weight: 700; }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
        @media print {
            body { padding: 0; background: #fff; }
            .toolbar { display: none; }
            .label { margin: 0; border: 0; page-break-after: always; }
            .label:last-child { page-break-after: auto; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" onclick="window.close()">Tutup</button>
        <button type="button" class="primary" onclick="window.print()">Cetak</button>
    </div>

    @foreach ($orders as $order)
        @php($warehouse = $order->warehouse)
        <div class="label">
            <div class="row head">
                <div>
                    <div class="courier">{{ $order->courier?->name ?? 'KURIR' }}</div>
                    @if ($order->courier_service)
                        <div>{{ $order->courier_service }}</div>
                    @endif
                </div>
                @if ($order->shippingMethodLabel())
                    <div class="method">{{ $order->shippingMethodLabel() }}</div>
                @endif
            </div>

            <div class="row">
                @if ($order->tracking_number && $barcodes[$order->id])
                    <div class="bars">{!! $barcodes[$order->id] !!}</div>
                    <div class="resi">{{ $order->tracking_number }}</div>
                @elseif ($order->tracking_number)
                    <div class="resi">{{ $order->tracking_number }}</div>
                @else
                    <div class="no-resi">Resi ditulis / ditempel kurir</div>
                @endif
            </div>

            <div class="row">
                <div class="cap">Penerima</div>
                <div class="name">{{ $order->customer_name }}</div>
                @if ($order->customer_phone)
                    <div>{{ $order->customer_phone }}</div>
                @endif
                <div style="white-space: pre-line;">{{ $order->shipping_address }}</div>
            </div>

            <div class="row">
                <div class="cap">Pengirim</div>
                <div><strong>{{ $warehouse->name }}</strong>{{ $warehouse->phone ? ' · '.$warehouse->phone : '' }}</div>
                <div>{{ collect([$warehouse->address, $warehouse->city])->filter()->implode(', ') }}</div>
            </div>

            <div class="grid row" style="padding: 0;">
                <div style="padding: 2mm 3mm;">
                    <div class="cap">No. Pesanan</div>
                    <div class="mono"><strong>{{ $order->number }}</strong></div>
                    @if ($order->external_number)
                        <div class="mono">{{ $order->external_number }}</div>
                    @endif
                </div>
                <div style="padding: 2mm 3mm;">
                    @if ($order->shipping_method === 'pickup')
                        <div class="cap">Jadwal Pickup</div>
                        <div>{{ $order->pickup_slot ?? $order->pickup_at?->format('d/m/Y H:i') ?? '—' }}</div>
                    @elseif ($order->shipping_method === 'dropoff')
                        <div class="cap">Drop Off</div>
                        <div>{{ $order->dropoff_point ?? '—' }}</div>
                    @else
                        <div class="cap">Saluran</div>
                        <div>{{ $order->channel_name ?? '—' }}</div>
                    @endif
                </div>
            </div>

            <div class="row items" style="border-bottom: 0;">
                <div class="cap">Isi Paket ({{ number_format($order->items->sum('qty'), 0, ',', '.') }} pcs)</div>
                <table>
                    @foreach ($order->items->take(8) as $item)
                        <tr>
                            <td class="mono">{{ $item->variant?->sku }}</td>
                            <td class="qty">× {{ $item->qty }}</td>
                        </tr>
                    @endforeach
                    @if ($order->items->count() > 8)
                        <tr><td colspan="2">+ {{ $order->items->count() - 8 }} baris lainnya</td></tr>
                    @endif
                </table>
            </div>
        </div>
    @endforeach
</body>
</html>
