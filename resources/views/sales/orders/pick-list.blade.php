<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Picking List {{ $order->number }} · {{ config('app.name') }}</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 24px; font: 13px/1.45 system-ui, -apple-system, "Segoe UI", sans-serif; color: #1f1f1f; background: #fff; }
        .sheet { max-width: 800px; margin: 0 auto; }
        header { display: flex; justify-content: space-between; gap: 16px; border-bottom: 2px solid #1f1f1f; padding-bottom: 12px; }
        h1 { margin: 0; font-size: 20px; }
        .muted { color: #666; }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
        .grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin: 16px 0; }
        .label { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #666; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border-bottom: 1px solid #ddd; padding: 8px 6px; text-align: left; vertical-align: top; }
        th { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #666; }
        .right { text-align: right; }
        .bin { font-size: 15px; font-weight: 700; }
        .check { width: 22px; height: 22px; border: 1.5px solid #1f1f1f; border-radius: 4px; display: inline-block; }
        .sign { display: grid; grid-template-columns: 1fr 1fr; gap: 32px; margin-top: 40px; }
        .sign div { border-top: 1px solid #1f1f1f; padding-top: 4px; text-align: center; }
        .toolbar { max-width: 800px; margin: 0 auto 16px; display: flex; justify-content: flex-end; gap: 8px; }
        .toolbar button { font: inherit; padding: 6px 14px; border-radius: 6px; border: 1px solid #ccc; background: #fff; cursor: pointer; }
        .toolbar button.primary { background: #ee4d2d; border-color: #ee4d2d; color: #fff; }
        @media print { body { padding: 0; } .toolbar { display: none; } }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" onclick="window.close()">Tutup</button>
        <button type="button" class="primary" onclick="window.print()">Cetak</button>
    </div>

    <div class="sheet">
        <header>
            <div>
                <h1>Picking List</h1>
                <div class="muted">{{ config('app.name') }} · Gudang {{ $order->warehouse->code }} · {{ $order->warehouse->name }}</div>
            </div>
            <div class="right">
                <div class="mono" style="font-size: 18px; font-weight: 700;">{{ $order->number }}</div>
                <div class="muted">Dicetak {{ now()->format('d/m/Y H:i') }}</div>
                @if ($order->hasStatus('confirmed'))
                    <div class="muted">Bin: usulan otomatis</div>
                @endif
            </div>
        </header>

        <div class="grid">
            <div>
                <div class="label">Pembeli</div>
                <div><strong>{{ $order->customer_name }}</strong></div>
                @if ($order->customer_phone)
                    <div>{{ $order->customer_phone }}</div>
                @endif
            </div>
            <div>
                <div class="label">Saluran / No. Pesanan</div>
                <div>{{ $order->channel_name ?? '—' }}</div>
                @if ($order->external_number)
                    <div class="mono">{{ $order->external_number }}</div>
                @endif
            </div>
            <div>
                <div class="label">Kurir</div>
                <div>{{ $order->courier?->name ?? '—' }}{{ $order->courier_service ? ' · '.$order->courier_service : '' }}</div>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Bin</th>
                    <th>SKU</th>
                    <th>Barang</th>
                    <th class="right">Qty</th>
                    <th class="right">Ambil</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($lines as $line)
                    <tr>
                        <td class="bin mono">{{ $line['code'] ?? 'Tanpa bin' }}</td>
                        <td class="mono">{{ $line['item']->variant->sku }}</td>
                        <td>
                            {{ $line['item']->variant->product->name }}{{ $line['item']->variant->name ? ' / '.$line['item']->variant->name : '' }}
                            @if ($line['item']->variant->barcode)
                                <div class="muted mono">{{ $line['item']->variant->barcode }}</div>
                            @endif
                            @if ($line['item']->bundleLine)
                                <div class="muted">Isi paket {{ $line['item']->bundleLine->bundle->sku }}</div>
                            @endif
                        </td>
                        <td class="right"><strong>{{ $line['qty'] }}</strong> {{ $line['item']->variant->product->unit->code }}</td>
                        <td class="right"><span class="check"></span></td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3" class="right muted">Total barang</td>
                    <td class="right"><strong>{{ $lines->sum('qty') }}</strong></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>

        @if ($order->notes)
            <p><span class="label">Catatan</span><br>{{ $order->notes }}</p>
        @endif

        <div class="sign">
            <div>Picker</div>
            <div>Packer</div>
        </div>
    </div>
</body>
</html>
