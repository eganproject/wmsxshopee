<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cetak Resi Gabungan · {{ config('app.name') }}</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <style>
        * { box-sizing: border-box; }
        html, body { height: 100%; }
        body { margin: 0; display: flex; flex-direction: column; font: 14px/1.45 system-ui, -apple-system, "Segoe UI", sans-serif; color: #1f272e; background: #f4f5f6; }
        .bar { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; padding: 12px 16px; background: #fff; border-bottom: 1px solid #e2e6e9; }
        .bar h1 { margin: 0; font-size: 16px; font-weight: 600; }
        .status { flex: 1; min-width: 200px; color: #525252; }
        [data-state="ready"] .status { color: #15803d; }
        [data-state="error"] .status { color: #b91c1c; }
        .status[data-tone="warn"] { color: #b45309; }
        .actions { display: flex; gap: 8px; }
        .btn { font: inherit; padding: 6px 14px; border-radius: 6px; border: 1px solid #d1d8dd; background: #fff; color: inherit; cursor: pointer; text-decoration: none; }
        .btn.primary { background: #171717; border-color: #171717; color: #fff; }
        .btn[disabled], .btn.disabled { opacity: .45; pointer-events: none; }
        #label-batch:not([data-state="ready"]) [data-print], #label-batch:not([data-state="ready"]) [data-download] { opacity: .45; pointer-events: none; }
        details { background: #fff; border-bottom: 1px solid #e2e6e9; padding: 0 16px; }
        summary { cursor: pointer; padding: 8px 0; color: #525252; font-size: 13px; }
        ul { list-style: none; margin: 0 0 10px; padding: 0; font-size: 13px; }
        li { display: flex; flex-wrap: wrap; gap: 4px 10px; padding: 4px 0; border-top: 1px solid #f0f2f4; }
        li .dot { width: 8px; height: 8px; margin-top: 6px; border-radius: 50%; background: #c0c6cc; flex: none; }
        li[data-state="loading"] .dot { background: #f59e0b; }
        li[data-state="ok"] .dot { background: #16a34a; }
        li[data-state="error"] .dot { background: #dc2626; }
        li .title { font-weight: 600; }
        li .numbers { color: #737373; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; }
        li [data-message] { flex-basis: 100%; padding-left: 18px; color: #737373; }
        li[data-state="error"] [data-message] { color: #b91c1c; }
        li[data-state="ok"] [data-message] { display: none; }
        .skipped { margin: 0 0 10px; font-size: 13px; color: #92400e; }
        .viewer { flex: 1; min-height: 0; display: flex; align-items: center; justify-content: center; color: #737373; }
        .viewer iframe { width: 100%; height: 100%; border: 0; background: #525659; }
        #label-batch:not([data-state="ready"]) iframe { display: none; }
        #label-batch[data-state="ready"] .waiting { display: none; }
        @media (prefers-color-scheme: dark) {
            body { background: #171717; color: #e5e5e5; }
            .bar, details { background: #1f1f1f; border-color: #333; }
            .btn { background: #262626; border-color: #404040; }
            .btn.primary { background: #f5f5f5; border-color: #f5f5f5; color: #171717; }
            li { border-color: #2a2a2a; }
            .status, summary { color: #a3a3a3; }
        }
    </style>
    @vite('resources/js/label-batch.js')
</head>
<body id="label-batch" data-state="loading">
    <div class="bar">
        <h1>Cetak Resi Gabungan</h1>
        <span class="status" data-status>Menyiapkan label...</span>
        <div class="actions">
            <button type="button" class="btn" onclick="window.close()">Tutup</button>
            <a class="btn" data-download download="resi-gabungan-{{ now()->format('Ymd-His') }}.pdf">Unduh PDF</a>
            <button type="button" class="btn primary" data-print>Cetak</button>
        </div>
    </div>

    <details open data-parts-wrap>
        <summary>{{ $parts->count() }} bagian · {{ $parts->sum(fn ($part) => count($part['numbers'])) }} pesanan</summary>
        @if ($skipped->isNotEmpty())
            <p class="skipped">Dilewati karena pengiriman belum diatur: {{ $skipped->implode(', ') }}</p>
        @endif
        <ul data-parts>
            @foreach ($parts as $part)
                <li data-state="waiting">
                    <span class="dot"></span>
                    <span class="title">{{ $part['title'] }}</span>
                    <span class="numbers">{{ implode(', ', $part['numbers']) }}</span>
                    <span data-message>Menunggu...</span>
                </li>
            @endforeach
        </ul>
    </details>

    <div class="viewer">
        <span class="waiting">Label sedang diambil dari Shopee / TikTok dan disatukan...</span>
        <iframe data-frame title="Pratinjau resi gabungan"></iframe>
    </div>

    <script type="application/json" id="label-batch-parts">@json($parts->values())</script>
</body>
</html>
