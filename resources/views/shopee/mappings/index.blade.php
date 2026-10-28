@php
    use App\Support\StockPushError;
@endphp

<x-layouts.app title="Pemetaan SKU Shopee">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('marketplace.shops', ['platform' => 'shopee']) }}" class="hover:text-slate-700">Toko Marketplace</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">{{ $shop->displayName() }}</span>
    </x-slot:breadcrumb>

    <x-page-header title="Pemetaan SKU · {{ $shop->displayName() }}"
                   description="Setiap produk/variasi di Shopee dihubungkan ke varian gudang atau paket di Optima. Pesanan Shopee nanti memakai pemetaan ini untuk mengurangi stok."
                   icon="heroicon-o-arrows-right-left">
        <a href="{{ route('marketplace.shops', ['platform' => 'shopee']) }}" class="btn-secondary"><x-heroicon-o-arrow-left class="size-5" /> Kembali</a>
        @can('shopee.mapping')
            <form method="POST" action="{{ route('shopee.mappings.auto-match', $shop) }}">
                @csrf
                <button type="submit" class="btn-secondary"><x-heroicon-o-sparkles class="size-5" /> Cocokkan Otomatis</button>
            </form>
            <form method="POST" action="{{ route('shopee.mappings.pull', $shop) }}" x-data="{ busy: false }" @submit="busy = true">
                @csrf
                <button type="submit" class="btn-primary" :disabled="busy" @disabled(! $shop->isConnected())
                        title="{{ $shop->isConnected() ? 'Ambil daftar produk & variasi dari toko' : 'Toko perlu diotorisasi ulang' }}">
                    <x-heroicon-o-arrow-path class="size-5 animate-spin" x-show="busy" x-cloak />
                    <x-heroicon-o-cloud-arrow-down class="size-5" x-show="!busy" />
                    <span x-text="busy ? 'Menarik produk...' : 'Tarik dari Shopee'">Tarik dari Shopee</span>
                </button>
            </form>
        @endcan
    </x-page-header>

    <div class="mb-6 grid gap-3 sm:grid-cols-4">
        <div class="card px-4 py-3">
            <p class="text-xs text-slate-500">Total SKU <span class="whitespace-nowrap">Shopee <x-hint text="Semua SKU / variasi produk yang ditarik dari toko Shopee ini." /></span></p>
            <p class="text-xl font-semibold text-slate-900 tabular-nums">{{ $total }}</p>
        </div>
        <div class="card px-4 py-3">
            <p class="text-xs text-slate-500">Sudah <span class="whitespace-nowrap">dipetakan <x-hint text="SKU Shopee yang sudah terhubung ke varian atau paket di gudang. Stoknya dikirim dan pesanannya bisa diproses." /></span></p>
            <p class="text-xl font-semibold text-emerald-600 tabular-nums">{{ $total - $unmapped }}</p>
        </div>
        <div class="card px-4 py-3">
            <p class="text-xs text-slate-500">Belum <span class="whitespace-nowrap">dipetakan <x-hint text="SKU Shopee yang belum terhubung. Stoknya tidak dikirim dan pesanan berisi SKU ini tertahan di Draft." next="Pilih SKU Optima di kolom kanan, atau samakan SKU di Shopee lalu Sinkron Produk." /></span></p>
            <p class="text-xl font-semibold tabular-nums {{ $unmapped ? 'text-amber-600' : 'text-slate-900' }}">{{ $unmapped }}</p>
        </div>
        <a href="{{ route('shopee.mappings.index', [$shop, 'status' => 'failed']) }}" class="card px-4 py-3 hover:border-slate-300">
            <p class="text-xs text-slate-500">Gagal kirim <span class="whitespace-nowrap">stok <x-hint text="SKU yang stoknya ditolak Shopee saat terakhir dikirim. Arahkan kursor ke tanda merah di baris untuk alasannya." /></span></p>
            <p class="text-xl font-semibold tabular-nums {{ $failed ? 'text-rose-600' : 'text-slate-900' }}">{{ $failed }}</p>
        </a>
    </div>

    @if ($removed && request('status') !== 'removed')
        <div class="mb-6 flex items-start gap-2 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
            <x-heroicon-o-archive-box-x-mark class="mt-0.5 size-5 shrink-0 text-slate-400" />
            <p>
                {{ $removed }} SKU sudah tidak ada lagi di Shopee (variasi/SKU dihapus atau diganti di toko). Barisnya disembunyikan, tidak dikirim stoknya, dan tidak dihitung gagal.
                <a href="{{ route('shopee.mappings.index', [$shop, 'status' => 'removed']) }}" class="font-medium text-slate-900 underline underline-offset-2">Lihat</a>
            </p>
        </div>
    @endif

    @if ($bundleLike && request('status') !== 'bundle_like')
        <div class="mb-6 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            <x-heroicon-o-gift class="mt-0.5 size-5 shrink-0 text-amber-500" />
            <p>
                {{ $bundleLike }} SKU terlihat seperti paket (berawalan {{ implode(', ', \App\Support\BundleLikeSku::prefixes()) }}) tapi dipetakan ke produk biasa. Stok yang dikirim ke Shopee dan dicek saat pesanan masuk dihitung dari produk itu, bukan dari isi paket. Bila memang paket, petakan ke Produk Paket.
                <a href="{{ route('shopee.mappings.index', [$shop, 'status' => 'bundle_like']) }}" class="font-medium underline underline-offset-2">Lihat</a>
            </p>
        </div>
    @endif

    @can('shopee.mapping')
        <details class="card mb-6" @if ($errors->any()) open @endif>
            <summary class="flex cursor-pointer items-center gap-2 px-5 py-3 text-sm font-medium text-slate-900">
                <x-heroicon-o-plus class="size-4" /> Tambah baris manual
                <span class="font-normal text-slate-500">untuk mencoba sebelum produk bisa ditarik dari Shopee</span>
            </summary>
            <form method="POST" action="{{ route('shopee.mappings.store', $shop) }}" class="grid gap-x-6 gap-y-4 border-t border-slate-100 p-5 sm:grid-cols-3">
                @csrf
                <x-input name="item_id" type="number" tip="ID produk di Shopee, terlihat di URL produk Seller Centre." min="1" label="Item ID" required />
                <x-input name="model_id" type="number" tip="ID variasi di Shopee. Isi 0 untuk produk tanpa variasi." min="0" label="Model ID" hint="0 / kosong untuk produk tanpa variasi." />
                <x-input name="shopee_sku" label="SKU Shopee" hint="Dicocokkan otomatis ke SKU varian / paket." />
                <x-input name="item_name" label="Nama produk" />
                <x-input name="model_name" label="Nama variasi" />
                <div>
                    <label for="new-target" class="form-label">SKU Optima</label>
                    <select id="new-target" name="target" class="form-input" data-remote="{{ route('shopee.mappings.lookup') }}" data-placeholder="Cari SKU / nama varian / paket">
                        <option value="">Otomatis dari SKU Shopee</option>
                    </select>
                </div>
                <div class="flex justify-end sm:col-span-3">
                    <button type="submit" class="btn-primary"><x-heroicon-o-check class="size-5" /> Tambah</button>
                </div>
            </form>
        </details>
    @endcan

    <div class="card">
        <form method="GET" class="flex flex-col gap-3 border-b border-slate-100 p-4 md:flex-row md:items-center">
            <div class="relative flex-1">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-slate-400" />
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari SKU, nama produk, variasi atau item ID..." class="form-input pl-8">
            </div>
            <select name="status" class="form-input md:w-48" onchange="this.form.submit()">
                <option value="">Semua baris</option>
                <option value="unmapped" @selected(request('status') === 'unmapped')>Belum dipetakan</option>
                <option value="mapped" @selected(request('status') === 'mapped')>Sudah dipetakan</option>
                <option value="failed" @selected(request('status') === 'failed')>Gagal kirim stok</option>
                <option value="bundle_like" @selected(request('status') === 'bundle_like')>Mirip paket, dipetakan ke produk ({{ $bundleLike }})</option>
                <option value="removed" @selected(request('status') === 'removed')>Tidak ada di toko ({{ $removed }})</option>
            </select>
            <div class="flex gap-2">
                <button type="submit" class="btn-secondary"><x-heroicon-o-funnel class="size-5" /> Filter</button>
                @if (request()->hasAny(['search', 'status']))
                    <a href="{{ route('shopee.mappings.index', $shop) }}" class="btn-icon" title="Reset filter"><x-heroicon-o-x-mark class="size-5" /></a>
                @endif
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                    <tr>
                        <th class="h-8 px-4 py-0 font-normal">Produk <span class="whitespace-nowrap">Shopee <x-hint text="Nama produk dan variasi di Shopee, beserta Item ID / Model ID." /></span></th>
                        <th class="h-8 px-4 py-0 font-normal">SKU <span class="whitespace-nowrap">Shopee <x-hint text="SKU yang diisi penjual di Shopee. Bila sama dengan SKU di gudang, otomatis terhubung." /></span></th>
                        <th class="h-8 px-4 py-0 font-normal">SKU <span class="whitespace-nowrap">Optima <x-hint text="Varian atau paket di gudang yang dihubungkan ke SKU marketplace ini. Kosong = Belum dipetakan." /></span></th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Stok di <span class="whitespace-nowrap">Shopee <x-hint text="Stok terakhir yang dikirim Optima ke Shopee untuk SKU ini, dan waktu pengirimannya." /></span></th>
                        <th class="h-8 w-16 px-4 py-0"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($mappings as $mapping)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-4 py-2.5">
                                <p class="font-medium text-slate-900">{{ $mapping->item_name ?? 'Item '.$mapping->item_id }}</p>
                                <p class="text-xs text-slate-500">
                                    @if ($mapping->model_name){{ $mapping->model_name }} · @endif
                                    <span class="font-mono">{{ $mapping->item_id }}@if ($mapping->model_id)/{{ $mapping->model_id }}@endif</span>
                                </p>
                                @if ($mapping->isRemoved())
                                    <span class="mt-1 inline-flex cursor-help rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600"
                                          data-hint-title="SKU tidak ada lagi" data-hint="Tidak muncul lagi saat produk ditarik dari Shopee. Stoknya tidak dikirim; pemetaan dipakai lagi bila SKU ini muncul kembali.">
                                        Tidak ada di Shopee sejak {{ $mapping->removed_at->format('d/m/Y') }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 font-mono text-[13px] text-slate-700">
                                {{ $mapping->shopee_sku ?? '—' }}
                                @if ($mapping->looksLikeBundle())
                                    <span class="ml-1 inline-flex cursor-help rounded-full bg-amber-50 px-2 py-0.5 font-sans text-xs text-amber-700"
                                          data-hint-title="Mirip paket" data-hint="SKU ini terlihat seperti paket, tapi dipetakan ke produk biasa. Stoknya dihitung dari produk itu, bukan dari isi paket. Bila memang paket, pilih Produk Paket di kolom kanan.">Mirip paket</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5">
                                @can('shopee.mapping')
                                    <form method="POST" action="{{ route('shopee.mappings.update', [$shop, $mapping]) }}" class="flex items-center gap-2">
                                        @csrf @method('PUT')
                                        <select name="target" class="form-input w-72" onchange="this.form.submit()" aria-label="SKU Optima"
                                                data-remote="{{ route('shopee.mappings.lookup') }}" data-placeholder="Cari SKU / nama varian / paket">
                                            <option value="">Belum dipetakan</option>
                                            @if ($mapping->variant)
                                                <option value="{{ $mapping->targetKey() }}" selected>{{ $mapping->variant->displayName() }}</option>
                                            @elseif ($mapping->bundle)
                                                <option value="{{ $mapping->targetKey() }}" selected>Paket · {{ $mapping->bundle->displayName() }}</option>
                                            @endif
                                        </select>
                                        @if ($mapping->match_type === 'auto')
                                            <span class="cursor-help rounded-full bg-sky-50 px-2 py-0.5 text-xs text-sky-700" data-hint-title="Otomatis" data-hint="Dicocokkan otomatis karena SKU di Shopee sama dengan SKU varian / paket di gudang. Ikut berubah bila SKU di Shopee diganti.">Otomatis</span>
                                        @endif
                                    </form>
                                @else
                                    {{ $mapping->variant?->displayName() ?? ($mapping->bundle ? 'Paket · '.$mapping->bundle->displayName() : '—') }}
                                @endcan
                            </td>
                            <td class="px-4 py-2.5 text-right text-xs whitespace-nowrap">
                                @if ($mapping->stock_pushed !== null)
                                    <p class="text-sm font-medium text-slate-900 tabular-nums">{{ $mapping->stock_pushed }}</p>
                                    <p class="text-slate-400">{{ $mapping->stock_pushed_at?->diffForHumans() }}</p>
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                                @if ($mapping->stock_error)
                                    @php($why = StockPushError::explain('shopee', $mapping->stock_error))
                                    <div class="mt-1 ml-auto max-w-64 text-left whitespace-normal">
                                        <p class="font-medium text-rose-600">{{ $why['title'] }}</p>
                                        <p class="text-rose-600/90">{{ $mapping->stock_error }}</p>
                                        <p class="mt-1 text-slate-500">{{ $why['fix'] }}</p>
                                    </div>
                                @endif
                            </td>
                            <td class="px-4 py-2.5">
                                @can('shopee.mapping')
                                    <form id="delete-mapping-{{ $mapping->id }}" method="POST" action="{{ route('shopee.mappings.destroy', [$shop, $mapping]) }}">
                                        @csrf @method('DELETE')
                                    </form>
                                    <button type="button" class="btn-icon hover:bg-rose-50 hover:text-rose-600" title="Hapus baris"
                                            @click="$dispatch('confirm', {
                                                form: 'delete-mapping-{{ $mapping->id }}',
                                                title: 'Hapus baris pemetaan?',
                                                message: 'Baris ini akan muncul lagi saat produk ditarik ulang dari Shopee.',
                                                confirmText: 'Hapus',
                                            })">
                                        <x-heroicon-o-trash class="size-5" />
                                    </button>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <x-empty-state colspan="5" icon="heroicon-o-arrows-right-left" title="Belum ada SKU Shopee"
                                       description="Klik Tarik dari Shopee untuk mengambil produk toko, atau tambah baris manual." />
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($mappings->hasPages())
            <div class="border-t border-slate-100 px-5 py-4">{{ $mappings->links() }}</div>
        @endif
    </div>
</x-layouts.app>
