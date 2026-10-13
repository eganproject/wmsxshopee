<x-layouts.app title="Serah Terima">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span>Penjualan</span>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Serah Terima</span>
    </x-slot:breadcrumb>

    <x-page-header title="Serah Terima" description="Scan resi tiap paket saat diserahkan ke kurir (pickup) atau dibawa ke cabang (drop off). Paket yang di-scan langsung tercatat Dikirim." icon="heroicon-o-paper-airplane" />

    <div class="mb-6 grid gap-6 lg:grid-cols-3">
        <form method="POST" action="{{ route('sales.handovers.store') }}" class="card lg:col-span-2">
            @csrf
            <div class="border-b border-slate-100 px-5 py-3">
                <h2 class="text-base font-semibold text-slate-900">Mulai Serah Terima</h2>
            </div>
            <div class="grid gap-x-6 gap-y-4 p-5 sm:grid-cols-3">
                <x-select name="warehouse_id" label="Gudang" required>
                    @foreach ($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}" @selected((string) old('warehouse_id') === (string) $warehouse->id)>{{ $warehouse->code }} · {{ $warehouse->name }}</option>
                    @endforeach
                </x-select>
                <x-select name="courier_id" label="Kurir" hint="Kosongkan bila semua kurir.">
                    <option value="">Semua kurir</option>
                    @foreach ($couriers as $courier)
                        <option value="{{ $courier->id }}" @selected((string) old('courier_id') === (string) $courier->id)>{{ $courier->name }}</option>
                    @endforeach
                </x-select>
                <x-select name="shipping_method" label="Cara Kirim">
                    <option value="">Pickup & drop off</option>
                    @foreach (\App\Models\SalesOrder::SHIPPING_METHODS as $value => $meta)
                        <option value="{{ $value }}" @selected(old('shipping_method') === $value)>{{ $meta['label'] }}</option>
                    @endforeach
                </x-select>
            </div>
            <div class="flex justify-end border-t border-slate-100 px-5 py-3">
                <button type="submit" class="btn-primary"><x-heroicon-o-qr-code class="size-5" /> Mulai Scan</button>
            </div>
        </form>

        <div class="card">
            <div class="border-b border-slate-100 px-5 py-3">
                <h2 class="text-base font-semibold text-slate-900">Siap Diserahkan</h2>
                <p class="mt-0.5 text-sm text-slate-500">Paket yang sudah lolos QC.</p>
            </div>
            <div class="divide-y divide-slate-100 text-sm">
                @forelse ($ready as $courier => $count)
                    <div class="flex justify-between px-5 py-2.5"><span class="text-slate-700">{{ $courier }}</span><span class="font-semibold tabular-nums text-slate-900">{{ $count }}</span></div>
                @empty
                    <p class="px-5 py-4 text-slate-500">Belum ada paket yang siap.</p>
                @endforelse
            </div>
        </div>
    </div>

    <div class="card">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                    <tr>
                        <th class="h-8 px-4 py-0 font-normal">No.</th>
                        <th class="h-8 px-4 py-0 font-normal">Tanggal</th>
                        <th class="h-8 px-4 py-0 font-normal">Gudang</th>
                        <th class="h-8 px-4 py-0 font-normal">Kurir</th>
                        <th class="h-8 px-4 py-0 font-normal">Cara Kirim</th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Paket</th>
                        <th class="h-8 px-4 py-0 font-normal">Status</th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($handovers as $handover)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-4 py-2.5 font-mono font-medium text-slate-900"><a href="{{ route('sales.handovers.show', $handover) }}" class="hover:text-brand-600">{{ $handover->number }}</a></td>
                            <td class="px-4 py-2.5 whitespace-nowrap text-slate-600">{{ $handover->created_at->format('d/m/Y H:i') }}</td>
                            <td class="px-4 py-2.5 text-slate-600">{{ $handover->warehouse->code }}</td>
                            <td class="px-4 py-2.5 text-slate-700">{{ $handover->courier?->name ?? 'Semua kurir' }}</td>
                            <td class="px-4 py-2.5 text-slate-600">{{ $handover->methodLabel() ?? 'Pickup & drop off' }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-700">{{ $handover->orders_count }}</td>
                            <td class="px-4 py-2.5">
                                @if ($handover->isOpen())
                                    <span class="inline-flex items-center gap-1 rounded-full bg-sky-50 px-2 py-0.5 text-xs font-medium text-sky-700"><x-heroicon-o-qr-code class="size-3.5" /> Sedang scan</span>
                                @else
                                    <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700"><x-heroicon-o-check-circle class="size-3.5" /> Selesai</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5">
                                <div class="flex justify-end gap-1">
                                    <a href="{{ route('sales.handovers.manifest', $handover) }}" target="_blank" class="btn-icon" title="Cetak manifest"><x-heroicon-o-printer class="size-5" /></a>
                                    <a href="{{ route('sales.handovers.show', $handover) }}" class="btn-icon" title="Lihat"><x-heroicon-o-eye class="size-5" /></a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <x-empty-state colspan="8" icon="heroicon-o-paper-airplane" title="Belum ada serah terima" description="Mulai serah terima saat kurir datang menjemput atau sebelum paket diantar ke cabang." />
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($handovers->hasPages())
            <div class="border-t border-slate-100 px-5 py-4">{{ $handovers->links() }}</div>
        @endif
    </div>
</x-layouts.app>
