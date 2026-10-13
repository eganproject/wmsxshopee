<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Lembar Opname {{ $count->number }} · {{ config('app.name') }}</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 24px; font: 13px/1.45 system-ui, -apple-system, "Segoe UI", sans-serif; color: #1f1f1f; background: #fff; }
        .sheet { max-width: 900px; margin: 0 auto; }
        header { display: flex; justify-content: space-between; gap: 16px; border-bottom: 2px solid #1f1f1f; padding-bottom: 12px; }
        h1 { margin: 0; font-size: 20px; }
        .muted { color: #666; }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { border-bottom: 1px solid #ddd; padding: 7px 6px; text-align: left; vertical-align: top; }
        th { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #666; }
        .right { text-align: right; }
        .bin { font-weight: 700; }
        .box { width: 80px; height: 22px; border: 1.5px solid #1f1f1f; border-radius: 4px; display: inline-block; }
        tr.group td { background: #f4f4f4; font-weight: 600; }
        .sign { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 32px; margin-top: 40px; }
        .sign div { border-top: 1px solid #1f1f1f; padding-top: 4px; text-align: center; }
        .toolbar { max-width: 900px; margin: 0 auto 16px; display: flex; justify-content: flex-end; gap: 8px; }
        .toolbar a, .toolbar button { font: inherit; padding: 6px 14px; border-radius: 6px; border: 1px solid #ccc; background: #fff; cursor: pointer; color: inherit; text-decoration: none; }
        .toolbar button.primary { background: #ee4d2d; border-color: #ee4d2d; color: #fff; }
        @media print { body { padding: 0; } .toolbar { display: none; } tr { break-inside: avoid; } }
    </style>
</head>
<body>
    <div class="toolbar">
        @if ($count->isDraft())
            <a href="{{ route('inventory.counts.sheet', ['count' => $count, 'system' => $showSystem ? 0 : 1]) }}">{{ $showSystem ? 'Sembunyikan saldo sistem' : 'Tampilkan saldo sistem' }}</a>
        @endif
        <button type="button" onclick="window.close()">Tutup</button>
        <button type="button" class="primary" onclick="window.print()">Cetak</button>
    </div>

    <div class="sheet">
        <header>
            <div>
                <h1>Lembar Stock Opname</h1>
                <div class="muted">{{ config('app.name') }} · Gudang {{ $count->warehouse->code }} · {{ $count->warehouse->name }}</div>
                <div class="muted">Cakupan: {{ $count->scopeLabel() }}</div>
            </div>
            <div class="right">
                <div class="mono" style="font-size: 18px; font-weight: 700;">{{ $count->number }}</div>
                <div class="muted">Tanggal opname {{ $count->count_date->format('d/m/Y') }}</div>
                <div class="muted">Dicetak {{ now()->format('d/m/Y H:i') }}</div>
            </div>
        </header>

        <table>
            <thead>
                <tr>
                    <th style="width: 32px;">#</th>
                    <th>SKU</th>
                    <th>Produk / Varian</th>
                    <th>Satuan</th>
                    @if ($showSystem)
                        <th class="right">Sistem</th>
                    @endif
                    <th class="right">Hasil Hitung</th>
                    <th>Catatan</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($count->items->groupBy(fn ($item) => $item->location?->code ?? 'Tanpa bin') as $location => $items)
                    <tr class="group"><td colspan="{{ $showSystem ? 7 : 6 }}" class="bin {{ $location === 'Tanpa bin' ? '' : 'mono' }}">{{ $location }}</td></tr>
                    @foreach ($items as $item)
                        <tr>
                            <td class="muted">{{ $loop->parent->index + 1 }}.{{ $loop->iteration }}</td>
                            <td class="mono">{{ $item->variant->sku }}</td>
                            <td>{{ $item->variant->product->name }}{{ $item->variant->name ? ' / '.$item->variant->name : '' }}</td>
                            <td>{{ $item->variant->product->unit->code }}</td>
                            @if ($showSystem)
                                <td class="right">{{ number_format($item->system_qty, 0, ',', '.') }}</td>
                            @endif
                            <td class="right">
                                @if ($count->isDraft() || $item->counted_qty === null)
                                    <span class="box"></span>
                                @else
                                    {{ number_format($item->counted_qty, 0, ',', '.') }}
                                @endif
                            </td>
                            <td></td>
                        </tr>
                    @endforeach
                @endforeach
                @if ($count->isDraft())
                    <tr class="group"><td colspan="{{ $showSystem ? 7 : 6 }}">Barang ditemukan, belum ada di lembar (tulis lokasi &amp; SKU)</td></tr>
                    @for ($i = 0; $i < 5; $i++)
                        <tr><td>&nbsp;</td><td></td><td></td><td></td>@if ($showSystem)<td></td>@endif<td class="right"><span class="box"></span></td><td></td></tr>
                    @endfor
                @endif
            </tbody>
        </table>

        <div class="sign">
            <div>Penghitung</div>
            <div>Pemeriksa</div>
            <div>Kepala Gudang</div>
        </div>
    </div>
</body>
</html>
