<x-layouts.app title="Toko TikTok">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span>Integrasi</span>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Toko TikTok</span>
    </x-slot:breadcrumb>

    <x-page-header title="Toko TikTok" description="Toko TikTok milik sendiri yang terhubung, masing-masing dikirim dari satu gudang." icon="heroicon-o-musical-note">
        @can('tiktok.settings')
            <a href="{{ route('tiktok.settings.edit') }}" class="btn-secondary"><x-heroicon-o-key class="size-5" /> Pengaturan</a>
        @endcan
    </x-page-header>

    @can('tiktok.connect')
        <div class="card mb-6">
            @if ($configured)
                <form method="POST" action="{{ route('tiktok.shops.connect') }}" class="flex flex-col gap-3 p-4 md:flex-row md:items-end">
                    @csrf
                    <x-select name="warehouse_id" label="Gudang pengirim" class="md:w-80" required>
                        <option value="">Pilih gudang</option>
                        @foreach ($warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}" @selected(old('warehouse_id') == $warehouse->id || $warehouses->count() === 1)>{{ $warehouse->code }} · {{ $warehouse->name }}</option>
                        @endforeach
                    </x-select>
                    <button type="submit" class="btn-primary"><x-heroicon-o-link class="size-5" /> Hubungkan Toko</button>
                    <p class="text-xs text-slate-500 md:ml-2 md:self-center">
                        Anda akan diarahkan ke TikTok untuk login dengan akun Seller Center lalu menyetujui akses Optima. Semua toko di akun itu ikut terhubung.
                    </p>
                </form>
            @else
                <div class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-sm text-slate-600">App Key & App Secret TikTok belum diisi.</p>
                    @can('tiktok.settings')
                        <a href="{{ route('tiktok.settings.edit') }}" class="btn-primary"><x-heroicon-o-key class="size-5" /> Isi Kredensial</a>
                    @endcan
                </div>
            @endif
        </div>
    @endcan

    <div class="card">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                    <tr>
                        <th class="h-8 px-4 py-0 font-normal">Toko</th>
                        <th class="h-8 px-4 py-0 font-normal">Gudang Pengirim</th>
                        <th class="h-8 px-4 py-0 font-normal">Status</th>
                        <th class="h-8 px-4 py-0 font-normal">Token & Sinkron</th>
                        <th class="h-8 px-4 py-0 font-normal">Pemetaan SKU</th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($shops as $shop)
                        <tr class="align-top hover:bg-slate-50/60">
                            <td class="px-4 py-2.5">
                                <p class="font-medium text-slate-900">{{ $shop->displayName() }}</p>
                                <p class="font-mono text-xs text-slate-500">
                                    {{ collect([$shop->shop_id, $shop->region, $shop->shop_code])->filter()->implode(' · ') }}
                                </p>
                            </td>
                            <td class="px-4 py-2.5">
                                @can('tiktok.connect')
                                    <form method="POST" action="{{ route('tiktok.shops.update', $shop) }}" class="flex items-center gap-2">
                                        @csrf @method('PUT')
                                        <input type="hidden" name="is_active" value="{{ $shop->is_active ? 1 : 0 }}">
                                        <select name="warehouse_id" class="form-input w-56" onchange="this.form.submit()" aria-label="Gudang pengirim">
                                            @unless ($shop->warehouse_id)
                                                <option value="">Pilih gudang</option>
                                            @endunless
                                            @foreach ($warehouses as $warehouse)
                                                <option value="{{ $warehouse->id }}" @selected($shop->warehouse_id === $warehouse->id)>{{ $warehouse->code }} · {{ $warehouse->name }}</option>
                                            @endforeach
                                        </select>
                                    </form>
                                @else
                                    {{ $shop->warehouse?->name ?? '—' }}
                                @endcan
                            </td>
                            <td class="px-4 py-2.5">
                                @php
                                    $tone = match ($shop->status) {
                                        'connected' => 'bg-emerald-50 text-emerald-700',
                                        'expired' => 'bg-amber-50 text-amber-700',
                                        default => 'bg-slate-100 text-slate-600',
                                    };
                                @endphp
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs {{ $tone }}">{{ \App\Models\TiktokShop::STATUSES[$shop->status] ?? $shop->status }}</span>
                                @unless ($shop->is_active)
                                    <span class="ml-1 inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">Nonaktif</span>
                                @endunless
                                @if ($shop->last_error)
                                    <p class="mt-1 max-w-xs text-xs text-rose-600">{{ $shop->last_error }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-xs whitespace-nowrap text-slate-500">
                                @if ($shop->access_token_expires_at)
                                    <p>Akses s.d. {{ $shop->access_token_expires_at->format('d/m H:i') }}</p>
                                @endif
                                @if ($shop->refresh_token_expires_at)
                                    <p>Refresh s.d. {{ $shop->refresh_token_expires_at->format('d/m/Y') }}</p>
                                @endif
                                @if ($shop->last_synced_at)
                                    <p class="mt-1">Sinkron {{ $shop->last_synced_at->diffForHumans() }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-2.5">
                                <a href="{{ route('tiktok.mappings.index', $shop) }}" class="font-medium text-slate-900 hover:underline">{{ $shop->mappings_count }} SKU</a>
                                @if ($shop->unmapped_count)
                                    <p class="text-xs text-amber-600">{{ $shop->unmapped_count }} belum dipetakan</p>
                                @endif
                            </td>
                            <td class="px-4 py-2.5">
                                <div class="flex items-center justify-end gap-1">
                                    <a href="{{ route('tiktok.mappings.index', $shop) }}" class="btn-icon" title="Pemetaan SKU"><x-heroicon-o-arrows-right-left class="size-5" /></a>
                                    @can('tiktok.sync')
                                        <form method="POST" action="{{ route('tiktok.shops.sync', $shop) }}" x-data="{ busy: false }" @submit="busy = true">
                                            @csrf
                                            <button type="submit" class="btn-icon" title="Sinkron pesanan & stok sekarang" :disabled="busy" @disabled(! $shop->isSyncable())>
                                                <x-heroicon-o-arrows-up-down class="size-5" />
                                            </button>
                                        </form>
                                    @endcan
                                    @can('tiktok.connect')
                                        <form method="POST" action="{{ route('tiktok.shops.refresh', $shop) }}">
                                            @csrf
                                            <button type="submit" class="btn-icon" title="Perbarui token & info toko"><x-heroicon-o-arrow-path class="size-5" /></button>
                                        </form>
                                        <form id="delete-shop-{{ $shop->id }}" method="POST" action="{{ route('tiktok.shops.destroy', $shop) }}">
                                            @csrf @method('DELETE')
                                        </form>
                                        <button type="button" class="btn-icon hover:bg-rose-50 hover:text-rose-600" title="Hapus koneksi"
                                                @click="$dispatch('confirm', {
                                                    form: 'delete-shop-{{ $shop->id }}',
                                                    title: 'Hapus koneksi toko?',
                                                    message: @js("Token dan {$shop->mappings_count} pemetaan SKU toko {$shop->displayName()} akan dihapus dari Optima. Untuk mencabut akses di sisi TikTok, lakukan dari TikTok Shop Seller Center."),
                                                    confirmText: 'Hapus',
                                                })">
                                            <x-heroicon-o-trash class="size-5" />
                                        </button>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <x-empty-state colspan="6" icon="heroicon-o-musical-note" title="Belum ada toko terhubung"
                                       description="Isi kredensial di Pengaturan TikTok, lalu hubungkan toko dengan tombol di atas." />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-layouts.app>
