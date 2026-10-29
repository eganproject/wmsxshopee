@php
    $fmt = fn ($value) => number_format((int) $value, 0, ',', '.');
    $tones = [
        'rose' => 'bg-rose-50 text-rose-700',
        'amber' => 'bg-amber-50 text-amber-700',
        'slate' => 'bg-slate-100 text-slate-600',
    ];
    $active = collect($groups)->filter(fn ($group) => $group['count'] > 0);
@endphp

<x-layouts.app title="Notifikasi">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Notifikasi</span>
    </x-slot:breadcrumb>

    <x-page-header title="Notifikasi" description="Peringatan stok dan pekerjaan yang tertunda di gudang yang bisa Anda akses.">
        @can('reports.view')
            <a href="{{ route('reports.reorder') }}" class="btn-secondary"><x-heroicon-o-shopping-bag class="size-5" /> Saran Pembelian</a>
        @endcan
    </x-page-header>

    @if ($groups)
        <div class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($groups as $group)
                <a href="#{{ $group['key'] }}" class="card flex items-center gap-3 p-4 hover:bg-slate-50/60">
                    <span @class(['flex size-9 shrink-0 items-center justify-center rounded-lg', $group['count'] ? $tones[$group['tone']] : 'bg-emerald-50 text-emerald-700'])>
                        <x-dynamic-component :component="$group['count'] ? $group['icon'] : 'heroicon-o-check-circle'" class="size-5" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-xs text-slate-500">{{ $group['label'] }}</span>
                        <span class="block text-lg font-semibold tabular-nums text-slate-900">{{ $fmt($group['count']) }}</span>
                    </span>
                </a>
            @endforeach
        </div>
    @endif

    @if ($active->isEmpty())
        <div class="card px-5 py-16 text-center">
            <x-heroicon-o-check-badge class="mx-auto size-12 text-emerald-600" />
            <p class="mt-3 font-medium text-slate-700">Tidak ada peringatan</p>
            <p class="text-sm text-slate-500">Stok aman dan tidak ada pekerjaan yang tertunda.</p>
        </div>
    @endif

    <div class="space-y-5">
        @foreach ($active as $group)
            <section id="{{ $group['key'] }}" class="card scroll-mt-16">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-5 py-3">
                    <div class="flex items-center gap-2">
                        <span @class(['flex size-7 items-center justify-center rounded-lg', $tones[$group['tone']]])>
                            <x-dynamic-component :component="$group['icon']" class="size-4" />
                        </span>
                        <div>
                            <h2 class="text-base font-semibold text-slate-900">{{ $group['label'] }} <span class="text-slate-500 tabular-nums">({{ $fmt($group['count']) }})</span></h2>
                            <p class="text-xs text-slate-500">{{ $group['hint'] }}</p>
                        </div>
                    </div>
                    @switch($group['key'])
                        @case('low_stock')
                            <div class="flex gap-3 text-sm">
                                @can('reports.view')
                                    <a href="{{ route('reports.min-stock') }}" class="text-slate-600 hover:text-slate-900">Lihat semua</a>
                                    <a href="{{ route('reports.reorder') }}" class="font-medium text-brand-600 hover:text-brand-700">Saran pembelian</a>
                                @endcan
                            </div>
                            @break
                        @case('refill')
                            <a href="{{ route('inventory.transfers.index') }}" class="text-sm font-medium text-brand-600 hover:text-brand-700">Buat transfer</a>
                            @break
                        @case('bundles')
                            @can('products.view')
                                <a href="{{ route('master.bundles.index') }}" class="text-sm text-slate-600 hover:text-slate-900">Lihat paket</a>
                            @endcan
                            @break
                        @case('held_orders')
                            <a href="{{ route('sales.orders.index', ['status' => 'draft']) }}" class="text-sm text-slate-600 hover:text-slate-900">Lihat pesanan</a>
                            @break
                        @case('courier_pending')
                        @case('shopee_ahead')
                            <a href="{{ route('sales.orders.index', ['shopee' => $group['key'] === 'courier_pending' ? 'courier_overdue' : 'shopee_ahead']) }}" class="text-sm text-slate-600 hover:text-slate-900">Lihat pesanan</a>
                            @break
                        @case('qc_skipped')
                            <a href="{{ route('sales.orders.index', ['qc' => 'unchecked']) }}" class="text-sm text-slate-600 hover:text-slate-900">Lihat pesanan</a>
                            @break
                        @case('pending_orders')
                        @case('draft_orders')
                            <a href="{{ route('sales.orders.index', $group['key'] === 'draft_orders' ? ['status' => 'draft'] : []) }}" class="text-sm text-slate-600 hover:text-slate-900">Lihat pesanan</a>
                            @break
                        @case('draft_receipts')
                            <a href="{{ route('inventory.receipts.index', ['status' => 'draft']) }}" class="text-sm text-slate-600 hover:text-slate-900">Lihat penerimaan</a>
                            @break
                        @case('marketplace')
                            <a href="{{ route('marketplace.sync-logs') }}" class="text-sm text-slate-600 hover:text-slate-900">Riwayat sinkron</a>
                            @break
                        @case('pending_adjustments')
                            <a href="{{ route('inventory.adjustments.index', ['status' => 'pending']) }}" class="text-sm text-slate-600 hover:text-slate-900">Lihat penyesuaian</a>
                            @break
                    @endswitch
                </div>

                <ul class="divide-y divide-slate-100">
                    @foreach ($group['items'] as $item)
                        <li class="flex items-center gap-3 px-5 py-2.5">
                            @switch($group['key'])
                                @case('low_stock')
                                    <div class="min-w-0 flex-1">
                                        <a href="{{ route('inventory.stocks.index', ['view' => 'location', 'search' => $item->sku]) }}" class="font-mono text-sm font-medium text-slate-900 hover:text-brand-600">{{ $item->sku }}</a>
                                        <p class="truncate text-xs text-slate-500">{{ $item->product_name }}{{ $item->variant_name ? ' · '.$item->variant_name : '' }}</p>
                                    </div>
                                    <div class="text-right">
                                        <p @class(['text-sm font-semibold tabular-nums', 'text-rose-600' => $item->available <= 0, 'text-amber-700' => $item->available > 0])>
                                            {{ $item->available <= 0 ? 'Habis' : $fmt($item->available).' '.$item->unit_code }}
                                        </p>
                                        <p class="text-xs text-slate-500 tabular-nums">min. {{ $fmt($item->min_stock) }}</p>
                                    </div>
                                    @break
                                @case('refill')
                                    <div class="min-w-0 flex-1">
                                        <p class="font-mono text-sm font-medium text-slate-900">{{ $item->variant->sku }}</p>
                                        <p class="truncate text-xs text-slate-500">{{ $item->warehouse->code }} ← {{ $item->supply->code }}{{ $item->urgent ? ' · ada pesanan menunggu' : '' }}</p>
                                    </div>
                                    <div class="text-right">
                                        <p @class(['text-sm font-semibold tabular-nums', 'text-rose-600' => $item->urgent, 'text-amber-700' => ! $item->urgent])>tersedia {{ $fmt($item->available) }}</p>
                                        <p class="text-xs text-slate-500 tabular-nums">saran kirim {{ $fmt($item->qty) }}</p>
                                    </div>
                                    @break
                                @case('bundles')
                                    <div class="min-w-0 flex-1">
                                        <p class="font-mono text-sm font-medium text-slate-900">{{ $item->sku }}</p>
                                        <p class="truncate text-xs text-slate-500">{{ $item->name }}</p>
                                    </div>
                                    <p class="max-w-[50%] truncate text-right text-xs text-slate-500" title="Komponen">{{ $item->items->map(fn ($component) => $component->qty.'× '.$component->variant?->sku)->join(', ') }}</p>
                                    @break
                                @case('held_orders')
                                    <div class="min-w-0 flex-1">
                                        <a href="{{ route('sales.orders.show', $item) }}" class="text-sm font-medium text-slate-900 hover:text-brand-600">{{ $item->number }}</a>
                                        <p class="truncate text-xs text-slate-500">{{ $item->channel_name }} · {{ $item->warehouse?->code }}{{ $item->external_number ? ' · '.$item->external_number : '' }}</p>
                                        @forelse ($item->shortages as $short)
                                            <p class="text-xs">
                                                <span class="font-mono font-medium text-slate-800">{{ $short->variant->sku }}</span>
                                                <span class="text-rose-600">kurang {{ $fmt($short->short) }}</span>
                                                <span class="text-slate-500">(dipesan {{ $fmt($short->needed) }}, tersedia {{ $fmt($short->sellable) }})</span>
                                                @if ($short->sources->isNotEmpty())
                                                    <span class="text-slate-500">· stok ada di {{ $short->sources->map(fn ($s) => $s->warehouse->code.' '.$fmt($s->available))->join(', ') }}</span>
                                                @else
                                                    <span class="text-slate-500">· habis di semua gudang</span>
                                                @endif
                                            </p>
                                        @empty
                                            <p class="text-xs text-emerald-700">Stok sudah cukup, segera dikonfirmasi otomatis.</p>
                                        @endforelse
                                    </div>
                                    <div class="shrink-0 text-right">
                                        @if ($item->transferFrom)
                                            @can('inventory.create')
                                                <a href="{{ route('inventory.transfers.create', ['held' => $item->id]) }}" class="btn-secondary px-2.5 py-1 text-xs">
                                                    <x-heroicon-o-truck class="size-4" /> Transfer dari {{ $item->transferFrom->code }}
                                                </a>
                                            @endcan
                                        @elseif ($item->shortages->isNotEmpty())
                                            @can('receipts.create')
                                                <a href="{{ route('inventory.receipts.create') }}" class="text-xs font-medium text-brand-600 hover:text-brand-700">Catat penerimaan</a>
                                            @endcan
                                        @endif
                                        <p class="mt-1 text-xs text-slate-500" title="{{ $item->ordered_at?->translatedFormat('d M Y H:i') }}">{{ ($item->ordered_at ?? $item->created_at)?->diffForHumans() }}</p>
                                    </div>
                                    @break
                                @case('courier_pending')
                                @case('shopee_ahead')
                                    @php($since = $group['key'] === 'courier_pending' ? $item->shipped_at : $item->shopee_synced_at)
                                    <div class="min-w-0 flex-1">
                                        <a href="{{ route('sales.orders.show', $item) }}" class="text-sm font-medium text-slate-900 hover:text-brand-600">{{ $item->number }}</a>
                                        <p class="truncate text-xs text-slate-500">{{ $item->tracking_number ?? $item->external_number }} · {{ $item->warehouse?->code }}</p>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-xs font-medium text-slate-700">Gudang: {{ $item->statusLabel() }} · {{ $item->isTiktok() ? 'TikTok: '.$item->tiktokStatusLabel() : 'Shopee: '.$item->shopeeStatusLabel() }}</p>
                                        <p class="text-xs text-slate-500" title="{{ $since?->translatedFormat('d M Y H:i') }}">{{ $since?->diffForHumans() }}</p>
                                    </div>
                                    @break
                                @case('qc_skipped')
                                    <div class="min-w-0 flex-1">
                                        <a href="{{ route('sales.orders.show', $item) }}" class="text-sm font-medium text-slate-900 hover:text-brand-600">{{ $item->number }}</a>
                                        <p class="truncate text-xs text-slate-500">{{ $item->tracking_number ?? $item->external_number }} · {{ $item->warehouse?->code }}</p>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-xs font-medium text-slate-700">Dikirim otomatis dari {{ $item->isTiktok() ? 'TikTok' : 'Shopee' }}</p>
                                        <p class="text-xs text-slate-500" title="{{ $item->shipped_at?->translatedFormat('d M Y H:i') }}">{{ $item->shipped_at?->diffForHumans() }}</p>
                                    </div>
                                    @break
                                @case('pending_orders')
                                @case('draft_orders')
                                    @php($since = $group['key'] === 'pending_orders' ? $item->confirmed_at : $item->created_at)
                                    <div class="min-w-0 flex-1">
                                        <a href="{{ route('sales.orders.show', $item) }}" class="text-sm font-medium text-slate-900 hover:text-brand-600">{{ $item->number }}</a>
                                        <p class="truncate text-xs text-slate-500">{{ $item->customer_name }} · {{ $item->warehouse?->code }}{{ $item->external_number ? ' · '.$item->external_number : '' }}</p>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-xs font-medium text-slate-700">{{ \App\Models\SalesOrder::STATUSES[$item->status]['label'] ?? $item->status }}</p>
                                        <p class="text-xs text-slate-500" title="{{ $since?->translatedFormat('d M Y H:i') }}">{{ $since?->diffForHumans() }}</p>
                                    </div>
                                    @break
                                @case('marketplace')
                                    <div class="min-w-0 flex-1">
                                        <a href="{{ $item->url }}" class="text-sm font-medium text-slate-900 hover:text-brand-600">{{ $item->shop }}</a>
                                        <p @class(['text-xs', 'text-rose-600' => $item->severe, 'text-slate-500' => ! $item->severe])>{{ $item->problem }}</p>
                                    </div>
                                    @if ($item->since)
                                        <p class="shrink-0 text-xs text-slate-500" title="{{ $item->since->translatedFormat('d M Y H:i') }}">{{ $item->since->diffForHumans() }}</p>
                                    @endif
                                    @break
                                @case('draft_receipts')
                                    <div class="min-w-0 flex-1">
                                        <a href="{{ route('inventory.receipts.show', $item) }}" class="text-sm font-medium text-slate-900 hover:text-brand-600">{{ $item->number }}</a>
                                        <p class="truncate text-xs text-slate-500">{{ $item->supplier?->name ?? 'Tanpa supplier' }} · {{ $item->warehouse?->code }}</p>
                                    </div>
                                    <p class="text-xs text-slate-500" title="{{ $item->created_at?->translatedFormat('d M Y H:i') }}">{{ $item->created_at?->diffForHumans() }}</p>
                                    @break
                                @case('pending_adjustments')
                                    <div class="min-w-0 flex-1">
                                        <a href="{{ route('inventory.adjustments.show', $item) }}" class="text-sm font-medium text-slate-900 hover:text-brand-600">{{ $item->number }}</a>
                                        <p class="truncate text-xs text-slate-500">{{ $item->reasonLabel() }} · {{ $item->warehouse?->code }} · diajukan {{ $item->submitter?->name ?? '—' }}</p>
                                    </div>
                                    <p class="text-xs text-slate-500" title="{{ $item->submitted_at?->translatedFormat('d M Y H:i') }}">{{ $item->submitted_at?->diffForHumans() }}</p>
                                    @break
                            @endswitch
                        </li>
                    @endforeach
                    @if ($group['count'] > $group['items']->count())
                        <li class="px-5 py-2.5 text-xs text-slate-500">dan {{ $fmt($group['count'] - $group['items']->count()) }} lainnya</li>
                    @endif
                </ul>
            </section>
        @endforeach
    </div>
</x-layouts.app>
