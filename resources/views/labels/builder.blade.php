@php
    $isProduct = $kind === 'products';
    $title = 'Cetak Label';
    $config = [
        'items' => $items,
        'sizes' => $sizes,
        'size' => $defaultSize,
        'printUrl' => route($isProduct ? 'labels.products.print' : 'labels.locations.print'),
        'lookupUrl' => route($isProduct ? 'labels.products.lookup' : 'labels.locations.lookup'),
        'options' => $isProduct
            ? ['symbol' => 'barcode', 'content' => 'barcode', 'show_name' => true, 'show_variant' => true, 'show_sku' => true]
            : ['symbol' => 'barcode', 'show_path' => true],
    ];
    $groups = collect($sizes)->groupBy('group', preserveKeys: true);
@endphp

<x-layouts.app :title="$title">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span>Inventori</span>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">{{ $title }}</span>
    </x-slot:breadcrumb>

    <x-page-header :title="$title" description="Label barcode untuk SKU produk, produk paket, dan lokasi bin. Pilih barang, atur ukuran kertas, lalu cetak." icon="heroicon-o-qr-code" />

    @php
        $tabs = array_filter([
            auth()->user()->can('products.view') ? ['label' => 'Label Produk', 'route' => 'labels.products', 'icon' => 'heroicon-o-cube'] : null,
            auth()->user()->can('warehouses.view') ? ['label' => 'Label Lokasi Bin', 'route' => 'labels.locations', 'icon' => 'heroicon-o-map-pin'] : null,
        ]);
    @endphp
    <nav class="mb-5 flex gap-5 overflow-x-auto border-b border-slate-200">
        @foreach ($tabs as $tab)
            @php($active = request()->routeIs($tab['route']))
            <a href="{{ route($tab['route']) }}"
               @class([
                   '-mb-px flex items-center gap-1.5 border-b-[1.5px] pt-1 pb-2.5 text-sm whitespace-nowrap transition',
                   'border-slate-900 text-slate-900' => $active,
                   'border-transparent text-slate-500 hover:text-slate-900' => ! $active,
               ])>
                <x-dynamic-component :component="$tab['icon']" class="size-4" /> {{ $tab['label'] }}
            </a>
        @endforeach
    </nav>

    @error('qty')
        <div class="mb-4 flex items-center gap-2 rounded-lg border border-rose-200 bg-rose-50 px-4 py-2.5 text-sm text-rose-700">
            <x-heroicon-o-exclamation-triangle class="size-5" /> {{ $message }}
        </div>
    @enderror

    <form method="POST" action="{{ $config['printUrl'] }}" target="_blank" x-data="labelBuilder(@js($config))" @submit="if (! total) $event.preventDefault()">
        @csrf
        <div class="grid gap-5 lg:grid-cols-3">
            {{-- Daftar label --}}
            <div class="card lg:col-span-2">
                <div class="flex flex-col gap-3 border-b border-slate-100 px-5 py-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="flex items-center gap-2 text-base font-semibold text-slate-900">
                            <x-heroicon-o-queue-list class="size-5 text-slate-400" /> Daftar Label
                        </h2>
                        <p class="mt-0.5 text-sm text-slate-500">
                            @if ($isProduct)
                                Pilih dari halaman Produk atau Produk Paket, atau cari di bawah. Isi barcode memakai barcode varian, atau SKU bila barcode kosong.
                            @else
                                Pilih dari halaman Lokasi Bin, cari kode lokasi, atau tambahkan semua lokasi dalam satu rak.
                            @endif
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2" x-show="items.length" x-cloak>
                        <div class="flex items-center gap-1">
                            <input type="number" min="1" max="999" x-model.number="bulkQty" class="form-input w-16 text-center" data-native aria-label="Jumlah per item">
                            <button type="button" class="btn-secondary" @click="applyBulkQty()">Set semua</button>
                        </div>
                        <button type="button" class="btn-icon hover:bg-rose-50 hover:text-rose-600" title="Kosongkan daftar" @click="items = []">
                            <x-heroicon-o-trash class="size-5" />
                        </button>
                    </div>
                </div>

                <div class="grid gap-3 border-b border-slate-100 p-4 {{ $isProduct ? '' : 'sm:grid-cols-2' }}">
                    <div>
                        <label class="form-label" for="label-search">{{ $isProduct ? 'Tambah varian atau paket' : 'Tambah lokasi' }}</label>
                        <select id="label-search" x-ref="search" class="form-input" data-native data-remote="{{ $config['lookupUrl'] }}"
                                data-placeholder="{{ $isProduct ? 'Cari SKU, nama produk, atau scan barcode...' : 'Cari kode lokasi...' }}"></select>
                    </div>
                    @unless ($isProduct)
                        <div>
                            <label class="form-label" for="label-rack">Tambah semua lokasi di rak</label>
                            <div class="flex gap-2">
                                <select id="label-rack" x-ref="rack" class="form-input">
                                    <option value="">Pilih rak</option>
                                    @foreach ($racks as $rack)
                                        <option value="{{ $rack['value'] }}">{{ $rack['text'] }}</option>
                                    @endforeach
                                </select>
                                <button type="button" class="btn-secondary" @click="addRack()" :disabled="loading">
                                    <x-heroicon-o-plus class="size-5" /> Tambah
                                </button>
                            </div>
                        </div>
                    @endunless
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                            <tr>
                                @if ($isProduct)
                                    <th class="h-8 px-4 py-0 font-normal">SKU</th>
                                    <th class="h-8 px-4 py-0 font-normal">Barang</th>
                                    <th class="h-8 px-4 py-0 font-normal">Isi Barcode</th>
                                @else
                                    <th class="h-8 px-4 py-0 font-normal">Kode Lokasi</th>
                                    <th class="h-8 px-4 py-0 font-normal">Gudang / Zona / Rak</th>
                                    <th class="h-8 px-4 py-0 font-normal">Keterangan</th>
                                @endif
                                <th class="h-8 w-24 px-4 py-0 text-center font-normal">Jumlah</th>
                                <th class="h-8 w-12 px-4 py-0"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <template x-for="(item, index) in items" :key="item.key">
                                <tr class="transition-colors" :class="item.key === flashKey ? 'bg-amber-50' : 'hover:bg-slate-50/60'">
                                    @if ($isProduct)
                                        <td class="px-4 py-2 font-mono font-medium whitespace-nowrap text-slate-900" x-text="item.sku"></td>
                                        <td class="px-4 py-2">
                                            <p class="text-slate-900">
                                                <span x-text="item.title"></span>
                                                <span x-show="item.type === 'Paket'" class="ml-1 rounded-full bg-brand-50 px-2 py-0.5 text-[11px] font-medium text-brand-700 ring-1 ring-brand-100">Paket</span>
                                                <span x-show="! item.active" class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500">Nonaktif</span>
                                            </p>
                                            <p class="text-xs text-slate-500" x-show="item.subtitle && item.type !== 'Paket'" x-text="item.subtitle"></p>
                                        </td>
                                        <td class="px-4 py-2 font-mono text-xs whitespace-nowrap">
                                            <span class="text-slate-700" x-text="options.content === 'sku' ? item.sku : item.code"></span>
                                            <span x-show="options.content !== 'sku' && ! item.barcode_value" class="ml-1 font-sans text-slate-400">(SKU)</span>
                                        </td>
                                    @else
                                        <td class="px-4 py-2 font-mono font-medium whitespace-nowrap text-slate-900">
                                            <span x-text="item.code"></span>
                                            <span x-show="! item.active" class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 font-sans text-[11px] font-normal text-slate-500">Nonaktif</span>
                                        </td>
                                        <td class="px-4 py-2 whitespace-nowrap text-slate-600" x-text="item.path"></td>
                                        <td class="px-4 py-2 whitespace-nowrap text-slate-500" x-text="item.detail"></td>
                                    @endif
                                    <td class="px-4 py-1.5">
                                        <input type="number" min="0" max="999" :name="`qty[${item.key}]`" x-model.number="item.qty"
                                               class="form-input w-20 text-center" data-native>
                                    </td>
                                    <td class="px-4 py-1.5 text-right">
                                        <button type="button" class="btn-icon hover:bg-rose-50 hover:text-rose-600" title="Hapus dari daftar" @click="items.splice(index, 1)">
                                            <x-heroicon-o-x-mark class="size-5" />
                                        </button>
                                    </td>
                                </tr>
                            </template>
                            <tr x-show="! items.length">
                                <td colspan="5" class="px-5 py-14 text-center">
                                    <x-heroicon-o-qr-code class="mx-auto size-12 text-slate-300" />
                                    <p class="mt-3 font-medium text-slate-700">Belum ada label</p>
                                    <p class="text-sm text-slate-500">
                                        @if ($isProduct)
                                            Centang produk di halaman <a href="{{ route('master.products.index') }}" class="text-brand-600 hover:underline">Produk</a>
                                            atau <a href="{{ route('master.bundles.index') }}" class="text-brand-600 hover:underline">Produk Paket</a>, atau cari di atas.
                                        @else
                                            Centang lokasi di halaman <a href="{{ route('master.locations.index') }}" class="text-brand-600 hover:underline">Lokasi Bin</a>, atau cari di atas.
                                        @endif
                                    </p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Pengaturan & pratinjau --}}
            <div class="space-y-5">
                <div class="card">
                    <div class="border-b border-slate-100 px-5 py-3">
                        <h2 class="flex items-center gap-2 text-base font-semibold text-slate-900"><x-heroicon-o-adjustments-horizontal class="size-5 text-slate-400" /> Pengaturan</h2>
                    </div>
                    <div class="space-y-4 p-5">
                        <div>
                            <label class="form-label" for="label-size">Ukuran kertas</label>
                            <select id="label-size" name="size" class="form-input" x-model="size">
                                @foreach ($groups as $group => $options)
                                    <optgroup label="{{ $group }}">
                                        @foreach ($options as $key => $option)
                                            <option value="{{ $key }}">{{ $option['label'] }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <p class="form-label">Jenis kode</p>
                            <div class="grid grid-cols-3 gap-1 rounded-lg bg-slate-100 p-0.5">
                                @foreach (['barcode' => 'Barcode', 'qr' => 'QR Code', 'both' => 'Keduanya'] as $value => $text)
                                    <label class="cursor-pointer rounded-md px-2 py-1 text-center text-sm transition"
                                           :class="options.symbol === @js($value) ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-900'">
                                        <input type="radio" name="symbol" value="{{ $value }}" x-model="options.symbol" class="sr-only">
                                        {{ $text }}
                                    </label>
                                @endforeach
                            </div>
                            <p class="mt-1 text-xs text-slate-500">Barcode memakai format Code128, terbaca semua scanner.</p>
                        </div>

                        @if ($isProduct)
                            <div>
                                <label class="form-label" for="label-content">Isi kode</label>
                                <select id="label-content" name="content" class="form-input" x-model="options.content">
                                    <option value="barcode">Barcode varian (SKU bila kosong)</option>
                                    <option value="sku">Selalu SKU</option>
                                </select>
                            </div>
                        @endif

                        <div class="space-y-2">
                            <p class="form-label mb-0">Tampilkan</p>
                            @foreach ($isProduct
                                ? ['show_name' => 'Nama produk', 'show_variant' => 'Nama varian', 'show_sku' => 'SKU']
                                : ['show_path' => 'Gudang, zona, rak, dan level'] as $name => $text)
                                <label class="flex cursor-pointer items-center gap-2 text-sm text-slate-700">
                                    <input type="hidden" name="{{ $name }}" value="0">
                                    <input type="checkbox" name="{{ $name }}" value="1" x-model="options.{{ $name }}" class="form-checkbox">
                                    {{ $text }}
                                </label>
                            @endforeach
                        </div>

                        <div x-show="sheet" x-cloak>
                            <label class="form-label" for="label-offset">Mulai dari stiker ke-</label>
                            <input id="label-offset" type="number" min="1" :max="perSheet" x-model.number="start" class="form-input w-24" data-native>
                            <input type="hidden" name="offset" :value="sheet ? Math.max(0, (start || 1) - 1) : 0">
                            <p class="mt-1 text-xs text-slate-500">Untuk memakai sisa stiker di lembar yang sudah terpakai sebagian.</p>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3">
                        <h2 class="flex items-center gap-2 text-base font-semibold text-slate-900"><x-heroicon-o-eye class="size-5 text-slate-400" /> Pratinjau</h2>
                        <span class="text-xs text-slate-500" x-text="sizes[size].width.toLocaleString('id-ID') + ' × ' + sizes[size].height.toLocaleString('id-ID') + ' mm'"></span>
                    </div>
                    <div class="p-4">
                        <div class="flex items-center justify-center rounded-lg bg-slate-100 p-2" :style="`aspect-ratio: ${previewRatio}`">
                            <template x-if="previewUrl">
                                <iframe :src="previewUrl" class="size-full" title="Pratinjau label"></iframe>
                            </template>
                            <p x-show="! previewUrl" class="text-center text-xs text-slate-400">Pratinjau muncul setelah ada label di daftar.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="sticky bottom-0 mt-6 flex items-center justify-between gap-3 border-t border-slate-200 bg-white/90 py-3 backdrop-blur">
            <p class="text-sm text-slate-600">
                <span class="font-semibold text-slate-900" x-text="total.toLocaleString('id-ID')"></span> label
                <span x-show="total" x-cloak>· <span x-text="pageCount.toLocaleString('id-ID')"></span> <span x-text="sheet ? 'lembar A4' : 'halaman'"></span></span>
            </p>
            <button type="submit" class="btn-primary" :disabled="! total">
                <x-heroicon-o-printer class="size-5" /> Cetak Label
            </button>
        </div>
    </form>
</x-layouts.app>
