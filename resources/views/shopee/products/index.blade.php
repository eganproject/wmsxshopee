<x-layouts.app title="Status Produk Shopee">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span>Integrasi</span>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Status Produk Shopee</span>
    </x-slot:breadcrumb>

    <x-page-header title="Status Produk Shopee" description="Varian dan paket Optima yang sudah atau belum terhubung ke toko Shopee, beserta stok terakhir yang dikirim." icon="heroicon-o-check-badge">
        <x-reports.export-button />
    </x-page-header>

    <div class="mb-4 flex gap-1 border-b border-slate-200">
        @foreach (['variant' => 'Varian', 'bundle' => 'Paket'] as $value => $label)
            <a href="{{ route('shopee.products.index', [...request()->except(['type', 'page']), 'type' => $value]) }}"
               @class(['-mb-px border-b-2 px-3 py-2 text-sm', 'border-slate-900 font-medium text-slate-900' => $type === $value, 'border-transparent text-slate-500 hover:text-slate-700' => $type !== $value])>{{ $label }}</a>
        @endforeach
    </div>

    <div class="mb-6 grid gap-3 sm:grid-cols-4">
        @foreach ([
            [null, 'Total SKU aktif', $summary['total'], 'text-slate-900'],
            ['linked', $shop ? 'Terhubung di toko ini' : 'Terhubung (min. 1 toko)', $summary['linked'], 'text-emerald-600'],
            ['unlinked', $shop ? 'Belum di toko ini' : 'Belum di toko mana pun', $summary['unlinked'], $summary['unlinked'] ? 'text-amber-600' : 'text-slate-900'],
            ['error', 'Gagal kirim stok', $summary['error'], $summary['error'] ? 'text-rose-600' : 'text-slate-900'],
        ] as [$status, $label, $value, $tone])
            <a href="{{ route('shopee.products.index', [...request()->except(['status', 'page']), 'status' => $status]) }}"
               @class(['card px-4 py-3 hover:bg-slate-50/60', 'ring-1 ring-slate-900' => request('status') === $status && ($status !== null || ! request()->filled('status'))])>
                <p class="text-xs text-slate-500">{{ $label }}</p>
                <p class="text-xl font-semibold tabular-nums {{ $tone }}">{{ number_format($value, 0, ',', '.') }}</p>
            </a>
        @endforeach
    </div>

    <div class="card">
        <form method="GET" class="flex flex-col gap-3 border-b border-slate-100 p-4 md:flex-row md:items-center">
            <input type="hidden" name="type" value="{{ $type }}">
            <div class="relative flex-1">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-slate-400" />
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari SKU atau nama..." class="form-input pl-8">
            </div>
            <select name="shop" class="form-input md:w-56" onchange="this.form.submit()">
                <option value="">Semua toko</option>
                @foreach ($shops as $option)
                    <option value="{{ $option->id }}" @selected($shop?->id === $option->id)>{{ $option->displayName() }}{{ $option->environment === 'test' ? ' (test)' : '' }}</option>
                @endforeach
            </select>
            <select name="status" class="form-input md:w-48" onchange="this.form.submit()">
                <option value="">Semua status</option>
                @foreach (\App\Http\Controllers\Shopee\ShopeeProductStatusController::STATUSES as $value => $label)
                    <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <div class="flex gap-2">
                <button type="submit" class="btn-secondary"><x-heroicon-o-funnel class="size-5" /> Filter</button>
                @if (request()->hasAny(['search', 'status', 'shop']))
                    <a href="{{ route('shopee.products.index', ['type' => $type]) }}" class="btn-icon" title="Reset filter"><x-heroicon-o-x-mark class="size-5" /></a>
                @endif
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                    <tr>
                        <th class="h-8 px-4 py-0 font-normal">SKU Optima</th>
                        @foreach ($shops as $column)
                            <th class="h-8 min-w-44 px-4 py-0 font-normal">
                                <a href="{{ route('shopee.mappings.index', $column) }}" class="hover:text-slate-700">{{ $column->displayName() }}</a>
                                @if ($column->environment === 'test')<span class="text-amber-600"> · test</span>@endif
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($rows as $row)
                        @php($byShop = $mappings[$row->id] ?? collect())
                        <tr class="align-top hover:bg-slate-50/60">
                            <td class="px-4 py-2.5">
                                <p class="font-mono text-[13px] text-slate-900">{{ $row->sku }}</p>
                                <p class="text-xs text-slate-500">{{ $type === 'bundle' ? $row->name : trim(($row->product?->name ?? '').' · '.$row->name, ' ·') }}</p>
                            </td>
                            @foreach ($shops as $column)
                                @php($cell = $byShop[$column->id] ?? collect())
                                @php($failed = $cell->whereNotNull('stock_error')->first())
                                @php($pushed = $cell->sortByDesc('stock_pushed_at')->first())
                                <td class="px-4 py-2.5">
                                    @if ($cell->isEmpty())
                                        <span class="inline-flex items-center gap-1 text-xs text-slate-400"><x-heroicon-o-minus-circle class="size-4" /> Belum terhubung</span>
                                    @elseif ($failed)
                                        <span class="inline-flex items-center gap-1 rounded-full bg-rose-50 px-2 py-0.5 text-xs text-rose-700"><x-heroicon-o-exclamation-triangle class="size-4" /> Gagal kirim stok</span>
                                        <p class="mt-1 max-w-56 text-xs text-rose-600">{{ $failed->stock_error }}</p>
                                    @else
                                        <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs text-emerald-700"><x-heroicon-o-check-circle class="size-4" /> Terhubung</span>
                                        <p class="mt-1 text-xs text-slate-500">
                                            @if ($pushed?->stock_pushed_at)
                                                Stok dikirim <span class="font-medium tabular-nums text-slate-700">{{ number_format($pushed->stock_pushed, 0, ',', '.') }}</span> · {{ $pushed->stock_pushed_at->diffForHumans() }}
                                            @else
                                                Stok belum pernah dikirim
                                            @endif
                                        </p>
                                    @endif
                                    @if ($cell->count() > 1)
                                        <p class="text-xs text-slate-400">{{ $cell->count() }} listing Shopee</p>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <x-empty-state :colspan="$shops->count() + 1" icon="heroicon-o-check-badge" title="Tidak ada SKU" description="Belum ada {{ $type === 'bundle' ? 'paket' : 'varian' }} aktif." />
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($rows->hasPages())
            <div class="border-t border-slate-100 px-5 py-4">{{ $rows->links() }}</div>
        @endif
    </div>

    <p class="mt-4 text-xs text-slate-500">
        SKU dihubungkan di Toko Shopee › Pemetaan SKU. Stok dikirim otomatis tiap 15 menit; SKU yang belum terhubung tidak ikut dikirim dan pesanannya tertahan sebagai draft.
    </p>
</x-layouts.app>
