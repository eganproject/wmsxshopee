@php($fmt = fn ($n) => number_format((int) $n, 0, ',', '.'))
<x-layouts.app title="Lokasi Tetap SKU">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span>Inventori</span>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Lokasi Tetap SKU</span>
    </x-slot:breadcrumb>

    <x-page-header title="Lokasi Tetap SKU" description="Bin langganan setiap SKU per gudang (disarankan saat transfer & penerimaan), serta stok minimum/maksimum untuk saran isi ulang dari gudang cadangan." icon="heroicon-o-map-pin" />

    <div class="card">
        <form method="GET" class="flex flex-col gap-3 border-b border-slate-100 p-4 lg:flex-row lg:items-center">
            <select name="warehouse" class="form-input lg:w-56" onchange="this.form.submit()">
                @foreach ($warehouses as $option)
                    <option value="{{ $option->id }}" @selected($warehouse?->id === $option->id)>{{ $option->code }} · {{ $option->name }}</option>
                @endforeach
            </select>
            <div class="relative flex-1">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute top-1/2 left-3.5 size-5 -translate-y-1/2 text-slate-400" />
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari SKU, barcode atau nama produk..." class="form-input pl-11">
            </div>
            <select name="filter" class="form-input lg:w-52" onchange="this.form.submit()">
                <option value="">Semua SKU</option>
                <option value="stocked" @selected(request('filter') === 'stocked')>Ada stok di gudang ini</option>
                <option value="unset" @selected(request('filter') === 'unset')>Belum punya lokasi tetap</option>
            </select>
            <button type="submit" class="btn-secondary"><x-heroicon-o-funnel class="size-5" /> Filter</button>
        </form>

        @if (! $warehouse)
            <x-empty-state icon="heroicon-o-building-office-2" title="Belum ada gudang aktif" description="Tambahkan gudang di Master Gudang dulu." />
        @else
            <form method="POST" action="{{ route('inventory.sku-settings.update') }}" data-dirty-guard>
                @csrf
                @method('PUT')
                <input type="hidden" name="warehouse_id" value="{{ $warehouse->id }}">
                <x-form-errors class="mx-4 mt-4" />

                @if ($warehouse->supply_warehouse_id)
                    <p class="mx-4 mt-4 rounded-lg bg-sky-50 px-3 py-2 text-sm text-sky-700">
                        {{ $warehouse->code }} memakai gudang cadangan. Isi Min/Maks agar sistem menyarankan transfer saat stok di sini menipis.
                    </p>
                @endif

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                            <tr>
                                <th class="px-4 py-2.5">SKU</th>
                                <th class="px-4 py-2.5 text-right">Stok di {{ $warehouse->code }}</th>
                                <th class="px-4 py-2.5">Ada di bin</th>
                                <th class="px-4 py-2.5">Lokasi Tetap</th>
                                <th class="px-4 py-2.5">Min</th>
                                <th class="px-4 py-2.5">Maks</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse ($variants as $variant)
                                @php($setting = $settings[$variant->id] ?? null)
                                @php($qty = (int) ($stock[$variant->id] ?? 0))
                                <tr class="align-top">
                                    <td class="px-4 py-2">
                                        <p class="font-mono text-[13px] text-slate-900">{{ $variant->sku }}</p>
                                        <p class="text-xs text-slate-500">{{ trim($variant->product?->name.' · '.$variant->name, ' ·') }}{{ $variant->pcs_per_carton ? ' · isi '.$variant->pcs_per_carton.'/koli' : '' }}</p>
                                    </td>
                                    <td class="px-4 py-2 text-right tabular-nums text-slate-700">
                                        {{ $fmt($qty) }}
                                        @if ($label = $variant->cartonLabel($qty))
                                            <p class="text-xs text-slate-500">{{ $label }}</p>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 font-mono text-xs text-slate-500">{{ ($bins[$variant->id] ?? collect())->join(', ') ?: '—' }}</td>
                                    <td class="px-4 py-2">
                                        <select name="settings[{{ $variant->id }}][default_location_id]" class="form-input min-w-44" data-placeholder="Belum diatur">
                                            <option value="">Belum diatur</option>
                                            @foreach ($locations as $location)
                                                <option value="{{ $location->id }}" @selected(old("settings.{$variant->id}.default_location_id", $setting?->default_location_id) == $location->id)>{{ $location->code }} · {{ $location->typeLabel() }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td class="px-4 py-2"><input type="number" min="0" name="settings[{{ $variant->id }}][min_qty]" value="{{ old("settings.{$variant->id}.min_qty", $setting?->min_qty) }}" class="form-input w-24"></td>
                                    <td class="px-4 py-2"><input type="number" min="0" name="settings[{{ $variant->id }}][max_qty]" value="{{ old("settings.{$variant->id}.max_qty", $setting?->max_qty) }}" class="form-input w-24"></td>
                                </tr>
                            @empty
                                <x-empty-state colspan="6" icon="heroicon-o-cube" title="Tidak ada SKU" description="Tambahkan produk di Master Produk." />
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($variants->isNotEmpty())
                    @can('inventory.create')
                        <div class="sticky bottom-0 flex items-center justify-between gap-3 border-t border-slate-200 bg-slate-50/90 px-4 py-3 backdrop-blur">
                            <p class="text-xs text-slate-500">Hanya SKU di halaman ini yang disimpan. Min/maks dalam pcs.</p>
                            <button type="submit" class="btn-primary"><x-heroicon-o-check class="size-5" /> Simpan</button>
                        </div>
                    @endcan
                @endif
            </form>

            @if ($variants->hasPages())
                <div class="border-t border-slate-100 px-5 py-4">{{ $variants->links() }}</div>
            @endif
        @endif
    </div>
</x-layouts.app>
