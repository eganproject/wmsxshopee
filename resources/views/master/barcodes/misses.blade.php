<x-layouts.app title="Barcode Tidak Dikenali">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span>Master Data</span>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('master.barcodes.index') }}" class="hover:text-slate-700">Barcode</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Tidak Dikenali</span>
    </x-slot:breadcrumb>

    <x-page-header title="Master Produk" description="Kode yang di-scan tapi tidak dikenali sebagai SKU, barcode, paket, lokasi maupun resi. Jadikan barcode alias, atau tandai selesai setelah master datanya dibetulkan." icon="heroicon-o-cube">
        <x-reports.export-button />
    </x-page-header>

    <x-master.product-tabs />
    <x-master.barcode-tabs :open-misses="$openMisses" />

    <div class="card" x-data="{ miss: null, resolution: 'alias' }">
        <form method="GET" class="flex flex-col gap-3 border-b border-slate-100 p-4 md:flex-row md:items-center">
            <div class="relative flex-1">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-slate-400" />
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari kode..." class="form-input pl-8">
            </div>
            <select name="status" class="form-input md:w-44" onchange="this.form.submit()" aria-label="Status">
                <option value="open" @selected($status === 'open')>Belum selesai</option>
                <option value="resolved" @selected($status === 'resolved')>Sudah selesai</option>
                <option value="all" @selected($status === 'all')>Semua</option>
            </select>
            <select name="context" class="form-input md:w-48" onchange="this.form.submit()" aria-label="Tempat scan">
                <option value="">Semua tempat scan</option>
                @foreach (\App\Models\BarcodeScanMiss::CONTEXTS as $key => $label)
                    <option value="{{ $key }}" @selected(request('context') === $key)>{{ $label }}</option>
                @endforeach
            </select>
            <div class="flex gap-2">
                <button type="submit" class="btn-secondary"><x-heroicon-o-funnel class="size-5" /> Filter</button>
                @if (request()->hasAny(['search', 'context']) || $status !== 'open')
                    <a href="{{ route('master.barcodes.misses') }}" class="btn-icon" title="Reset filter"><x-heroicon-o-x-mark class="size-5" /></a>
                @endif
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                    <tr>
                        <th class="h-8 px-4 py-0 font-normal">Kode</th>
                        <th class="h-8 px-4 py-0 font-normal"><span class="whitespace-nowrap">Tempat Scan <x-hint text="Halaman terakhir tempat kode ini di-scan." /></span></th>
                        <th class="h-8 px-4 py-0 text-right font-normal"><span class="whitespace-nowrap">Di-scan <x-hint text="Berapa kali kode ini di-scan sejak pertama tercatat." /></span></th>
                        <th class="h-8 px-4 py-0 font-normal">Terakhir</th>
                        <th class="h-8 px-4 py-0 font-normal">Gudang / Pesanan</th>
                        <th class="h-8 px-4 py-0 font-normal">Status</th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($misses as $miss)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-4 py-2.5 font-mono font-medium text-slate-900">{{ $miss->code }}</td>
                            <td class="px-4 py-2.5 text-slate-700">{{ $miss->contextLabel() }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-700">{{ number_format($miss->scan_count, 0, ',', '.') }}×</td>
                            <td class="px-4 py-2.5 whitespace-nowrap text-slate-600">
                                {{ $miss->last_seen_at?->format('d/m/Y H:i') }}
                                @if ($miss->user)<p class="text-xs text-slate-500">{{ $miss->user->name }}</p>@endif
                            </td>
                            <td class="px-4 py-2.5 text-slate-600">
                                {{ $miss->warehouse?->code ?? '—' }}
                                @if ($miss->order)
                                    <p class="font-mono text-xs">
                                        @can('sales.view')
                                            <a href="{{ route('sales.orders.show', $miss->order) }}" class="text-brand-700 hover:underline">{{ $miss->order->number }}</a>
                                        @else
                                            {{ $miss->order->number }}
                                        @endcan
                                    </p>
                                @endif
                            </td>
                            <td class="cell-wrap px-4 py-2.5">
                                @if ($miss->resolved_at)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700"><x-heroicon-o-check class="size-3.5" /> {{ $miss->resolutionLabel() }}</span>
                                    <p class="mt-0.5 text-xs text-slate-500">{{ $miss->resolver?->name }} · {{ $miss->resolved_at->format('d/m/Y H:i') }}{{ $miss->resolution_note ? ' · '.$miss->resolution_note : '' }}</p>
                                @else
                                    <span class="inline-flex items-center gap-1 rounded-full bg-rose-50 px-2 py-0.5 text-xs font-medium text-rose-700">Belum selesai</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                @if (! $miss->resolved_at)
                                    @can('products.edit')
                                        <button type="button" class="btn-secondary" @click="miss = @js(['code' => $miss->code, 'url' => route('master.barcodes.misses.resolve', $miss)]); resolution = 'alias'">
                                            <x-heroicon-o-check-circle class="size-5" /> Selesaikan
                                        </button>
                                    @endcan
                                @endif
                            </td>
                        </tr>
                    @empty
                        <x-empty-state colspan="7" icon="heroicon-o-check-badge" title="Tidak ada barcode tidak dikenali" description="Semua kode yang di-scan sudah dikenali sistem." />
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($misses->hasPages())
            <div class="border-t border-slate-100 px-5 py-4">{{ $misses->links() }}</div>
        @endif

        @can('products.edit')
            <div x-show="miss" x-cloak x-transition.opacity class="fixed inset-0 z-[70] bg-black/40"></div>
            <div x-show="miss" x-cloak class="fixed inset-0 z-[80] flex items-center justify-center p-4" @keydown.escape.window="miss = null">
                <form method="POST" :action="miss?.url" @click.outside="miss = null" class="w-full max-w-lg rounded-xl border border-slate-200 bg-white p-5 shadow-xl">
                    @csrf
                    <h2 class="text-base font-semibold text-slate-900">Selesaikan <span class="font-mono" x-text="miss?.code"></span></h2>
                    <div class="mt-4 space-y-2 text-sm">
                        @foreach (\App\Models\BarcodeScanMiss::RESOLUTIONS as $key => $label)
                            <label class="flex items-center gap-2">
                                <input type="radio" name="resolution" value="{{ $key }}" x-model="resolution" class="accent-slate-900"> {{ $label }}
                            </label>
                        @endforeach
                    </div>
                    <div class="mt-4" x-show="resolution === 'alias'">
                        <label for="miss-variant" class="form-label">Varian pemilik barcode <span class="text-rose-500">*</span></label>
                        <select id="miss-variant" name="product_variant_id" class="form-input"
                                data-remote="{{ route('master.barcodes.lookup') }}" data-placeholder="Ketik SKU atau nama varian">
                            <option value="">Pilih varian</option>
                        </select>
                    </div>
                    <div class="mt-4">
                        <label for="miss-note" class="form-label">Catatan</label>
                        <input id="miss-note" name="resolution_note" maxlength="255" class="form-input" placeholder="cth. barcode baru dari supplier">
                    </div>
                    <div class="mt-5 flex justify-end gap-2">
                        <button type="button" class="btn-secondary" @click="miss = null">Batal</button>
                        <button type="submit" class="btn-primary"><x-heroicon-o-check class="size-5" /> Simpan</button>
                    </div>
                </form>
            </div>
        @endcan
    </div>
</x-layouts.app>
