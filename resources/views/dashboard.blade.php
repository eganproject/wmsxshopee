
<x-layouts.app title="Dashboard">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" /> <span class="font-medium text-slate-700">Dashboard</span>
    </x-slot:breadcrumb>

    {{-- Sambutan & filter gudang --}}
    <div class="mb-6 flex flex-col gap-4 border-b border-slate-200 pb-5 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-sm text-slate-500">{{ now()->translatedFormat('l, d F Y') }}</p>
            <h1 class="mt-1 text-xl font-semibold tracking-normal text-slate-900">Halo, {{ auth()->user()->name }} 👋</h1>
            <p class="mt-1 text-sm text-slate-500">Ringkasan operasional {{ config('app.name') }} hari ini.</p>
        </div>
        <form method="GET" class="flex items-center gap-2">
            <select name="warehouse" class="form-input sm:w-56" onchange="this.form.submit()" aria-label="Filter gudang">
                <option value="">Semua gudang</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}" @selected(request('warehouse') == $warehouse->id)>{{ $warehouse->code }} · {{ $warehouse->name }}</option>
                @endforeach
            </select>
            <noscript><button type="submit" class="btn-secondary">Terapkan</button></noscript>
        </form>
    </div>

    {{-- Angka utama --}}
    @php
        $fmt = fn ($value) => number_format((int) $value, 0, ',', '.');
        $wh = request('warehouse');
        $cards = array_filter([
            $orders ? ['label' => 'Pesanan Perlu Diproses', 'value' => $orders['open'], 'hint' => 'Dikonfirmasi, diambil & dikemas', 'icon' => 'heroicon-o-clipboard-document-list', 'tone' => 'sky',
                'url' => route('sales.orders.index', ['warehouse' => $wh])] : null,
            $orders ? ['label' => 'Dikirim Hari Ini', 'value' => $orders['shipped_today'], 'hint' => 'Pesanan berstatus Dikirim', 'icon' => 'heroicon-o-truck', 'tone' => 'emerald',
                'url' => route('sales.orders.index', ['status' => 'shipped', 'warehouse' => $wh])] : null,
            $receipts ? ['label' => 'Penerimaan Hari Ini', 'value' => $receipts['today_count'], 'hint' => $fmt($receipts['drafts']).' draft belum diterima', 'icon' => 'heroicon-o-inbox-arrow-down', 'tone' => 'violet',
                'url' => route('inventory.receipts.index', ['from' => today()->toDateString(), 'to' => today()->toDateString(), 'warehouse' => $wh])] : null,
            $lowStock ? ['label' => 'Stok Menipis', 'value' => $lowStock['count'], 'hint' => 'SKU di bawah stok minimum', 'icon' => 'heroicon-o-bell-alert', 'tone' => $lowStock['count'] ? 'rose' : 'slate',
                'url' => auth()->user()->can('reports.view') ? route('reports.min-stock', ['warehouse' => $wh]) : null] : null,
        ]);
        $tones = [
            'sky' => 'bg-sky-50 text-sky-700',
            'emerald' => 'bg-emerald-50 text-emerald-700',
            'violet' => 'bg-violet-50 text-violet-700',
            'rose' => 'bg-rose-50 text-rose-700',
            'slate' => 'bg-slate-100 text-slate-600',
        ];
    @endphp
    @if ($cards)
        <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
            @foreach ($cards as $card)
                <a @if ($card['url']) href="{{ $card['url'] }}" @endif @class(['card flex items-start gap-3 p-4 transition', 'hover:border-slate-300' => $card['url']])>
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-lg {{ $tones[$card['tone']] }}">
                        <x-dynamic-component :component="$card['icon']" class="size-5" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-sm text-slate-500">{{ $card['label'] }}</p>
                        <p class="text-xl font-semibold text-slate-900 tabular-nums">{{ $fmt($card['value']) }}</p>
                        <p class="mt-0.5 truncate text-xs text-slate-500">{{ $card['hint'] }}</p>
                    </div>
                </a>
            @endforeach
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Pesanan per status --}}
        @if ($orders)
            <div class="card lg:col-span-3">
                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3">
                    <h2 class="text-base font-semibold text-slate-900">Pesanan per Status</h2>
                    <a href="{{ route('sales.orders.index', ['warehouse' => $wh]) }}" class="text-sm text-slate-600 hover:text-slate-900">Lihat pesanan</a>
                </div>
                <div class="grid grid-cols-2 divide-slate-100 sm:grid-cols-3 lg:grid-cols-6 lg:divide-x">
                    @foreach ($orders['counts'] as $status => $total)
                        <a href="{{ route('sales.orders.index', ['status' => $status, 'warehouse' => $wh]) }}" class="flex flex-col gap-2 px-5 py-4 hover:bg-slate-50/60">
                            <span><x-sales.status-badge :status="$status" /></span>
                            <span class="text-2xl font-semibold text-slate-900 tabular-nums">{{ $fmt($total) }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Grafik pergerakan barang --}}
        @if ($chart)
            <div class="card flex flex-col lg:col-span-2">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-5 py-3">
                    <h2 class="text-base font-semibold text-slate-900">Pergerakan Barang 14 Hari</h2>
                    <div class="flex items-center gap-4 text-xs text-slate-500">
                        <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-emerald-600"></span> Masuk <b class="font-medium text-slate-900 tabular-nums">{{ $fmt($chart['in']) }}</b></span>
                        <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-rose-600"></span> Keluar <b class="font-medium text-slate-900 tabular-nums">{{ $fmt($chart['out']) }}</b></span>
                    </div>
                </div>
                <div class="px-5 pt-5 pb-3">
                    <div class="flex h-48 items-end gap-1 border-b border-slate-200 sm:gap-2">
                        @foreach ($chart['days'] as $day)
                            <div class="group relative flex h-full flex-1 items-end justify-center gap-px sm:gap-0.5">
                                <div class="w-1/2 max-w-3 rounded-t-sm bg-emerald-600" style="height: {{ $day['in'] ? max(2, round($day['in'] / $chart['max'] * 100, 1)) : 0 }}%"></div>
                                <div class="w-1/2 max-w-3 rounded-t-sm bg-rose-600" style="height: {{ $day['out'] ? max(2, round($day['out'] / $chart['max'] * 100, 1)) : 0 }}%"></div>
                                <div class="pointer-events-none absolute bottom-full left-1/2 z-10 mb-1 hidden -translate-x-1/2 rounded-md border border-slate-200 bg-white px-2.5 py-1.5 text-xs whitespace-nowrap shadow-sm group-hover:block">
                                    <p class="font-medium text-slate-900">{{ $day['date']->translatedFormat('D, d M') }}</p>
                                    <p class="text-slate-500">Masuk <span class="text-slate-900 tabular-nums">{{ $fmt($day['in']) }}</span> · Keluar <span class="text-slate-900 tabular-nums">{{ $fmt($day['out']) }}</span></p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-1.5 flex gap-1 text-center text-[11px] text-slate-400 sm:gap-2">
                        @foreach ($chart['days'] as $day)
                            <span @class(['flex-1 tabular-nums', 'font-medium text-slate-700' => $day['date']->isToday(), 'max-sm:invisible' => $loop->index % 2])>{{ $day['date']->format('d/m') }}</span>
                        @endforeach
                    </div>
                </div>
                <div class="mt-auto flex justify-end border-t border-slate-100 px-5 py-2.5">
                    @can('reports.view')
                        <a href="{{ route('reports.movements', ['from' => $chart['days']->first()['date']->toDateString(), 'to' => today()->toDateString(), 'warehouse' => $wh]) }}" class="text-sm text-slate-600 hover:text-slate-900">Laporan pergerakan barang</a>
                    @else
                        <a href="{{ route('inventory.movements.index') }}" class="text-sm text-slate-600 hover:text-slate-900">Lihat mutasi stok</a>
                    @endcan
                </div>
            </div>
        @endif

        {{-- Stok menipis --}}
        @if ($lowStock)
            <div class="card">
                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3">
                    <h2 class="text-base font-semibold text-slate-900">Stok Menipis</h2>
                    @can('reports.view')
                        <a href="{{ route('reports.min-stock', ['warehouse' => $wh]) }}" class="text-sm text-slate-600 hover:text-slate-900">Lihat semua</a>
                    @endcan
                </div>
                <ul class="divide-y divide-slate-100">
                    @forelse ($lowStock['rows'] as $row)
                        <li class="flex items-center gap-3 px-5 py-2.5">
                            <div class="min-w-0 flex-1">
                                <a href="{{ route('inventory.stocks.index', ['view' => 'location', 'search' => $row->sku, 'warehouse' => $wh]) }}" class="font-mono text-sm font-medium text-slate-900 hover:text-brand-600">{{ $row->sku }}</a>
                                <p class="truncate text-xs text-slate-500">{{ $row->product_name }}{{ $row->variant_name ? ' · '.$row->variant_name : '' }}</p>
                            </div>
                            <div class="text-right">
                                <p @class(['text-sm font-semibold tabular-nums', 'text-rose-600' => $row->available <= 0, 'text-amber-700' => $row->available > 0])>{{ $fmt($row->available) }} <span class="text-xs font-normal text-slate-500">{{ $row->unit_code }}</span></p>
                                <p class="text-xs text-slate-500 tabular-nums">min. {{ $fmt($row->min_stock) }}</p>
                            </div>
                        </li>
                    @empty
                        <li class="flex flex-col items-center gap-1.5 px-5 py-10 text-center text-sm text-slate-500">
                            <x-heroicon-o-check-circle class="size-6 text-emerald-600" />
                            Semua stok di atas batas minimum.
                        </li>
                    @endforelse
                </ul>
            </div>
        @endif

        {{-- Penerimaan hari ini --}}
        @if ($receipts)
            <div @class(['card', 'lg:col-span-2' => ! $activities])>
                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3">
                    <h2 class="text-base font-semibold text-slate-900">Penerimaan Hari Ini</h2>
                    <a href="{{ route('inventory.receipts.index', ['warehouse' => $wh]) }}" class="text-sm text-slate-600 hover:text-slate-900">Lihat semua</a>
                </div>
                <ul class="divide-y divide-slate-100">
                    @forelse ($receipts['today'] as $receipt)
                        <li class="flex items-center gap-3 px-5 py-2.5">
                            <div class="min-w-0 flex-1">
                                <a href="{{ route('inventory.receipts.show', $receipt) }}" class="text-sm font-medium text-slate-900 hover:text-brand-600">{{ $receipt->number }}</a>
                                <p class="truncate text-xs text-slate-500">{{ $receipt->supplier?->name ?? 'Tanpa supplier' }} · {{ $receipt->warehouse?->code }} · {{ $receipt->items_count }} item</p>
                            </div>
                            <span @class(['rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap',
                                'bg-emerald-50 text-emerald-700' => $receipt->status === 'received',
                                'bg-slate-100 text-slate-600' => $receipt->status !== 'received'])>
                                {{ \App\Models\GoodsReceipt::STATUSES[$receipt->status]['label'] ?? $receipt->status }}
                            </span>
                        </li>
                    @empty
                        <li class="px-5 py-10 text-center text-sm text-slate-500">Belum ada penerimaan barang hari ini.</li>
                    @endforelse
                </ul>
            </div>
        @endif

        {{-- Aktivitas terbaru --}}
        @if ($activities)
            <div @class(['card', 'lg:col-span-2' => $receipts, 'lg:col-span-3' => ! $receipts])>
                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3">
                    <h2 class="text-base font-semibold text-slate-900">Aktivitas Terbaru</h2>
                    <a href="{{ route('admin.activity.index') }}" class="text-sm text-slate-600 hover:text-slate-900">Log aktivitas</a>
                </div>
                <ul class="divide-y divide-slate-100">
                    @forelse ($activities as $log)
                        <li class="flex items-start gap-3 px-5 py-2.5">
                            <x-activity.event-badge :event="$log->event" class="mt-0.5 w-28 shrink-0 justify-center" />
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm text-slate-900">{{ $log->description }}</p>
                                <p class="truncate text-xs text-slate-500">{{ $log->user_name ?? 'Sistem' }} · {{ $log->moduleLabel() }}</p>
                            </div>
                            <span class="shrink-0 text-xs text-slate-500" title="{{ $log->created_at->format('d/m/Y H:i') }}">{{ $log->created_at->diffForHumans() }}</span>
                        </li>
                    @empty
                        <li class="px-5 py-10 text-center text-sm text-slate-500">Belum ada aktivitas.</li>
                    @endforelse
                </ul>
            </div>
        @endif

        @if (! $cards && ! $activities)
            <div class="card flex items-start gap-3 p-5 lg:col-span-3">
                <x-heroicon-o-information-circle class="size-5 shrink-0 text-slate-500" />
                <p class="text-sm text-slate-500">Belum ada ringkasan yang bisa ditampilkan untuk akses Anda. Hubungi admin bila Anda perlu melihat data pesanan, stok atau penerimaan.</p>
            </div>
        @endif
    </div>
</x-layouts.app>
