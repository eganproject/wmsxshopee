<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manifest {{ $handover->number }} · {{ config('app.name') }}</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 24px; font: 13px/1.45 system-ui, -apple-system, "Segoe UI", sans-serif; color: #1f1f1f; background: #fff; }
        .sheet { max-width: 800px; margin: 0 auto; }
        header { display: flex; justify-content: space-between; gap: 16px; border-bottom: 2px solid #1f1f1f; padding-bottom: 12px; }
        h1 { margin: 0; font-size: 20px; }
        .muted { color: #666; }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
        .grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin: 16px 0; }
        .label { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #666; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border-bottom: 1px solid #ddd; padding: 6px; text-align: left; vertical-align: top; }
        th { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #666; }
        .right { text-align: right; }
        .sign { display: grid; grid-template-columns: 1fr 1fr; gap: 32px; margin-top: 56px; }
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
                <h1>Manifest Serah Terima</h1>
                <div class="muted">{{ config('app.name') }} · Gudang {{ $handover->warehouse->code }} · {{ $handover->warehouse->name }}</div>
            </div>
            <div class="right">
                <div class="mono" style="font-size: 18px; font-weight: 700;">{{ $handover->number }}</div>
                <div class="muted">{{ ($handover->closed_at ?? now())->format('d/m/Y H:i') }}</div>
            </div>
        </header>

        <div class="grid">
            <div><div class="label">Kurir</div><div><strong>{{ $handover->courier?->name ?? '—' }}</strong></div></div>
            <div><div class="label">Cara Kirim</div><div>{{ $handover->methodLabel() ?? 'Pickup & drop off' }}</div></div>
            <div><div class="label">Jumlah Paket</div><div><strong>{{ $handover->orders->count() }}</strong></div></div>
            <div><div class="label">Status</div><div>{{ $handover->isOpen() ? 'Hari ini' : 'Selesai' }}</div></div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Resi</th>
                    <th>No. Pesanan</th>
                    <th>Penerima</th>
                    <th>Kurir</th>
                    <th class="right">Qty</th>
                    <th>Jam Scan</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($handover->orders as $order)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td class="mono"><strong>{{ $order->tracking_number ?? '—' }}</strong></td>
                        <td class="mono">{{ $order->number }}@if ($order->external_number)<br><span class="muted">{{ $order->external_number }}</span>@endif</td>
                        <td>{{ $order->customer_name }}</td>
                        <td>{{ $order->courier?->name ?? '—' }}{{ $order->courier_service ? ' · '.$order->courier_service : '' }}</td>
                        <td class="right">{{ $order->items->sum('qty') }}</td>
                        <td>{{ $order->shipped_at?->format('H:i') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if ($handover->notes)
            <p><span class="label">Catatan:</span> {{ $handover->notes }}</p>
        @endif

        <div class="sign">
            <div>Diserahkan oleh<br><strong>{{ $handover->creator?->name }}</strong></div>
            <div>Diterima kurir<br><strong>{{ $handover->receiver_name ?? '' }}</strong>&nbsp;</div>
        </div>
    </div>
</body>
</html>
