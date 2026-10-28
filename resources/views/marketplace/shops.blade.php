@php
    use App\Models\ShopeeSetting;
    use App\Models\ShopeeShop;
    use App\Support\StockPushError;

    $connectable = $platforms->filter(fn ($meta) => auth()->user()->can($meta['connect']));
    $defaultConnect = old('platform', $platform && $connectable->has($platform) ? $platform : $connectable->keys()->first());
@endphp

<x-layouts.app title="Toko Marketplace">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span>Integrasi</span>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Toko Marketplace</span>
    </x-slot:breadcrumb>

    <x-page-header title="Toko Marketplace" description="Semua toko Shopee dan TikTok Shop milik sendiri yang terhubung, masing-masing dikirim dari satu gudang." icon="heroicon-o-building-storefront">
        <x-marketplace.sync-products-button />
        @can('marketplace.view')
            <a href="{{ route('marketplace.products', array_filter(['platform' => $platform])) }}" class="btn-secondary"><x-heroicon-o-check-badge class="size-5" /> Status Produk</a>
        @endcan
        @can('marketplace.settings')
            <a href="{{ route('marketplace.settings', array_filter(['tab' => $platform])) }}" class="btn-secondary"><x-heroicon-o-key class="size-5" /> Pengaturan</a>
        @endcan
    </x-page-header>

    @if ($connectable->isNotEmpty())
        <div class="card mb-6" x-data="{ platform: @js($defaultConnect), configured: @js($configured), actions: @js($connectable->keys()->mapWithKeys(fn ($p) => [$p => route($p.'.shops.connect')])) }">
            <div class="flex flex-col gap-4 p-4 lg:flex-row lg:items-end">
                <div>
                    <p class="form-label"><span class="whitespace-nowrap">Platform <x-hint text="Pilih Shopee atau TikTok Shop. Kredensial app-nya harus sudah diisi di Pengaturan → Marketplace." /></span></p>
                    <div class="inline-flex rounded-lg border border-slate-200 p-0.5">
                        @foreach ($connectable as $key => $meta)
                            <button type="button" @click="platform = @js($key)"
                                    class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm"
                                    :class="platform === @js($key) ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-50'">
                                @if ($key === 'shopee')
                                    <x-heroicon-o-shopping-bag class="size-4" />
                                @else
                                    <x-heroicon-o-musical-note class="size-4" />
                                @endif
                                {{ $meta['label'] }}
                            </button>
                        @endforeach
                    </div>
                </div>

                <form method="POST" :action="actions[platform]" class="flex flex-1 flex-col gap-3 md:flex-row md:items-end" x-show="configured[platform]">
                    @csrf
                    <input type="hidden" name="platform" :value="platform">
                    <x-select name="warehouse_id" label="Gudang pengirim" tip="Gudang yang memproses pesanan toko ini. Stok gudang ini (ditambah jatah gudang cadangan) yang dikirim ke toko." class="md:w-80" required>
                        <option value="">Pilih gudang</option>
                        @foreach ($warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}" @selected(old('warehouse_id') == $warehouse->id || $warehouses->count() === 1)>{{ $warehouse->code }} · {{ $warehouse->name }}</option>
                        @endforeach
                    </x-select>
                    <button type="submit" class="btn-primary"><x-heroicon-o-link class="size-5" /> Hubungkan Toko</button>
                    <p class="text-xs text-slate-500 md:ml-2 md:self-center">
                        <span x-show="platform === 'shopee'">
                            Mode <span class="font-medium text-slate-700">{{ ShopeeSetting::ENVIRONMENTS[$shopeeEnvironment] ?? $shopeeEnvironment }}</span>.
                            Anda diarahkan ke Shopee untuk login dengan akun toko lalu menyetujui akses Optima.
                        </span>
                        <span x-show="platform === 'tiktok'" x-cloak>
                            Anda diarahkan ke TikTok untuk login dengan akun Seller Center lalu menyetujui akses Optima. Semua toko di akun itu ikut terhubung.
                        </span>
                    </p>
                </form>

                <div class="flex flex-1 flex-col gap-3 sm:flex-row sm:items-center sm:justify-between" x-show="! configured[platform]" x-cloak>
                    <p class="text-sm text-slate-600">
                        <span x-show="platform === 'shopee'">Partner ID & Partner Key Shopee untuk mode {{ ShopeeSetting::ENVIRONMENTS[$shopeeEnvironment] ?? $shopeeEnvironment }} belum diisi.</span>
                        <span x-show="platform === 'tiktok'">App Key & App Secret TikTok belum diisi.</span>
                    </p>
                    @can('marketplace.settings')
                        <a :href="@js(route('marketplace.settings')) + '?tab=' + platform" class="btn-primary"><x-heroicon-o-key class="size-5" /> Isi Kredensial</a>
                    @endcan
                </div>
            </div>
        </div>
    @endif

    <div class="card">
        <div class="flex flex-col gap-3 border-b border-slate-100 p-4 lg:flex-row lg:items-center">
            <div class="flex gap-1 overflow-x-auto">
                @foreach (['' => 'Semua', ...$platforms->map->label->all()] as $key => $label)
                    @continue($key === '' && $platforms->count() < 2)
                    <a href="{{ route('marketplace.shops', array_filter([...request()->only(['search', 'status']), 'platform' => $key ?: null])) }}"
                       @class(['inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm whitespace-nowrap',
                               'bg-slate-100 font-medium text-slate-900' => ($platform ?? '') === $key,
                               'text-slate-500 hover:bg-slate-50 hover:text-slate-700' => ($platform ?? '') !== $key])>
                        {{ $label }}
                        <span class="rounded-full bg-white px-1.5 text-[11px] tabular-nums text-slate-500 ring-1 ring-slate-200">{{ $key === '' ? $counts->sum() : ($counts[$key] ?? 0) }}</span>
                    </a>
                @endforeach
            </div>
            <form method="GET" class="flex flex-1 flex-col gap-2 sm:flex-row lg:justify-end">
                @if ($platform)
                    <input type="hidden" name="platform" value="{{ $platform }}">
                @endif
                <div class="relative sm:w-64">
                    <x-heroicon-o-magnifying-glass class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-slate-400" />
                    <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari nama / ID toko..." class="form-input pl-8">
                </div>
                <select name="status" class="form-input sm:w-48" onchange="this.form.submit()" aria-label="Status toko">
                    <option value="">Semua status</option>
                    @foreach (\App\Http\Controllers\Marketplace\MarketplaceController::STATUSES as $value => $label)
                        <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}{{ $value === 'attention' && $attention ? " ({$attention})" : '' }}</option>
                    @endforeach
                </select>
                @if (request()->hasAny(['search', 'status']))
                    <a href="{{ route('marketplace.shops', array_filter(['platform' => $platform])) }}" class="btn-icon" title="Reset filter"><x-heroicon-o-x-mark class="size-5" /></a>
                @endif
            </form>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                    <tr>
                        <th class="h-8 px-4 py-0 font-normal">Toko</th>
                        <th class="h-8 px-4 py-0 font-normal">Gudang <span class="whitespace-nowrap">Pengirim <x-hint text="Gudang yang memproses pesanan toko. Centang Kirim stok supaya Optima memperbarui stok di marketplace." /></span></th>
                        <th class="h-8 px-4 py-0 font-normal"><span class="whitespace-nowrap">Status <x-hint text="Terhubung: sinkron berjalan. Perlu otorisasi ulang: izin toko kedaluwarsa, sambungkan lagi. Diputus: koneksi dihapus dari marketplace." /></span></th>
                        <th class="h-8 px-4 py-0 font-normal">Token & <span class="whitespace-nowrap">Sinkron <x-hint text="Akses: masa berlaku token harian (diperbarui otomatis). Refresh: batas akhir sebelum toko harus diotorisasi ulang. Sinkron: kapan data terakhir diambil." /></span></th>
                        <th class="h-8 px-4 py-0 font-normal">Pemetaan <span class="whitespace-nowrap">SKU <x-hint text="Jumlah SKU marketplace yang sudah dihubungkan ke SKU gudang. SKU yang belum dipetakan tidak dikirim stoknya dan pesanannya tertahan." /></span></th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($shops as ['platform' => $p, 'shop' => $shop])
                        @php
                            $can = fn (string $action) => auth()->user()->can("{$p}.{$action}");
                            $tone = match ($shop->status) {
                                'connected' => 'bg-emerald-50 text-emerald-700',
                                'expired' => 'bg-amber-50 text-amber-700',
                                default => 'bg-slate-100 text-slate-600',
                            };
                            $platformName = $p === 'shopee' ? 'Shopee' : 'TikTok';
                            $sellerCenter = $p === 'shopee' ? 'Shopee Seller Centre' : 'TikTok Shop Seller Center';
                        @endphp
                        <tr class="align-top hover:bg-slate-50/60">
                            <td class="px-4 py-2.5">
                                <div class="flex items-center gap-2">
                                    <x-marketplace.platform-badge :platform="$p" />
                                    <p class="font-medium text-slate-900">{{ $shop->displayName() }}</p>
                                </div>
                                <p class="mt-0.5 font-mono text-xs text-slate-500">
                                    @if ($p === 'shopee')
                                        {{ $shop->shop_id }}@if ($shop->region) · {{ $shop->region }}@endif
                                        · <span @class(['text-amber-600' => $shop->environment === 'test'])>{{ $shop->environment === 'test' ? 'Test' : 'Live' }}</span>
                                    @else
                                        {{ collect([$shop->shop_id, $shop->region, $shop->shop_code])->filter()->implode(' · ') }}
                                    @endif
                                </p>
                            </td>
                            <td class="px-4 py-2.5">
                                @if ($can('connect'))
                                    <form method="POST" action="{{ route($p.'.shops.update', $shop) }}" class="flex items-center gap-2">
                                        @csrf @method('PUT')
                                        <input type="hidden" name="is_active" value="{{ $shop->is_active ? 1 : 0 }}">
                                        <input type="hidden" name="stock_push" value="{{ $shop->stock_push ? 1 : 0 }}">
                                        <select name="warehouse_id" class="form-input w-56" onchange="this.form.submit()" aria-label="Gudang pengirim">
                                            @unless ($shop->warehouse_id)
                                                <option value="">Pilih gudang</option>
                                            @endunless
                                            @foreach ($warehouses as $warehouse)
                                                <option value="{{ $warehouse->id }}" @selected($shop->warehouse_id === $warehouse->id)>{{ $warehouse->code }} · {{ $warehouse->name }}</option>
                                            @endforeach
                                        </select>
                                    </form>
                                    @if ($shop->warehouse_id)
                                        <form method="POST" action="{{ route($p.'.shops.update', $shop) }}" class="mt-1.5">
                                            @csrf @method('PUT')
                                            <input type="hidden" name="is_active" value="{{ $shop->is_active ? 1 : 0 }}">
                                            <input type="hidden" name="warehouse_id" value="{{ $shop->warehouse_id }}">
                                            <label class="inline-flex cursor-pointer items-center gap-1.5 text-xs text-slate-600" data-hint-title="Kirim stok ke {{ $platformName }}" data-hint="Nyala: Optima memperbarui stok di {{ $platformName }} setiap ada perubahan. Mati: stok di {{ $platformName }} tidak diubah Optima." data-hint-next="Nyalakan setelah stok gudang sudah dicek (stock opname).">
                                                <input type="checkbox" name="stock_push" value="1" class="form-checkbox" @checked($shop->stock_push) onchange="this.form.submit()">
                                                Kirim stok ke {{ $platformName }}
                                            </label>
                                        </form>
                                    @endif
                                @else
                                    {{ $shop->warehouse?->name ?? '—' }}
                                    <p class="mt-1 text-xs text-slate-500">Kirim stok {{ $shop->stock_push ? 'aktif' : 'mati' }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-2.5">
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs {{ $tone }}">{{ ShopeeShop::STATUSES[$shop->status] ?? $shop->status }}</span>
                                @unless ($shop->is_active)
                                    <span class="ml-1 inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">Nonaktif</span>
                                @endunless
                                @if ($p === 'shopee' && $shop->authorization_expires_at)
                                    <p class="mt-1 text-xs {{ $shop->authorization_expires_at->lte(now()->addDays(14)) ? 'text-amber-600' : 'text-slate-400' }}">
                                        Otorisasi s.d. {{ $shop->authorization_expires_at->format('d/m/Y') }}
                                    </p>
                                @endif
                                @if ($shop->last_error)
                                    <p class="mt-1 max-w-xs text-xs text-rose-600">{{ $shop->last_error }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-xs whitespace-nowrap text-slate-500">
                                @if ($shop->access_token_expires_at)
                                    <p>Akses s.d. {{ $shop->access_token_expires_at->format('d/m H:i') }}</p>
                                @endif
                                @if ($shop->refresh_token_expires_at)
                                    <p @class(['text-amber-600' => $shop->refresh_token_expires_at->lte(now()->addDays(7))])>Refresh s.d. {{ $shop->refresh_token_expires_at->format('d/m/Y') }}</p>
                                @endif
                                @if ($shop->last_synced_at)
                                    <p class="mt-1">Sinkron {{ $shop->last_synced_at->diffForHumans() }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-2.5">
                                <a href="{{ route($p.'.mappings.index', $shop) }}" class="font-medium text-slate-900 hover:underline">{{ $shop->mappings_count }} SKU</a>
                                @if ($shop->unmapped_count)
                                    <p class="text-xs text-amber-600">{{ $shop->unmapped_count }} belum dipetakan</p>
                                @endif
                                @if ($shop->stock_error_count)
                                    @php($hint = StockPushError::summary($p, $shop->failedStockMappings))
                                    <a href="{{ route($p.'.mappings.index', [$shop, 'status' => 'failed']) }}"
                                       class="inline-flex items-center gap-1 text-xs text-rose-600 underline decoration-dotted underline-offset-2 hover:text-rose-700"
                                       data-hint-title="{{ $hint['title'] }}" data-hint="{{ $hint['body'] }}" data-hint-next="{{ $hint['next'] }}">
                                        {{ $shop->stock_error_count }} gagal kirim stok
                                        <x-heroicon-o-information-circle class="size-3.5 shrink-0" />
                                    </a>
                                @endif
                            </td>
                            <td class="px-4 py-2.5">
                                <div class="flex items-center justify-end gap-1">
                                    <a href="{{ route($p.'.mappings.index', $shop) }}" class="btn-icon" title="Pemetaan SKU"><x-heroicon-o-arrows-right-left class="size-5" /></a>
                                    @if ($can('sync'))
                                        <form method="POST" action="{{ route($p.'.shops.sync', $shop) }}" x-data="{ busy: false }" @submit="busy = true" @pageshow.window="busy = false">
                                            @csrf
                                            <button type="submit" class="btn-icon" title="{{ $p === 'shopee' ? 'Sinkron pesanan, resi, retur & stok sekarang' : 'Sinkron pesanan, retur & stok sekarang' }}" :disabled="busy" @disabled(! $shop->isSyncable())>
                                                <x-heroicon-o-arrows-up-down class="size-5" />
                                            </button>
                                        </form>
                                    @endif
                                    @if ($can('connect'))
                                        <form method="POST" action="{{ route($p.'.shops.refresh', $shop) }}">
                                            @csrf
                                            <button type="submit" class="btn-icon" title="Perbarui token & info toko"><x-heroicon-o-arrow-path class="size-5" /></button>
                                        </form>
                                        <form id="delete-{{ $p }}-shop-{{ $shop->id }}" method="POST" action="{{ route($p.'.shops.destroy', $shop) }}">
                                            @csrf @method('DELETE')
                                        </form>
                                        <button type="button" class="btn-icon hover:bg-rose-50 hover:text-rose-600" title="Hapus koneksi"
                                                @click="$dispatch('confirm', {
                                                    form: 'delete-{{ $p }}-shop-{{ $shop->id }}',
                                                    title: 'Hapus koneksi toko?',
                                                    message: @js("Token dan {$shop->mappings_count} pemetaan SKU toko {$platformName} {$shop->displayName()} akan dihapus dari Optima. Untuk mencabut akses di sisi {$platformName}, lakukan dari {$sellerCenter}."),
                                                    confirmText: 'Hapus',
                                                })">
                                            <x-heroicon-o-trash class="size-5" />
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        @if (request()->hasAny(['search', 'status']))
                            <x-empty-state colspan="6" icon="heroicon-o-magnifying-glass" title="Tidak ada toko yang cocok" description="Ubah kata kunci atau filter status." />
                        @else
                            <x-empty-state colspan="6" icon="heroicon-o-building-storefront" title="Belum ada toko terhubung"
                                           description="Isi kredensial di Pengaturan Marketplace, lalu pilih platform dan hubungkan toko dengan tombol di atas." />
                        @endif
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-layouts.app>
