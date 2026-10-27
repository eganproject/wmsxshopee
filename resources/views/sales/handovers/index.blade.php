<x-layouts.app title="Serah Terima">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span>Penjualan</span>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Serah Terima</span>
    </x-slot:breadcrumb>

    <x-page-header title="Serah Terima" description="Scan resi atau no. pesanan tiap paket yang diserahkan ke kurir / cabang drop off. Paket langsung tercatat Dikirim dan masuk manifest harian kurirnya." icon="heroicon-o-paper-airplane" />

    <div class="mb-6 grid gap-6 lg:grid-cols-3">
        <div class="card lg:col-span-2">
            <form method="POST" action="{{ route('sales.handovers.scan') }}" class="p-5">
                @csrf
                <label for="code" class="form-label">Scan Resi / No. <span class="whitespace-nowrap">Pesanan <x-hint text="Scan resi setiap paket saat diserahkan ke kurir. Pesanan langsung berstatus Dikirim, stok berkurang, dan paket masuk manifest kurir hari ini." /></span></label>
                <div class="relative">
                    <x-heroicon-o-qr-code class="pointer-events-none absolute top-1/2 left-3 size-5 -translate-y-1/2 text-slate-400" />
                    <input id="code" name="code" data-auto-scan autofocus autocomplete="off" required maxlength="100"
                           class="form-input h-12 pl-10 font-mono text-lg" placeholder="Scan resi paket, terkirim otomatis...">
                </div>
                @if (session('scan_ok') || session('scan_error'))
                    <div @class(['mt-3 rounded-lg px-4 py-3 text-base font-medium', 'bg-emerald-50 text-emerald-700' => session('scan_ok'), 'bg-rose-50 text-rose-700' => session('scan_error')]) role="status">
                        {{ session('scan_ok') ?? session('scan_error') }}
                    </div>
                    <script>
                        (() => { try { const c = new (window.AudioContext || window.webkitAudioContext)(); const o = c.createOscillator(); o.frequency.value = @js(session('scan_error') ? 220 : 880); o.connect(c.destination); o.start(); o.stop(c.currentTime + @js(session('scan_error') ? 0.35 : 0.08)); } catch (e) {} })();
                    </script>
                @endif
                <p class="mt-3 text-sm text-slate-500">Hari ini <span class="font-semibold text-slate-900">{{ $todayCount }}</span> paket diserahkan.</p>
            </form>

            <div class="border-t border-slate-100">
                <div class="px-5 py-3"><h2 class="text-base font-semibold text-slate-900">Scan Terakhir</h2></div>
                <div class="divide-y divide-slate-100 border-t border-slate-100 text-sm">
                    @php($canViewOrder = auth()->user()->can('sales.view'))
                    @forelse ($recent as $order)
                        <{{ $canViewOrder ? 'a' : 'div' }} @if ($canViewOrder) href="{{ route('sales.orders.show', $order) }}" @endif @class(['flex items-center justify-between gap-3 px-5 py-2.5', 'hover:bg-slate-50/60' => $canViewOrder])>
                            <span>
                                <span class="font-mono font-medium text-slate-900">{{ $order->tracking_number ?? $order->number }}</span>
                                <span class="block text-xs text-slate-500">{{ $order->number }} · {{ $order->customer_name }} · {{ $order->courier?->name ?? '—' }}</span>
                            </span>
                            <span class="text-xs text-slate-500">{{ $order->shipped_at?->format('H:i') }}</span>
                        </{{ $canViewOrder ? 'a' : 'div' }}>
                    @empty
                        <p class="px-5 py-4 text-slate-500">Belum ada paket yang di-scan hari ini.</p>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="card self-start">
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
        <div class="border-b border-slate-100 px-5 py-3">
            <h2 class="text-base font-semibold text-slate-900">Manifest</h2>
            <p class="mt-0.5 text-sm text-slate-500">Dibuat otomatis per gudang & kurir tiap hari. Cetak untuk ditandatangani kurir.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                    <tr>
                        <th class="h-8 px-4 py-0 font-normal">No.</th>
                        <th class="h-8 px-4 py-0 font-normal">Tanggal</th>
                        <th class="h-8 px-4 py-0 font-normal">Gudang</th>
                        <th class="h-8 px-4 py-0 font-normal">Kurir</th>
                        <th class="h-8 px-4 py-0 text-right font-normal"><span class="whitespace-nowrap">Paket <x-hint text="Jumlah paket yang sudah di-scan ke manifest ini." /></span></th>
                        <th class="h-8 px-4 py-0 font-normal"><span class="whitespace-nowrap">Status <x-hint text="Hari ini: manifest masih terbuka dan bisa bertambah. Selesai: manifest hari sebelumnya yang sudah ditutup." /></span></th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($handovers as $handover)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-4 py-2.5 font-mono font-medium text-slate-900"><a href="{{ route('sales.handovers.show', $handover) }}" class="hover:text-brand-600">{{ $handover->number }}</a></td>
                            <td class="px-4 py-2.5 whitespace-nowrap text-slate-600">{{ $handover->created_at->format('d/m/Y') }}</td>
                            <td class="px-4 py-2.5 text-slate-600">{{ $handover->warehouse->code }}</td>
                            <td class="px-4 py-2.5 text-slate-700">{{ $handover->courier?->name ?? 'Tanpa kurir' }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-700">{{ $handover->orders_count }}</td>
                            <td class="px-4 py-2.5">
                                @if ($handover->isOpen())
                                    <span class="inline-flex items-center gap-1 rounded-full bg-sky-50 px-2 py-0.5 text-xs font-medium text-sky-700"><x-heroicon-o-qr-code class="size-3.5" /> Hari ini</span>
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
                        <x-empty-state colspan="7" icon="heroicon-o-paper-airplane" title="Belum ada manifest" description="Manifest dibuat otomatis saat paket pertama di-scan." />
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($handovers->hasPages())
            <div class="border-t border-slate-100 px-5 py-4">{{ $handovers->links() }}</div>
        @endif
    </div>
</x-layouts.app>
