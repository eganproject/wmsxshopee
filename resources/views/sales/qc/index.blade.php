<x-layouts.app title="QC Kemas">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span>Penjualan</span>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">QC Kemas</span>
    </x-slot:breadcrumb>

    <x-page-header title="QC Kemas" description="Scan resi atau nomor SO, lalu scan setiap barang. Paket ditutup otomatis bila semua barang cocok." icon="heroicon-o-viewfinder-circle" />

    <form method="POST" action="{{ route('sales.qc.lookup') }}" class="card mb-6 p-5">
        @csrf
        <label for="code" class="form-label">Scan Resi / No. SO / No. <span class="whitespace-nowrap">Pesanan <x-hint text="Scan resi di label, nomor SO Optima, atau nomor pesanan marketplace untuk membuka halaman QC pesanan tersebut." /></span></label>
        <div class="flex gap-2">
            <div class="relative flex-1">
                <x-heroicon-o-qr-code class="pointer-events-none absolute top-1/2 left-3 size-5 -translate-y-1/2 text-slate-400" />
                <input id="code" name="code" data-auto-scan autofocus autocomplete="off" required maxlength="100"
                       class="form-input h-12 pl-10 font-mono text-lg" placeholder="Arahkan scanner ke resi...">
            </div>
            <button type="submit" class="btn-primary h-12 px-5">Buka</button>
        </div>
    </form>

    <div class="card" x-data="{ open: true }" @unless ($filtered) x-init="try { open = localStorage.getItem('qc-queue') !== 'closed' } catch (e) {}" @endunless>
        <button type="button" class="flex w-full items-start justify-between gap-3 px-5 py-3 text-left" :aria-expanded="open"
                @click="open = ! open; try { localStorage.setItem('qc-queue', open ? 'open' : 'closed') } catch (e) {}; document.getElementById('code')?.focus()">
            <span>
                <span class="block text-base font-semibold text-slate-900">Antrean QC <span class="ml-1 text-sm font-normal text-slate-400">{{ number_format($queue->total(), 0, ',', '.') }}</span></span>
                <span class="mt-0.5 block text-sm text-slate-500">Pesanan yang picking-nya sudah selesai dan menunggu dicek.</span>
            </span>
            <x-heroicon-o-chevron-down class="mt-1 size-5 shrink-0 text-slate-400 transition-transform" ::class="open || '-rotate-90'" />
        </button>

        <div x-show="open" x-collapse>
            <form method="GET" class="flex flex-col gap-3 border-t border-slate-100 p-4 md:flex-row md:items-center">
                <div class="relative flex-1">
                    <x-heroicon-o-magnifying-glass class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-slate-400" />
                    <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari no. SO, no. pesanan, resi, pembeli..." class="form-input pl-8">
                </div>
                <select name="warehouse" class="form-input md:w-52" onchange="this.form.submit()" aria-label="Gudang">
                    @if ($warehouses->count() > 1)<option value="">Semua gudang</option>@endif
                    @foreach ($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}" @selected($warehouseId === $warehouse->id)>{{ $warehouse->code }} · {{ $warehouse->name }}</option>
                    @endforeach
                </select>
                <select name="courier" class="form-input md:w-52" onchange="this.form.submit()" aria-label="Kurir">
                    <option value="">Semua kurir</option>
                    @foreach ($couriers as $courier)
                        <option value="{{ $courier->id }}" @selected(request('courier') == $courier->id)>{{ $courier->name }}</option>
                    @endforeach
                    <option value="none" @selected(request('courier') === 'none')>Tanpa kurir</option>
                </select>
                <select name="shipment" class="form-input md:w-48" onchange="this.form.submit()" aria-label="Pengiriman">
                    <option value="">Semua pengiriman</option>
                    @foreach (\App\Models\SalesOrder::SHIPPING_METHODS as $key => $method)
                        <option value="{{ $key }}" @selected(request('shipment') === $key)>{{ $method['label'] }}</option>
                    @endforeach
                    <option value="none" @selected(request('shipment') === 'none')>Belum diatur</option>
                </select>
                <div class="flex gap-2">
                    <button type="submit" class="btn-secondary"><x-heroicon-o-funnel class="size-5" /> Filter</button>
                    @if ($filtered)
                        <a href="{{ route('sales.qc.index', ['warehouse' => $warehouseId ?: '']) }}" class="btn-icon" title="Reset filter"><x-heroicon-o-x-mark class="size-5" /></a>
                    @endif
                </div>
            </form>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                        <tr>
                            <th class="h-8 px-4 py-0 font-normal">No.</th>
                            <th class="h-8 px-4 py-0 font-normal">Pembeli</th>
                            @unless ($warehouseId)<th class="h-8 px-4 py-0 font-normal">Gudang</th>@endunless
                            <th class="h-8 px-4 py-0 font-normal">Kurir / Resi</th>
                            <th class="h-8 px-4 py-0 font-normal"><span class="whitespace-nowrap">Pengiriman <x-hint text="Cara serah paket ke kurir (pickup / drop off)." /></span></th>
                            <th class="h-8 px-4 py-0 font-normal"><span class="whitespace-nowrap">Diambil Sejak <x-hint text="Waktu pesanan ditandai Sedang Diambil. Kuning bila sudah lebih dari {{ $waitingHours / 2 }} jam, merah bila lebih dari {{ $waitingHours }} jam belum di-QC." /></span></th>
                            <th class="h-8 px-4 py-0 text-right font-normal"><span class="whitespace-nowrap">Qty <x-hint text="Total barang yang harus di-scan di paket ini." /></span></th>
                            <th class="h-8 px-4 py-0 text-right font-normal">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($queue as $order)
                            @php($minutes = $order->picking_at ? (int) $order->picking_at->diffInMinutes(now()) : null)
                            <tr class="hover:bg-slate-50/60">
                                <td class="px-4 py-2.5 font-mono font-medium text-slate-900">{{ $order->number }}
                                    @if ($order->external_number)<p class="text-xs font-normal text-slate-500">{{ $order->external_number }}</p>@endif
                                </td>
                                <td class="cell-wrap px-4 py-2.5 text-slate-700">{{ $order->customer_name }}</td>
                                @unless ($warehouseId)<td class="px-4 py-2.5 whitespace-nowrap text-slate-600">{{ $order->warehouse?->code ?? '—' }}</td>@endunless
                                <td class="px-4 py-2.5">
                                    <p class="text-slate-700">{{ $order->courier?->name ?? '—' }}</p>
                                    <p class="font-mono text-xs text-slate-500">{{ $order->tracking_number ?? 'Belum ada resi' }}</p>
                                </td>
                                <td class="px-4 py-2.5 text-slate-600">{{ $order->shippingMethodLabel() ?? ($order->shopeeShipmentArranged() ? 'Diatur di Shopee' : '—') }}</td>
                                <td class="px-4 py-2.5 whitespace-nowrap">
                                    @if ($minutes === null)
                                        <span class="text-slate-400">—</span>
                                    @else
                                        <p data-age="{{ $minutes >= $waitingHours * 60 ? 'late' : ($minutes >= $waitingHours * 30 ? 'warn' : 'ok') }}" @class([
                                            'font-medium',
                                            'text-rose-600' => $minutes >= $waitingHours * 60,
                                            'text-amber-700' => $minutes < $waitingHours * 60 && $minutes >= $waitingHours * 30,
                                            'text-slate-700' => $minutes < $waitingHours * 30,
                                        ])>{{ $order->picking_at->diffForHumans(short: true) }}</p>
                                        <p class="text-xs text-slate-500">{{ $order->picking_at->format('d/m H:i') }}</p>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-slate-700">{{ number_format((int) $order->items_sum_qty, 0, ',', '.') }}</td>
                                <td class="px-4 py-2.5 text-right">
                                    <a href="{{ route('sales.qc.show', $order) }}" class="btn-secondary"><x-heroicon-o-viewfinder-circle class="size-5" /> QC</a>
                                </td>
                            </tr>
                        @empty
                            @if ($filtered)
                                <x-empty-state :colspan="$warehouseId ? 7 : 8" icon="heroicon-o-funnel" title="Tidak ada pesanan yang cocok" description="Coba ubah atau reset filter." />
                            @else
                                <x-empty-state :colspan="$warehouseId ? 7 : 8" icon="heroicon-o-viewfinder-circle" title="Tidak ada antrean QC" description="Pesanan muncul di sini setelah picking selesai." />
                            @endif
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($queue->hasPages())
                <div class="border-t border-slate-100 px-5 py-4">{{ $queue->links() }}</div>
            @endif
        </div>
    </div>
</x-layouts.app>
