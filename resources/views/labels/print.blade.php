@php
    $sheet = $size['sheet'] ?? null;
    $isProduct = $kind === 'products';
    $symbol = $options['symbol'];
    $flag = fn (string $key, bool $default = true) => (bool) ($options[$key] ?? $default);
    $mm = fn (float $value) => rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.').'mm';
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $isProduct ? 'Label Produk' : 'Label Lokasi' }} · {{ config('app.name') }}</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <style>
        @page { size: {{ $sheet ? $mm($sheet['width']).' '.$mm($sheet['height']) : $mm($size['width']).' '.$mm($size['height']) }}; margin: 0; }

        * { box-sizing: border-box; }
        html, body { margin: 0; }
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: #000; background: #e7e7e7; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }

        .toolbar { position: sticky; top: 0; z-index: 1; display: flex; flex-wrap: wrap; align-items: center; gap: 12px; padding: 10px 20px; background: #fff; border-bottom: 1px solid #ddd; font-size: 13px; color: #1f1f1f; }
        .toolbar strong { font-size: 14px; }
        .toolbar .muted { color: #666; }
        .toolbar .spacer { flex: 1; }
        .toolbar button { font: inherit; height: 32px; padding: 0 14px; border-radius: 8px; border: 0; background: #f0f0f0; color: #1f1f1f; cursor: pointer; }
        .toolbar button.primary { background: #171717; color: #fff; }

        .pages { display: flex; flex-wrap: wrap; justify-content: center; gap: 12px; padding: 20px; }
        .page { background: #fff; overflow: hidden; box-shadow: 0 1px 3px rgba(0, 0, 0, .15); }
        .page.thermal { width: {{ $mm($size['width']) }}; height: {{ $mm($size['height']) }}; }
        .page.sheet {
            width: {{ $mm($sheet['width'] ?? 0) }}; height: {{ $mm($sheet['height'] ?? 0) }};
            padding: {{ $mm($sheet['margin_top'] ?? 0) }} 0 0 {{ $mm($sheet['margin_left'] ?? 0) }};
            display: grid; align-content: start;
            grid-template-columns: repeat({{ $sheet['columns'] ?? 1 }}, {{ $mm($size['width']) }});
            grid-auto-rows: {{ $mm($size['height']) }};
            column-gap: {{ $mm($sheet['gap_x'] ?? 0) }}; row-gap: {{ $mm($sheet['gap_y'] ?? 0) }};
        }
        .page.sheet .label { outline: 1px dashed #d4d4d4; outline-offset: -1px; }

        /* Isi label. Ukuran huruf dasar diskalakan dari label 50 × 30 mm. */
        .label { position: relative; width: {{ $mm($size['width']) }}; height: {{ $mm($size['height']) }}; padding: {{ $mm(1.6 * $scale) }} {{ $mm(2 * $scale) }}; display: flex; flex-direction: column; gap: {{ $mm(0.6 * $scale) }}; overflow: hidden; font-size: {{ round(7 * $scale, 2) }}pt; line-height: 1.2; }
        .row { display: flex; gap: {{ $mm(1.6 * $scale) }}; min-height: 0; }
        .row.fill { flex: 1 1 auto; }
        .row.top { flex: 0 0 52%; }
        .text { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; justify-content: center; gap: {{ $mm(0.4 * $scale) }}; }
        .qr { flex: 0 0 auto; height: 100%; aspect-ratio: 1; max-width: 46%; }
        .qr svg { display: block; width: 100%; height: 100%; }
        .bars { flex: 1 1 auto; min-height: {{ $mm(3) }}; }
        .bars { padding: 0 {{ $mm(1.2 * $scale) }}; }
        .bars svg { display: block; width: 100%; height: 100%; }
        .value { text-align: center; font-size: .95em; letter-spacing: .06em; white-space: nowrap; }
        .clamp { overflow: hidden; display: -webkit-box; -webkit-box-orient: vertical; word-break: break-word; }
        .title { font-weight: 700; -webkit-line-clamp: 2; }
        .title.long { -webkit-line-clamp: 3; }
        .sub { color: #222; -webkit-line-clamp: 1; }
        .sku { font-weight: 700; font-size: 1.1em; white-space: nowrap; }
        .path { font-size: .9em; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .code { font-weight: 800; font-size: 2.8em; line-height: 1.05; white-space: nowrap; letter-spacing: .02em; }
        .center { text-align: center; }
        .invalid { flex: 1; display: flex; align-items: center; justify-content: center; border: 1px dashed #c00; color: #c00; font-size: .9em; text-align: center; }

        @if ($preview)
            body { background: transparent; display: flex; align-items: center; justify-content: center; height: 100vh; overflow: hidden; }
            .preview { background: #fff; box-shadow: 0 1px 4px rgba(0, 0, 0, .2); transform-origin: center; }
        @endif

        @media print {
            body { background: none; }
            .toolbar { display: none; }
            .pages { display: block; padding: 0; }
            .page { box-shadow: none; break-after: page; page-break-after: always; }
            .page:last-child { break-after: auto; page-break-after: auto; }
            .page.sheet .label { outline: 0; }
        }
    </style>
</head>
<body>
    @unless ($preview)
        <div class="toolbar">
            <div>
                <strong>{{ $isProduct ? 'Label Produk' : 'Label Lokasi Bin' }}</strong>
                <span class="muted">· {{ number_format($count, 0, ',', '.') }} label · {{ $size['label'] }} · {{ $pages->count() }} halaman</span>
            </div>
            <div class="muted">
                @if ($sheet)
                    Kertas A4, margin <em>None</em>, skala 100% (<em>Actual size</em>).
                @else
                    Kertas {{ $size['width'] }} × {{ $size['height'] }} mm di driver printer, margin <em>None</em>, skala 100%.
                @endif
            </div>
            <span class="spacer"></span>
            <button type="button" onclick="window.close()">Tutup</button>
            <button type="button" class="primary" onclick="window.print()">Cetak</button>
        </div>
    @endunless

    @if ($preview)
        @if ($label = $pages->flatten(1)->first())
            <div class="preview" id="preview">@include('labels._label')</div>
            <script>
                // Perbesar label agar memenuhi bingkai pratinjau.
                const fit = () => {
                    const el = document.getElementById('preview');
                    el.style.transform = 'none';
                    const scale = Math.min((innerWidth - 16) / el.offsetWidth, (innerHeight - 16) / el.offsetHeight);
                    el.style.transform = `scale(${scale})`;
                };
                addEventListener('resize', fit);
                document.fonts.ready.then(fit);
                fit();
            </script>
        @endif
    @else
        <div class="pages">
            @foreach ($pages as $page)
                <div class="page {{ $sheet ? 'sheet' : 'thermal' }}">
                    @foreach ($page as $label)
                        @include('labels._label')
                    @endforeach
                </div>
            @endforeach
        </div>
    @endif

    <script>
        // Kecilkan teks satu baris (SKU, kode lokasi) yang lebih lebar dari labelnya.
        document.querySelectorAll('[data-fit]').forEach((el) => {
            let size = parseFloat(getComputedStyle(el).fontSize);
            while (el.scrollWidth > el.clientWidth && size > 5) {
                size -= 0.5;
                el.style.fontSize = `${size}px`;
            }
        });
    </script>
</body>
</html>
