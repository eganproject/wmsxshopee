@php
    $fmt = fn ($n) => number_format((int) $n, 0, ',', '.');
    $address = fn ($w) => collect([$w->address, collect([$w->city, $w->province, $w->postal_code])->filter()->implode(', ')])->filter()->implode("\n");
    $contact = fn ($w) => collect([$w->pic_name, $w->phone])->filter()->implode(' · ');
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Surat Jalan {{ $transfer->number }} · {{ config('app.name') }}</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 24px; font: 13px/1.45 system-ui, -apple-system, "Segoe UI", sans-serif; color: #1f1f1f; background: #fff; }
        .sheet { max-width: 800px; margin: 0 auto; position: relative; }
        header { display: flex; justify-content: space-between; gap: 16px; border-bottom: 2px solid #1f1f1f; padding-bottom: 12px; }
        h1 { margin: 0; font-size: 22px; letter-spacing: .02em; }
        .muted { color: #666; }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
        .label { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #666; }
        .parties { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin: 16px 0 12px; }
        .party { border: 1px solid #ddd; border-radius: 6px; padding: 10px 12px; }
        .party strong { font-size: 14px; }
        .pre { white-space: pre-line; }
        .meta { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 14px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; vertical-align: top; }
        th { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #555; background: #f5f5f5; }
        tfoot td { font-weight: 700; background: #fafafa; }
        .right { text-align: right; }
        .center { text-align: center; }
        .check { width: 90px; }
        .notes { margin-top: 12px; }
        .sign { display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px; margin-top: 28px; text-align: center; }
        .sign .space { height: 64px; }
        .sign .line { border-top: 1px solid #1f1f1f; padding-top: 4px; }
        .foot { margin-top: 20px; font-size: 11px; color: #888; }
        .stamp { position: absolute; top: 160px; left: 50%; transform: translateX(-50%) rotate(-12deg); border: 4px solid #c0392b; color: #c0392b; font-size: 40px; font-weight: 800; padding: 4px 24px; opacity: .35; pointer-events: none; }
        .toolbar { max-width: 800px; margin: 0 auto 16px; display: flex; justify-content: flex-end; gap: 8px; }
        .toolbar button { font: inherit; padding: 6px 14px; border-radius: 6px; border: 1px solid #ccc; background: #fff; cursor: pointer; }
        .toolbar button.primary { background: #ee4d2d; border-color: #ee4d2d; color: #fff; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        @page { size: A4; margin: 12mm; }
        @media print { body { padding: 0; } .toolbar { display: none; } }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" onclick="window.close()">Tutup</button>
        <button type="button" class="primary" onclick="window.print()">Cetak</button>
    </div>

    <div class="sheet">
        @if ($transfer->status === 'cancelled')
            <div class="stamp">DIBATALKAN</div>
        @endif

        <header>
            <div>
                <h1>SURAT JALAN</h1>
                <div class="muted">Transfer Antar Gudang · {{ config('app.name') }}</div>
            </div>
            <div class="right">
                <div class="mono" style="font-size: 18px; font-weight: 700;">{{ $transfer->number }}</div>
                <div class="muted">{{ $transfer->transfer_date->translatedFormat('d F Y') }}</div>
            </div>
        </header>

        <div class="parties">
            @foreach (['Dari (Pengirim)' => $transfer->fromWarehouse, 'Kepada (Penerima)' => $transfer->toWarehouse] as $title => $warehouse)
                <div class="party">
                    <div class="label">{{ $title }}</div>
                    <strong>{{ $warehouse->code }} · {{ $warehouse->name }}</strong>
                    @if ($text = $address($warehouse))
                        <div class="pre">{{ $text }}</div>
                    @endif
                    @if ($text = $contact($warehouse))
                        <div class="muted">{{ $text }}</div>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="meta">
            <div><div class="label">Referensi</div><div>{{ $transfer->reference ?? '—' }}</div></div>
            <div><div class="label">Dibuat oleh</div><div>{{ $transfer->creator?->name ?? '—' }} · {{ $transfer->created_at->format('d/m/Y H:i') }}</div></div>
            <div><div class="label">Status</div><div>{{ $transfer->statusLabel() }}</div></div>
        </div>

        <table>
            <thead>
                <tr>
                    <th class="center" style="width: 32px;">No</th>
                    <th>SKU</th>
                    <th>Nama Barang</th>
                    <th>Lokasi Asal</th>
                    <th class="right">Jumlah</th>
                    <th class="right check">Diterima</th>
                    <th>Keterangan</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($transfer->items as $item)
                    <tr>
                        <td class="center">{{ $loop->iteration }}</td>
                        <td class="mono"><strong>{{ $item->variant->sku }}</strong></td>
                        <td>{{ $item->variant->product->name }}@if ($item->variant->name)<br><span class="muted">{{ $item->variant->name }}</span>@endif</td>
                        <td class="mono">{{ $item->fromLocation?->code ?? '—' }}</td>
                        <td class="right">
                            <strong>{{ $fmt($item->qty) }}</strong> {{ $item->variant->product->unit->code }}
                            @if ($label = $item->variant->cartonLabel($item->qty))
                                <br><span class="muted">{{ $label }}</span>
                            @endif
                        </td>
                        <td class="right">{{ $item->received_qty !== null ? $fmt($item->received_qty) : '' }}</td>
                        <td>{{ $item->notes }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4" class="right">Total ({{ $transfer->items->count() }} baris)</td>
                    <td class="right">{{ $fmt($transfer->items->sum('qty')) }}</td>
                    <td class="right">{{ $transfer->received_at ? $fmt($transfer->items->sum('received_qty')) : '' }}</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>

        @if ($transfer->notes)
            <div class="notes"><span class="label">Catatan:</span> <span class="pre">{{ $transfer->notes }}</span></div>
        @endif

        <div class="sign">
            <div>
                <div>Pengirim</div>
                <div class="space"></div>
                <div class="line"><strong>{{ $transfer->creator?->name }}</strong>&nbsp;</div>
            </div>
            <div>
                <div>Pengantar / Sopir</div>
                <div class="space"></div>
                <div class="line">&nbsp;</div>
            </div>
            <div>
                <div>Penerima</div>
                <div class="space"></div>
                <div class="line"><strong>{{ $transfer->receiver?->name }}</strong>&nbsp;</div>
            </div>
        </div>

        <div class="foot">Barang diperiksa saat diterima. Selisih jumlah dicatat di kolom Diterima dan dilaporkan ke gudang pengirim. Dicetak {{ now()->format('d/m/Y H:i') }}.</div>
    </div>
</body>
</html>
