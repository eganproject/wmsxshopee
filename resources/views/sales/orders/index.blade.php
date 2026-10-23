<x-layouts.app title="Pesanan">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span>Penjualan</span>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Pesanan</span>
    </x-slot:breadcrumb>

    <x-page-header title="Pesanan" description="Pesanan manual, Shopee dan TikTok. Konfirmasi untuk mencadangkan stok, lalu atur pengiriman, picking, kemas dan kirim." icon="heroicon-o-shopping-cart">
        <x-reports.export-button />
        @can('sales.create')
            <a href="{{ route('sales.orders.import') }}" class="btn-secondary">
                <x-heroicon-o-arrow-up-tray class="size-5" /> Impor Excel / CSV
            </a>
            <a href="{{ route('sales.orders.create') }}" class="btn-primary">
                <x-heroicon-o-plus class="size-5" /> Pesanan Baru
            </a>
        @endcan
    </x-page-header>

    <div class="mb-4 flex gap-1 overflow-x-auto border-b border-slate-200 text-sm" x-data="hintTips">
        @php($statusFilter = request('status'))
        <a href="{{ route('sales.orders.index', request()->except(['status', 'page'])) }}"
           data-hint-title="Semua pesanan" data-hint="Seluruh pesanan manual, Shopee dan TikTok sesuai filter yang sedang dipakai, dari Draft sampai Batal."
           @class(['-mb-px border-b-2 px-3 py-2 whitespace-nowrap', 'border-brand-500 font-medium text-slate-900' => ! $statusFilter, 'border-transparent text-slate-500 hover:text-slate-700' => $statusFilter])>
            Semua <span class="ml-1 text-xs text-slate-400">{{ $counts->sum() }}</span>
        </a>
        @foreach (\App\Models\SalesOrder::STATUSES as $value => $meta)
            <a href="{{ route('sales.orders.index', [...request()->except(['status', 'page']), 'status' => $value]) }}"
               data-hint-title="{{ $meta['label'] }}" data-hint="{{ $meta['hint'] }}" @if ($meta['next']) data-hint-next="{{ $meta['next'] }}" @endif
               @class(['-mb-px border-b-2 px-3 py-2 whitespace-nowrap', 'border-brand-500 font-medium text-slate-900' => $statusFilter === $value, 'border-transparent text-slate-500 hover:text-slate-700' => $statusFilter !== $value])>
                {{ $meta['label'] }} <span class="ml-1 text-xs text-slate-400">{{ $counts[$value] ?? 0 }}</span>
            </a>
        @endforeach
    </div>

    {{-- Status kedua: status pesanan di Shopee / TikTok, label disamakan. Jumlah ikut tab status gudang di atas. --}}
    @php($marketplaceFilter = request('mp'))
    @php($chip = 'inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-xs whitespace-nowrap transition')
    <div class="mb-4 flex flex-wrap items-center gap-1.5" aria-label="Filter status marketplace" x-data="hintTips">
        <span class="mr-1 flex items-center gap-1 text-[11px] font-medium tracking-wide text-slate-500 uppercase">
            <x-marketplace-logo platform="shopee" class="size-3.5" /><x-marketplace-logo platform="tiktok" class="size-3.5" /> Status marketplace
        </span>
        <a href="{{ route('sales.orders.index', request()->except(['mp', 'page'])) }}"
           data-hint-title="Semua status marketplace" data-hint="Pesanan Shopee dan TikTok pada tab yang sedang dibuka, apa pun statusnya di marketplace. Pesanan manual tidak dihitung."
           @class([$chip, 'border-slate-900 bg-slate-900 text-white dark:border-slate-200 dark:bg-slate-200 dark:text-slate-900' => ! $marketplaceFilter, 'border-slate-200 bg-white text-slate-600 hover:border-slate-300' => $marketplaceFilter])>
            Semua <span class="opacity-60">{{ $marketplaceCounts->sum() }}</span>
        </a>
        @foreach (\App\Models\SalesOrder::MARKETPLACE_STATUSES as $value => $meta)
            @continue(! ($marketplaceCounts[$value] ?? 0) && $marketplaceFilter !== $value)
            <a href="{{ route('sales.orders.index', [...request()->except(['mp', 'page']), 'mp' => $value]) }}"
               data-hint-title="{{ $meta['label'] }} di marketplace" data-hint="{{ $meta['hint'] }}"
               @class([$chip, 'border-slate-900 bg-slate-900 text-white dark:border-slate-200 dark:bg-slate-200 dark:text-slate-900' => $marketplaceFilter === $value, 'border-slate-200 bg-white text-slate-600 hover:border-slate-300' => $marketplaceFilter !== $value])>
                {{ $meta['label'] }} <span class="opacity-60">{{ $marketplaceCounts[$value] ?? 0 }}</span>
            </a>
        @endforeach
        @if (request()->filled('shopee'))
            {{-- Filter lama dari tautan notifikasi (?shopee=...). --}}
            <a href="{{ route('sales.orders.index', request()->except(['shopee', 'page'])) }}" class="{{ $chip }} border-sky-200 bg-sky-50 text-sky-700" title="Hapus filter">
                @php($legacy = ['courier_pending' => 'Belum discan kurir', 'courier_overdue' => 'Belum discan kurir > '.config('alerts.courier_scan_hours').' jam', 'shopee_ahead' => 'Dikirim di Shopee, belum di gudang'])
                Shopee: {{ $legacy[request('shopee')] ?? (\App\Models\SalesOrder::SHOPEE_STATUSES[request('shopee')]['label'] ?? request('shopee')) }}
                <x-heroicon-o-x-mark class="size-3.5" />
            </a>
        @endif
        <a href="{{ route('sales.orders.index', [...request()->except(['mp', 'page']), 'mp' => $marketplaceFilter === 'out_of_sync' ? null : 'out_of_sync']) }}"
           data-hint-title="Tidak sinkron" data-hint="Status gudang dan status marketplace bertentangan, misalnya pembeli minta batal tapi gudang masih memproses, atau sudah dikirim di marketplace tapi belum di gudang."
           data-hint-next="Buka pesanannya dan samakan statusnya, misalnya batalkan atau tandai dikirim."
           @class([$chip, 'ml-auto font-medium',
               'border-rose-600 bg-rose-600 text-white dark:border-rose-700 dark:bg-rose-700' => $marketplaceFilter === 'out_of_sync',
               'border-rose-200 bg-rose-50 text-rose-700 hover:border-rose-300' => $marketplaceFilter !== 'out_of_sync' && $outOfSyncCount,
               'border-slate-200 bg-white text-slate-400' => $marketplaceFilter !== 'out_of_sync' && ! $outOfSyncCount])>
            <x-heroicon-o-exclamation-triangle class="size-3.5" /> Tidak sinkron <span class="opacity-70">{{ $outOfSyncCount }}</span>
        </a>
    </div>

    @foreach (['shipment_failures' => 'belum bisa diatur pengirimannya', 'pick_failures' => 'belum bisa ditandai Sedang Diambil'] as $key => $title)
        @if ($failures = session($key))
            <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                <p class="flex items-center gap-1.5 font-medium"><x-heroicon-o-exclamation-triangle class="size-4" /> {{ count($failures) }} pesanan {{ $title }}</p>
                <ul class="mt-1 space-y-0.5 pl-6">
                    @foreach ($failures as $number => $reason)
                        <li class="list-disc"><span class="font-mono">{{ $number }}</span>: {{ $reason }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
    @endforeach

    <div class="card" x-data="{
            selected: [],
            rows: @js($bulkRows),
            count(key) { return this.selected.filter((id) => this.rows[id]?.[key]).length },
            ids(key) { return this.selected.filter((id) => this.rows[id]?.[key]) },
            get skipped() {
                const arranged = this.count('arranged'), manual = this.count('manual');
                const other = this.selected.length - this.count('arrange') - arranged - manual;
                return [
                    arranged ? arranged + ' sudah diatur pengirimannya' : null,
                    manual ? manual + ' pesanan manual (atur dari halaman pesanan)' : null,
                    other > 0 ? other + ' belum / tidak bisa diatur' : null,
                ].filter(Boolean).join(' · ');
            },
        }">
        {{-- Filter ala Ginee: platform & toko sekali klik, cari banyak nomor sekaligus, tanggal cepat dan urutan. --}}
        @php($pill = 'inline-flex max-w-56 items-center gap-1.5 rounded-md border px-2.5 py-1 text-xs whitespace-nowrap transition')
        @php($pillOn = 'border-brand-500 bg-brand-50 font-medium text-brand-700')
        @php($pillOff = 'border-slate-200 bg-white text-slate-600 hover:border-slate-300 hover:text-slate-900')
        @php($platform = request('platform'))
        @php($channel = request('channel'))
        <div class="space-y-2 border-b border-slate-100 px-4 pt-4 pb-3 text-sm">
            <div class="flex flex-col gap-1.5 sm:flex-row sm:items-start">
                <span class="w-20 shrink-0 pt-1 text-xs text-slate-500">Platform</span>
                <div class="flex flex-wrap gap-1.5">
                    @foreach (['' => 'Semua', 'shopee' => 'Shopee', 'tiktok' => 'TikTok', 'manual' => 'Manual'] as $value => $label)
                        <a href="{{ route('sales.orders.index', [...request()->except(['platform', 'channel', 'page']), 'platform' => $value ?: null]) }}"
                           @class([$pill, $pillOn => (string) $platform === $value, $pillOff => (string) $platform !== $value])>
                            @if (in_array($value, ['shopee', 'tiktok'], true))
                                <x-marketplace-logo :platform="$value" class="size-3.5" />
                            @endif
                            {{ $label }}
                        </a>
                    @endforeach
                </div>
            </div>
            @if ($shops->isNotEmpty())
                @php($shopLimit = 12)
                <div class="flex flex-col gap-1.5 sm:flex-row sm:items-start" x-data="{ more: false }">
                    <span class="w-20 shrink-0 pt-1 text-xs text-slate-500">Toko</span>
                    <div class="flex flex-wrap gap-1.5" data-testid="shop-chips">
                        <a href="{{ route('sales.orders.index', request()->except(['channel', 'page'])) }}"
                           @class([$pill, $pillOn => ! $channel, $pillOff => $channel])>Semua</a>
                        @foreach ($shops as $shop)
                            <a href="{{ route('sales.orders.index', [...request()->except(['channel', 'page']), 'channel' => $shop['name']]) }}"
                               title="{{ $shop['name'] }}"
                               @if ($loop->index >= $shopLimit && $channel !== $shop['name']) x-show="more" x-cloak @endif
                               @class([$pill, $pillOn => $channel === $shop['name'], $pillOff => $channel !== $shop['name']])>
                                <x-marketplace-logo :platform="$shop['platform']" class="size-3.5" />
                                <span class="truncate">{{ $shop['name'] }}</span>
                            </a>
                        @endforeach
                        @if ($shops->count() > $shopLimit)
                            <button type="button" class="px-1.5 py-1 text-xs font-medium text-brand-600 hover:underline" @click="more = ! more"
                                    x-text="more ? 'Lebih sedikit' : 'Lainnya ({{ $shops->count() - $shopLimit }})'"></button>
                        @endif
                    </div>
                </div>
            @endif
            {{-- Pencarian tersimpan milik user ini: kombinasi filter yang dipakai ulang sekali klik. --}}
            @php($currentQuery = collect(request()->query())->only(\App\Http\Controllers\Sales\OrderSavedFilterController::KEYS)->filter(fn ($v) => is_string($v) && $v !== '')->all())
            <div class="flex flex-col gap-1.5 sm:flex-row sm:items-start" x-data="{ saving: @js($errors->has('name')) }">
                <span class="w-20 shrink-0 pt-1 text-xs text-slate-500">Tersimpan</span>
                <div class="flex flex-1 flex-wrap items-center gap-1.5" data-testid="saved-filters">
                    @foreach ($savedFilters as $saved)
                        @php($active = $saved->query == $currentQuery)
                        <span @class([$pill, 'gap-0 p-0', $pillOn => $active, $pillOff => ! $active])>
                            <a href="{{ route('sales.orders.index', $saved->query) }}" class="flex items-center gap-1.5 py-1 pr-1 pl-2.5" title="{{ $saved->name }}">
                                <x-heroicon-o-bookmark class="size-3.5" /> <span class="truncate">{{ $saved->name }}</span>
                            </a>
                            <form method="POST" action="{{ route('sales.orders.saved-filters.destroy', $saved) }}"
                                  data-confirm-title="Hapus pencarian tersimpan?" data-confirm="Pencarian &quot;{{ $saved->name }}&quot; dihapus. Pesanannya tidak ikut terhapus." data-confirm-text="Hapus">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="rounded p-1 opacity-60 hover:opacity-100" title="Hapus {{ $saved->name }}" aria-label="Hapus {{ $saved->name }}"><x-heroicon-o-x-mark class="size-3" /></button>
                            </form>
                        </span>
                    @endforeach
                    @if ($currentQuery)
                        <button type="button" x-show="! saving" @click="saving = true; $nextTick(() => $refs.savedName.focus())"
                                class="inline-flex items-center gap-1 rounded-md border border-dashed border-slate-300 px-2.5 py-1 text-xs text-slate-600 hover:border-slate-400 hover:text-slate-900">
                            <x-heroicon-o-plus class="size-3.5" /> Simpan pencarian ini
                        </button>
                        <form method="POST" action="{{ route('sales.orders.saved-filters.store') }}" x-show="saving" x-cloak class="flex items-center gap-1.5" @keydown.escape="saving = false">
                            @csrf
                            <input type="hidden" name="query" value="{{ http_build_query($currentQuery) }}">
                            <input type="text" name="name" x-ref="savedName" maxlength="60" required value="{{ old('name') }}" placeholder="Nama, mis. Shopee lewat batas kirim" class="form-input h-8 w-64 text-xs">
                            <button type="submit" class="btn-primary h-8 px-3 text-xs">Simpan</button>
                            <button type="button" class="btn-icon" title="Batal" @click="saving = false"><x-heroicon-o-x-mark class="size-4" /></button>
                        </form>
                    @elseif ($savedFilters->isEmpty())
                        <span class="pt-1 text-xs text-slate-400">Pilih filter lalu simpan di sini supaya bisa dipakai ulang sekali klik.</span>
                    @endif
                    @error('name')
                        <p class="w-full text-xs text-rose-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </div>
        @php($from = request('from'))
        @php($to = request('to'))
        @php($today = now()->toDateString())
        @php($quickDates = ['Hari Ini' => [$today, $today], '7 Hari' => [now()->subDays(6)->toDateString(), $today], '30 Hari' => [now()->subDays(29)->toDateString(), $today]])
        <form method="GET" class="space-y-3 border-b border-slate-100 p-4"
              x-data="{ multi: @js($searchTerms > 1 && str_contains((string) request('search'), "\n")) }">
            @foreach (['status', 'mp', 'shopee', 'platform', 'channel', 'per_page'] as $keep)
                @if (request()->filled($keep))
                    <input type="hidden" name="{{ $keep }}" value="{{ request($keep) }}">
                @endif
            @endforeach
            <div class="flex flex-col gap-3 lg:flex-row lg:flex-wrap lg:items-start">
                <div class="flex flex-1 items-start lg:min-w-96">
                    <select name="search_by" class="form-input w-36 shrink-0 rounded-r-none" aria-label="Cari berdasarkan">
                        @foreach (\App\Http\Controllers\Sales\SalesOrderController::SEARCH_FIELDS as $value => $label)
                            <option value="{{ $value }}" @selected((string) request('search_by') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <div class="relative -ml-px flex-1">
                        <x-heroicon-o-magnifying-glass class="pointer-events-none absolute top-2.5 left-2.5 size-4 text-slate-400" x-show="! multi" />
                        <input type="search" name="search" value="{{ request('search') }}" x-ref="single" x-show="! multi" :disabled="multi"
                               placeholder="Cari no. SO, no. marketplace, pembeli, resi atau SKU..." class="form-input rounded-l-none pr-9 pl-8"
                               @paste="const text = $event.clipboardData.getData('text'); if (/[\r\n]/.test(text.trim())) { $event.preventDefault(); multi = true; $nextTick(() => { $refs.multi.value = text.trim(); $refs.multi.focus() }) }">
                        <textarea name="search" rows="4" x-ref="multi" x-show="multi" x-cloak :disabled="! multi"
                                  placeholder="Tempel banyak nomor pesanan / resi / SKU, satu per baris. Ctrl+Enter untuk cari."
                                  class="form-input rounded-l-none pr-9 font-mono text-xs" @keydown.ctrl.enter.prevent="$el.form.requestSubmit()"
                                  @keydown.meta.enter.prevent="$el.form.requestSubmit()">{{ request('search') }}</textarea>
                        <button type="button" class="absolute top-1.5 right-1.5 rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700"
                                :title="multi ? 'Kembali ke cari satu' : 'Cari banyak sekaligus (satu per baris)'"
                                @click="if (multi) { $refs.single.value = $refs.multi.value.split(/[\r\n]+/).filter(Boolean).join(', ') } else { $refs.multi.value = $refs.single.value.split(/\s*[,;]\s*/).filter(Boolean).join('\n') } multi = ! multi; $nextTick(() => (multi ? $refs.multi : $refs.single).focus())">
                            <x-heroicon-o-queue-list class="size-4" />
                        </button>
                    </div>
                </div>
                <select name="warehouse" class="form-input lg:w-40" onchange="this.form.submit()">
                    <option value="">Semua gudang</option>
                    @foreach ($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}" @selected(request('warehouse') == $warehouse->id)>{{ $warehouse->code }}</option>
                    @endforeach
                </select>
                <select name="shipment" class="form-input lg:w-44" onchange="this.form.submit()">
                    <option value="">Semua pengiriman</option>
                    <option value="unarranged" @selected(request('shipment') === 'unarranged')>Belum diatur</option>
                    @foreach (\App\Models\SalesOrder::SHIPPING_METHODS as $value => $meta)
                        <option value="{{ $value }}" @selected(request('shipment') === $value)>{{ $meta['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex flex-col gap-3 lg:flex-row lg:flex-wrap lg:items-center">
                <div class="flex flex-wrap items-center gap-2">
                    <select name="date_by" class="form-input w-40" aria-label="Jenis waktu">
                        @foreach (\App\Http\Controllers\Sales\SalesOrderController::DATE_FIELDS as $value => $label)
                            <option value="{{ $value }}" @selected((string) request('date_by') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <input type="date" name="from" value="{{ $from }}" x-ref="from" class="form-input w-40" title="Dari tanggal" aria-label="Dari tanggal">
                    <span class="text-sm text-slate-500">s/d</span>
                    <input type="date" name="to" value="{{ $to }}" x-ref="to" class="form-input w-40" title="Sampai tanggal" aria-label="Sampai tanggal">
                    @foreach ($quickDates as $label => [$start, $end])
                        <button type="button" @click="$refs.from.value = '{{ $start }}'; $refs.to.value = '{{ $end }}'; $el.form.requestSubmit()"
                                @class([$pill, 'py-1.5', $pillOn => $from === $start && $to === $end, $pillOff => ! ($from === $start && $to === $end)])>{{ $label }}</button>
                    @endforeach
                </div>
                <div class="flex items-center gap-2 lg:ml-auto">
                    <label for="order-sort" class="text-xs whitespace-nowrap text-slate-500">Urutkan</label>
                    <select id="order-sort" name="sort" class="form-input lg:w-48" onchange="this.form.submit()">
                        @foreach (\App\Http\Controllers\Sales\SalesOrderController::SORTS as $value => $label)
                            <option value="{{ $value }}" @selected((string) request('sort') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="btn-primary"><x-heroicon-o-funnel class="size-5" /> Cari</button>
                    @if (request()->hasAny(['search', 'search_by', 'warehouse', 'shipment', 'date_by', 'from', 'to', 'sort']))
                        <a href="{{ route('sales.orders.index', request()->only(['status', 'mp', 'shopee', 'platform', 'channel', 'per_page'])) }}" class="btn-icon" title="Reset filter"><x-heroicon-o-x-mark class="size-5" /></a>
                    @endif
                </div>
            </div>
            @if ($searchTerms > 1)
                <p class="flex items-center gap-1 text-xs text-slate-500">
                    <x-heroicon-o-information-circle class="size-4" /> Mencari {{ $searchTerms }} nomor sekaligus (cocok persis). Ditemukan {{ $orders->total() }} pesanan.
                </p>
            @endif
        </form>

        @can('sales.fulfill')
            {{-- Bar aksi massal mengambang di bawah layar selama ada pesanan yang dicentang. --}}
            <div x-show="selected.length" x-cloak
                 x-transition:enter="transition duration-150 ease-out" x-transition:enter-start="translate-y-4 opacity-0" x-transition:enter-end="translate-y-0 opacity-100"
                 x-transition:leave="transition duration-100 ease-in" x-transition:leave-start="opacity-100" x-transition:leave-end="translate-y-4 opacity-0"
                 @keydown.escape.window="selected = []"
                 class="pointer-events-none fixed inset-x-0 bottom-4 z-40 flex justify-center px-4 lg:left-60 lg:collapsed:left-14"
                 style="position: fixed; bottom: 1rem;"
                 role="region" aria-label="Aksi untuk pesanan terpilih">
                <div class="pointer-events-auto w-full max-w-5xl rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm shadow-xl shadow-slate-900/10">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                        <span class="flex items-center gap-2 text-slate-600">
                            <span class="flex h-6 min-w-6 items-center justify-center rounded-full bg-slate-900 px-1.5 text-xs font-semibold text-white" x-text="selected.length"></span>
                            pesanan dipilih
                        </span>
                        <span class="hidden h-5 w-px bg-slate-200 sm:block"></span>
                        <div class="flex flex-wrap items-center gap-2">
                            @foreach (\App\Models\SalesOrder::SHIPPING_METHODS as $value => $meta)
                                <form method="POST" action="{{ route('sales.orders.shipment.bulk') }}" x-show="count('arrange')"
                                      data-confirm-title="Atur {{ $meta['label'] }} di marketplace?" :data-confirm="count('arrange') + ' pesanan Shopee / TikTok diatur {{ $meta['label'] }} dengan alamat pickup toko dan jadwal paling awal.'" data-confirm-text="Atur {{ $meta['label'] }}">
                                    @csrf
                                    <input type="hidden" name="method" value="{{ $value }}">
                                    <template x-for="id in ids('arrange')" :key="id"><input type="hidden" name="orders[]" :value="id"></template>
                                    <button type="submit" class="btn-secondary"><x-dynamic-component :component="$meta['icon']" class="size-5" /> Atur {{ $meta['label'] }} <span class="text-slate-500" x-text="'(' + count('arrange') + ')'"></span></button>
                                </form>
                            @endforeach
                            <form method="GET" action="{{ route('sales.orders.shipping-labels') }}" target="_blank" data-no-loading x-show="count('label')">
                                <template x-for="id in ids('label')" :key="id"><input type="hidden" name="orders[]" :value="id"></template>
                                <button type="submit" class="btn-primary"><x-heroicon-o-printer class="size-5" /> Cetak Resi <span class="opacity-75" x-text="'(' + count('label') + ')'"></span></button>
                            </form>
                            <form method="POST" action="{{ route('sales.orders.pick.bulk') }}" x-show="count('pick')"
                                  data-confirm-title="Tandai Sedang Diambil?" :data-confirm="count('pick') + ' pesanan Dikonfirmasi ditandai Sedang Diambil dengan bin usulan otomatis (bin lokasi tetap dulu), sama seperti isian awal di halaman Picking. Pastikan barang sudah diambil sesuai picking list. Pesanan yang stok binnya kurang dilewati.'" data-confirm-text="Tandai Sedang Diambil">
                                @csrf
                                <template x-for="id in ids('pick')" :key="id"><input type="hidden" name="orders[]" :value="id"></template>
                                <button type="submit" class="btn-secondary"><x-heroicon-o-hand-raised class="size-5" /> Tandai Sedang Diambil <span class="text-slate-500" x-text="'(' + count('pick') + ')'"></span></button>
                            </form>
                            <p class="text-xs text-slate-500" x-show="! count('arrange') && ! count('label') && ! count('pick')">Tidak ada aksi untuk pesanan terpilih.</p>
                        </div>
                        <button type="button" class="btn-secondary ml-auto" title="Batal pilih (Esc)" @click="selected = []"><x-heroicon-o-x-mark class="size-5" /> Batal</button>
                    </div>
                    <p class="mt-2 flex items-center gap-1 border-t border-slate-100 pt-2 text-xs text-slate-500" x-show="skipped">
                        <x-heroicon-o-information-circle class="size-4 shrink-0" /> <span>Dilewati: <span x-text="skipped"></span></span>
                    </p>
                </div>
            </div>
        @endcan

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                    <tr>
                        @can('sales.fulfill')
                            <th class="h-8 w-8 py-0 pl-4">
                                <input type="checkbox" aria-label="Pilih semua" :checked="selected.length && selected.length === @js($orders->pluck('id'))->length"
                                       @change="selected = $event.target.checked ? @js($orders->pluck('id')) : []">
                            </th>
                        @endcan
                        <th class="h-8 px-3 py-0 font-normal">Pesanan</th>
                        <th class="h-8 px-3 py-0 font-normal">Saluran</th>
                        <th class="h-8 px-3 py-0 font-normal">Waktu</th>
                        <th class="h-8 px-3 py-0 font-normal">Barang Dipesan</th>
                        <th class="h-8 px-3 py-0 font-normal">Pengiriman</th>
                        <th class="h-8 px-3 py-0 font-normal">Status Gudang</th>
                        <th class="h-8 px-3 py-0 font-normal">Status Marketplace</th>
                        <th class="h-8 px-3 py-0 text-right font-normal">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($orders as $order)
                        @php($syncError = $order->shopee_sync_error ?? $order->tiktok_sync_error)
                        @php($unmapped = $order->hasStatus('draft') && $order->unmapped_skus !== null)
                        @php($deadline = $order->shipByDeadline())
                        @php($syncIssue = $order->syncIssue())
                        <tr @class(['align-top', 'bg-rose-50/40 hover:bg-rose-50/70' => $syncIssue, 'hover:bg-slate-50/60' => ! $syncIssue]) :class="selected.includes({{ $order->id }}) && 'bg-sky-50/60'">
                            @can('sales.fulfill')
                                <td class="py-2.5 pl-4"><input type="checkbox" value="{{ $order->id }}" x-model.number="selected" aria-label="Pilih {{ $order->number }}"></td>
                            @endcan
                            <td class="px-3 py-2.5 whitespace-nowrap">
                                <a href="{{ route('sales.orders.show', $order) }}" class="font-mono font-medium text-slate-900 hover:text-brand-600">{{ $order->number }}</a>
                            </td>
                            <td class="max-w-44 px-3 py-2.5">
                                {{-- Nama toko marketplace bisa sangat panjang tanpa spasi: potong supaya tidak menimpa kolom Pembeli. --}}
                                <p class="flex max-w-44 items-center gap-1.5 text-slate-700" title="{{ $order->channel_name ?? 'Manual' }}">
                                    <x-marketplace-logo :platform="$order->marketplacePlatform()" class="size-4" />
                                    <span class="truncate">{{ $order->channel_name ?? 'Manual' }}</span>
                                </p>
                                @if ($order->external_number)
                                    <p class="max-w-44 truncate font-mono text-xs text-slate-500" title="{{ $order->external_number }}">{{ $order->external_number }}</p>
                                @endif
                                @if ($syncError)
                                    <a href="{{ route('sales.orders.show', $order) }}{{ $unmapped ? '#sku-mapping' : '' }}"
                                       class="mt-1 inline-flex items-center gap-1 text-xs font-medium text-amber-700 hover:underline"
                                       title="{{ $syncError }} · Klik untuk menyelesaikan">
                                        <x-heroicon-o-exclamation-triangle class="size-3.5" />
                                        {{ $unmapped ? 'SKU belum terhubung · Selesaikan' : 'Perlu dicek' }}
                                    </a>
                                @endif
                            </td>
                            <td class="px-3 py-2.5 text-xs whitespace-nowrap">
                                {{-- Waktu pembuatan & waktu selesai proses gudang (serah terima). --}}
                                <p class="text-slate-500">Waktu Pembuatan</p>
                                <p class="text-slate-700">{{ $order->createdTime()->format('d/m/Y H:i') }}</p>
                                @if ($completed = $order->completedTime())
                                    <p class="mt-1 text-slate-500">Waktu Selesai</p>
                                    <p class="text-emerald-700">{{ $completed->format('d/m/Y H:i') }}</p>
                                @endif
                            </td>
                            <td class="px-3 py-2.5">
                                <x-sales.order-lines :order="$order" />
                            </td>
                            <td class="px-3 py-2.5">
                                <x-sales.shipment-status :order="$order" />
                                @if (! $order->isSelfPickup() && ($order->courier || $order->tracking_number))
                                    <p class="mt-1 text-xs text-slate-700">{{ $order->courier?->name }}{{ $order->courier && $order->courier_service ? ' · '.$order->courier_service : '' }}</p>
                                    @if ($order->tracking_number)
                                        <p class="font-mono text-xs text-slate-500">{{ $order->tracking_number }}</p>
                                    @endif
                                @endif
                                @if ($deadline)
                                    <p @class([
                                        'mt-1 flex items-center gap-1 text-[11px] whitespace-nowrap',
                                        'font-medium text-rose-600' => $deadline->isPast(),
                                        'text-amber-700' => ! $deadline->isPast() && $deadline->lte(now()->addDay()),
                                        'text-slate-500' => $deadline->gt(now()->addDay()),
                                    ]) title="Batas kirim dari marketplace">
                                        <x-heroicon-o-clock class="size-3.5" />
                                        {{ $deadline->isPast() ? 'Lewat batas kirim' : 'Kirim sebelum' }} {{ $deadline->format('d/m H:i') }}
                                    </p>
                                @endif
                            </td>
                            <td class="px-3 py-2.5">
                                <x-sales.status-badge :status="$order->status" />
                                <p class="mt-1 flex items-center gap-1 text-xs text-slate-500" title="{{ $order->warehouse->name }}">
                                    <x-heroicon-o-building-office-2 class="size-3.5" /> {{ $order->warehouse->code }}
                                </p>
                            </td>
                            <td class="px-3 py-2.5">
                                <x-sales.marketplace-status :order="$order" :issue="$syncIssue" class="max-w-52" />
                            </td>
                            <td class="px-3 py-2.5">
                                <div class="ml-auto flex w-[6.5rem] flex-wrap justify-end gap-0.5">
                                    @if ($order->isEditable())
                                        @can('sales.create')
                                            <a href="{{ route('sales.orders.edit', $order) }}" class="btn-icon" title="Edit">
                                                <x-heroicon-o-pencil-square class="size-5" />
                                            </a>
                                        @endcan
                                    @endif
                                    @can('sales.fulfill')
                                        @if ($order->canArrangeShipment() && ! ($order->isMarketplace() && $order->shipmentAlreadyArranged()))
                                            <a href="{{ route('sales.orders.shipment', $order) }}" class="btn-icon" title="{{ $order->isShipmentArranged() ? 'Ubah pengiriman' : 'Atur pengiriman' }}">
                                                <x-heroicon-o-truck class="size-5" />
                                            </a>
                                        @endif
                                    @endcan
                                    @if ($order->hasStatus(...\App\Models\SalesOrder::RESERVING))
                                        <a href="{{ route('sales.orders.pick-list', $order) }}" target="_blank" class="btn-icon" title="Cetak picking list">
                                            <x-heroicon-o-clipboard-document-list class="size-5" />
                                        </a>
                                    @endif
                                    @if ($order->isMarketplace())
                                        @can('sales.confirm')
                                            <form method="POST" action="{{ route('sales.orders.resync', $order) }}"
                                                  data-confirm-title="Sinkronkan {{ $order->number }}?" data-confirm-variant="primary" data-confirm-text="Sinkronkan"
                                                  data-confirm="{{ $order->hasStatus('draft') ? 'Data pesanan ditarik ulang dari marketplace dan SKU dicocokkan lagi. Bila semua SKU terhubung dan stok cukup, pesanan langsung dikonfirmasi.' : 'Status, resi dan data pesanan ditarik ulang dari marketplace.' }}">
                                                @csrf
                                                <input type="hidden" name="from_list" value="1">
                                                <button type="submit" class="btn-icon" title="Sinkronkan dari marketplace"><x-heroicon-o-arrow-path class="size-5" /></button>
                                            </form>
                                        @endcan
                                    @endif
                                    <button type="button" class="btn-icon" title="Riwayat operasi"
                                            @click="$dispatch('order-history', { url: @js(route('sales.orders.history', $order)), number: @js($order->number) })">
                                        <x-heroicon-o-clock class="size-5" />
                                    </button>
                                    <a href="{{ route('sales.orders.show', $order) }}" class="btn-icon" title="Lihat detail">
                                        <x-heroicon-o-eye class="size-5" />
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <x-empty-state :colspan="auth()->user()->can('sales.fulfill') ? 9 : 8" icon="heroicon-o-shopping-cart" title="Belum ada pesanan" description="Input pesanan manual atau impor dari file Excel / CSV.">
                            @can('sales.create')
                                <a href="{{ route('sales.orders.create') }}" class="btn-primary"><x-heroicon-o-plus class="size-5" /> Buat Pesanan</a>
                            @endcan
                        </x-empty-state>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Ruang agar baris terakhir tidak tertutup bar aksi mengambang. --}}
        <div x-show="selected.length" x-cloak class="h-24" aria-hidden="true"></div>

        @if ($orders->total() > \App\Http\Controllers\Sales\SalesOrderController::PER_PAGE[0])
            <div class="flex flex-col gap-3 border-t border-slate-100 px-5 py-4 lg:flex-row lg:items-center">
                <div class="flex items-center gap-2 text-sm text-slate-500">
                    <span>Total {{ number_format($orders->total(), 0, ',', '.') }}</span>
                    <select class="form-input w-32 py-1 text-xs" aria-label="Jumlah per halaman" onchange="window.location.href = this.value">
                        @foreach (\App\Http\Controllers\Sales\SalesOrderController::PER_PAGE as $size)
                            <option value="{{ route('sales.orders.index', [...request()->except(['per_page', 'page']), 'per_page' => $size === \App\Http\Controllers\Sales\SalesOrderController::PER_PAGE[0] ? null : $size]) }}" @selected($orders->perPage() === $size)>{{ $size }} / halaman</option>
                        @endforeach
                    </select>
                </div>
                @if ($orders->hasPages())
                    <div class="flex-1">{{ $orders->links() }}</div>
                @endif
            </div>
        @endif
    </div>

    {{-- Panel samping "Riwayat Operasi": log satu pesanan tanpa membuka halaman detail. --}}
    <div x-data="{ open: false, loading: false, number: '', html: '' }" x-cloak
         @order-history.window="open = true; loading = true; number = $event.detail.number; html = '';
             fetch($event.detail.url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                 .then((r) => r.ok ? r.text() : Promise.reject())
                 .then((t) => html = t)
                 .catch(() => html = '<p class=&quot;text-sm text-rose-600&quot;>Riwayat gagal dimuat. Coba lagi.</p>')
                 .finally(() => loading = false)"
         @keydown.escape.window="open = false">
        <div x-show="open" x-transition.opacity class="fixed inset-0 z-50 bg-slate-900/30" @click="open = false" aria-hidden="true"></div>
        <aside x-show="open" role="dialog" aria-modal="true" aria-label="Riwayat operasi"
               x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
               x-transition:leave="transition duration-150 ease-in" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
               class="fixed inset-y-0 right-0 z-50 flex w-full max-w-xl flex-col border-l border-slate-200 bg-white shadow-xl">
            <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3">
                <h2 class="flex items-center gap-2 text-sm font-medium text-slate-900">
                    <x-heroicon-o-clock class="size-4 text-slate-500" /> Riwayat Operasi <span class="font-mono text-slate-500" x-text="number"></span>
                </h2>
                <button type="button" class="btn-icon" title="Tutup (Esc)" @click="open = false"><x-heroicon-o-x-mark class="size-5" /></button>
            </div>
            <div class="flex-1 overflow-y-auto p-5">
                <p x-show="loading" class="flex items-center gap-2 text-sm text-slate-500"><x-heroicon-o-arrow-path class="size-4 animate-spin" /> Memuat riwayat...</p>
                <div x-show="! loading" x-html="html"></div>
            </div>
        </aside>
    </div>
</x-layouts.app>
