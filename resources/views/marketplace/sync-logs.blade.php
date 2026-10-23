@php
    use App\Http\Controllers\Marketplace\MarketplaceSyncLogController as Logs;
    use App\Models\MarketplaceSyncLog;
    $fmt = fn ($value) => number_format((int) $value, 0, ',', '.');
    $pushTones = [
        'pending' => 'bg-slate-100 text-slate-600',
        'retrying' => 'bg-amber-50 text-amber-700',
        'processed' => 'bg-emerald-50 text-emerald-700',
        'ignored' => 'bg-slate-100 text-slate-500',
        'failed' => 'bg-rose-50 text-rose-700',
    ];
    $duration = fn (int $ms) => $ms >= 60000 ? round($ms / 60000, 1).' mnt' : ($ms >= 1000 ? round($ms / 1000, 1).' dtk' : $ms.' ms');
@endphp

<x-layouts.app title="Riwayat Sinkron Marketplace">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span>Integrasi</span>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Riwayat Sinkron</span>
    </x-slot:breadcrumb>

    <x-page-header title="Riwayat Sinkron" description="Setiap sinkron pesanan, retur, stok dan produk per toko, serta push yang masuk dari Shopee dan TikTok." icon="heroicon-o-arrow-path">
        {{-- Kirim ulang & proses ulang butuh hak sinkron (shopee.sync / tiktok.sync). --}}
        @php($canRetry = auth()->user()->canAny(['shopee.sync', 'tiktok.sync']))
        @if ($canRetry && $summary['failed_stock'])
            <form method="POST" action="{{ route('marketplace.sync-logs.retry-stock') }}" x-data="{ busy: false }" @submit="busy = true">
                @csrf
                <button type="submit" class="btn-secondary" :disabled="busy">
                    <span class="inline-flex" :class="busy && 'animate-spin'"><x-heroicon-o-arrow-path class="size-5" /></span>
                    <span x-text="busy ? 'Mengirim...' : 'Kirim Ulang Stok Gagal'">Kirim Ulang Stok Gagal</span>
                </button>
            </form>
        @endif
        @if ($canRetry && $summary['failed_push'])
            <form method="POST" action="{{ route('marketplace.sync-logs.retry-push') }}">
                @csrf
                <button type="submit" class="btn-secondary"><x-heroicon-o-arrow-uturn-right class="size-5" /> Proses Ulang Push Gagal</button>
            </form>
        @endif
    </x-page-header>

    @foreach ($health as $problem)
        <div class="mb-3 flex gap-3 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800" role="alert">
            <x-heroicon-o-exclamation-triangle class="mt-0.5 size-5 shrink-0" />
            <div>
                <p class="font-medium">{{ $problem['title'] }}</p>
                <p class="mt-0.5 text-xs">{!! preg_replace('/`([^`]+)`/', '<code class="rounded bg-white/70 px-1 font-mono">$1</code>', e($problem['body'])) !!}</p>
            </div>
        </div>
    @endforeach

    <div class="mb-6 grid gap-3 sm:grid-cols-4">
        <a href="{{ route('marketplace.sync-logs', ['status' => 'failed']) }}" class="card px-4 py-3 hover:bg-slate-50/60">
            <p class="text-xs text-slate-500">Sinkron gagal hari ini</p>
            <p @class(['text-xl font-semibold tabular-nums', 'text-rose-600' => $summary['failed_today'], 'text-slate-900' => ! $summary['failed_today']])>{{ $fmt($summary['failed_today']) }}</p>
        </a>
        <a href="{{ route('marketplace.products', ['status' => 'error']) }}" class="card px-4 py-3 hover:bg-slate-50/60">
            <p class="text-xs text-slate-500">SKU gagal kirim stok</p>
            <p @class(['text-xl font-semibold tabular-nums', 'text-rose-600' => $summary['failed_stock'], 'text-slate-900' => ! $summary['failed_stock']])>{{ $fmt($summary['failed_stock']) }}</p>
        </a>
        <a href="{{ route('marketplace.sync-logs', ['tab' => 'push', 'status' => 'failed']) }}" class="card px-4 py-3 hover:bg-slate-50/60">
            <p class="text-xs text-slate-500">Push gagal diproses</p>
            <p @class(['text-xl font-semibold tabular-nums', 'text-rose-600' => $summary['failed_push'], 'text-slate-900' => ! $summary['failed_push']])>{{ $fmt($summary['failed_push']) }}</p>
        </a>
        <a href="{{ route('marketplace.sync-logs', ['tab' => 'push']) }}" class="card px-4 py-3 hover:bg-slate-50/60">
            <p class="text-xs text-slate-500">Push terakhir masuk</p>
            <p class="text-sm font-semibold text-slate-900" title="{{ $summary['last_push'] ? \Illuminate\Support\Carbon::parse($summary['last_push'])->translatedFormat('d M Y H:i') : '' }}">
                {{ $summary['last_push'] ? \Illuminate\Support\Carbon::parse($summary['last_push'])->diffForHumans() : 'Belum ada' }}
            </p>
        </a>
    </div>

    <div class="mb-4 flex gap-1 border-b border-slate-200">
        @foreach (['logs' => 'Riwayat Sinkron', 'push' => 'Push Masuk'] as $value => $label)
            <a href="{{ route('marketplace.sync-logs', array_filter(['tab' => $value === 'push' ? 'push' : null, 'shop' => request('shop')])) }}"
               @class(['-mb-px border-b-2 px-3 py-2 text-sm', 'border-slate-900 font-medium text-slate-900' => $tab === $value, 'border-transparent text-slate-500 hover:text-slate-700' => $tab !== $value])>{{ $label }}</a>
        @endforeach
    </div>

    <div class="card">
        <form method="GET" class="flex flex-col gap-3 border-b border-slate-100 p-4 md:flex-row md:items-center">
            @if ($tab === 'push')
                <input type="hidden" name="tab" value="push">
                <div class="relative flex-1">
                    <x-heroicon-o-magnifying-glass class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-slate-400" />
                    <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari nomor pesanan..." class="form-input pl-8">
                </div>
            @endif
            <select name="shop" class="form-input md:w-64" onchange="this.form.submit()">
                <option value="">Semua toko</option>
                @foreach ($shops as $option)
                    @php($key = $option['platform'].'-'.$option['shop']->id)
                    <option value="{{ $key }}" @selected(request('shop') === $key)>{{ $option['platform'] === 'shopee' ? 'Shopee' : 'TikTok' }} · {{ $option['shop']->displayName() }}</option>
                @endforeach
            </select>
            @if ($tab === 'logs')
                <select name="part" class="form-input md:w-48" onchange="this.form.submit()">
                    <option value="">Semua bagian</option>
                    @foreach (MarketplaceSyncLog::PARTS as $value => $label)
                        <option value="{{ $value }}" @selected(request('part') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            @endif
            <select name="status" class="form-input md:w-44" onchange="this.form.submit()">
                <option value="">Semua status</option>
                @foreach ($tab === 'logs' ? Logs::STATUSES : Logs::PUSH_STATUSES as $value => $label)
                    <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <div class="flex gap-2">
                <button type="submit" class="btn-secondary"><x-heroicon-o-funnel class="size-5" /> Filter</button>
                @if (request()->hasAny(['search', 'status', 'shop', 'part']))
                    <a href="{{ route('marketplace.sync-logs', array_filter(['tab' => $tab === 'push' ? 'push' : null])) }}" class="btn-icon" title="Reset filter"><x-heroicon-o-x-mark class="size-5" /></a>
                @endif
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                    @if ($tab === 'logs')
                        <tr>
                            <th class="h-8 px-4 py-0 font-normal">Waktu</th>
                            <th class="h-8 px-4 py-0 font-normal">Toko</th>
                            <th class="h-8 px-4 py-0 font-normal">Bagian</th>
                            <th class="h-8 px-4 py-0 font-normal">Pemicu</th>
                            <th class="h-8 px-4 py-0 font-normal">Hasil</th>
                            <th class="h-8 px-4 py-0 text-right font-normal">Durasi</th>
                        </tr>
                    @else
                        <tr>
                            <th class="h-8 px-4 py-0 font-normal">Diterima</th>
                            <th class="h-8 px-4 py-0 font-normal">Toko</th>
                            <th class="h-8 px-4 py-0 font-normal">Jenis</th>
                            <th class="h-8 px-4 py-0 font-normal">Pesanan</th>
                            <th class="h-8 px-4 py-0 font-normal">Status</th>
                            <th class="h-8 px-4 py-0 text-right font-normal">Percobaan</th>
                        </tr>
                    @endif
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($rows as $row)
                        @if ($tab === 'logs')
                            <tr class="align-top hover:bg-slate-50/60">
                                <td class="px-4 py-2.5 whitespace-nowrap text-slate-700" title="{{ $row->started_at->translatedFormat('d M Y H:i:s') }}">
                                    {{ $row->started_at->translatedFormat('d M H:i') }}
                                    <p class="text-xs text-slate-400">{{ $row->started_at->diffForHumans() }}</p>
                                </td>
                                <td class="px-4 py-2.5">
                                    <x-marketplace.platform-badge :platform="$row->platform" />
                                    <p class="mt-0.5 text-slate-700">{{ $shopNames[$row->platform.'-'.$row->shop_ref] ?? $row->shop_name }}</p>
                                </td>
                                <td class="px-4 py-2.5 text-slate-700">{{ $row->partLabel() }}</td>
                                <td class="px-4 py-2.5 text-slate-500">{{ MarketplaceSyncLog::TRIGGERS[$row->trigger] ?? $row->trigger }}</td>
                                <td class="max-w-md px-4 py-2.5">
                                    @if ($row->status === 'success')
                                        <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs text-emerald-700"><x-heroicon-o-check-circle class="size-4" /> Berhasil</span>
                                        <p class="mt-1 text-xs text-slate-500">{{ $row->summary }}</p>
                                    @else
                                        <span class="inline-flex items-center gap-1 rounded-full bg-rose-50 px-2 py-0.5 text-xs text-rose-700"><x-heroicon-o-exclamation-triangle class="size-4" /> Gagal</span>
                                        <p class="mt-1 text-xs break-words text-rose-600">{{ $row->error }}</p>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-right whitespace-nowrap text-slate-500 tabular-nums">{{ $duration($row->duration_ms) }}</td>
                            </tr>
                        @else
                            <tr class="align-top hover:bg-slate-50/60">
                                <td class="px-4 py-2.5 whitespace-nowrap text-slate-700" title="{{ $row->created_at->translatedFormat('d M Y H:i:s') }}">
                                    {{ $row->created_at->translatedFormat('d M H:i') }}
                                    <p class="text-xs text-slate-400">{{ $row->created_at->diffForHumans() }}</p>
                                </td>
                                <td class="px-4 py-2.5">
                                    <x-marketplace.platform-badge :platform="$row->platform" />
                                    <p class="mt-0.5 text-slate-700">{{ $shopNames[$row->platform.'-'.$row->shop_ref] ?? ($row->shop_id ?: 'Semua toko') }}</p>
                                </td>
                                <td class="px-4 py-2.5 text-slate-700">{{ \App\Models\MarketplaceWebhookEvent::label($row->platform, $row->event_type) }}</td>
                                <td class="px-4 py-2.5 font-mono text-[13px] text-slate-700">{{ $row->order_id ?? '-' }}</td>
                                <td class="max-w-md px-4 py-2.5">
                                    <span class="rounded-full px-2 py-0.5 text-xs {{ $pushTones[$row->status] ?? 'bg-slate-100 text-slate-600' }}">{{ Logs::PUSH_STATUSES[$row->status] ?? $row->status }}</span>
                                    @if ($row->error)
                                        <p class="mt-1 text-xs break-words text-rose-600">{{ $row->error }}</p>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-right text-slate-500 tabular-nums">{{ $row->attempts }}</td>
                            </tr>
                        @endif
                    @empty
                        <x-empty-state colspan="6" icon="heroicon-o-arrow-path" :title="$tab === 'logs' ? 'Belum ada riwayat sinkron' : 'Belum ada push masuk'"
                            :description="$tab === 'logs' ? 'Riwayat muncul setelah sinkron terjadwal atau manual berjalan.' : 'Push muncul bila URL webhook sudah didaftarkan di Shopee / TikTok dan Optima bisa diakses dari internet.'" :ignore="['tab']" />
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($rows->hasPages())
            <div class="border-t border-slate-100 px-5 py-4">{{ $rows->links() }}</div>
        @endif
    </div>

    <p class="mt-4 text-xs text-slate-500">
        Pesanan disinkron tiap 5 menit dan retur tiap jam. Stok dikirim begitu berubah, dengan kirim ulang semua SKU tiap 30 menit. Gangguan sementara dari Shopee/TikTok diulang otomatis. Riwayat disimpan 30 hari.
    </p>
</x-layouts.app>
