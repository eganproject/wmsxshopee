<x-layouts.app :title="'Serah Terima '.$handover->number">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('sales.handovers.index') }}" class="hover:text-slate-700">Serah Terima</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">{{ $handover->number }}</span>
    </x-slot:breadcrumb>

    <x-page-header :title="'Serah Terima '.$handover->number"
                   :description="$handover->warehouse->code.' · '.($handover->courier?->name ?? 'Semua kurir').' · '.($handover->methodLabel() ?? 'Pickup & drop off')"
                   icon="heroicon-o-paper-airplane">
        <a href="{{ route('sales.handovers.manifest', $handover) }}" target="_blank" class="btn-secondary"><x-heroicon-o-printer class="size-5" /> Cetak Manifest</a>
        <a href="{{ route('sales.handovers.index') }}" class="btn-secondary"><x-heroicon-o-arrow-left class="size-5" /> Kembali</a>
    </x-page-header>

    @if ($handover->isOpen())
        <div class="mb-6 grid gap-6 lg:grid-cols-3">
            <form method="POST" action="{{ route('sales.handovers.scan', $handover) }}" class="card p-5 lg:col-span-2">
                @csrf
                <label for="code" class="form-label">Scan Resi</label>
                <div class="relative">
                    <x-heroicon-o-qr-code class="pointer-events-none absolute top-1/2 left-3 size-5 -translate-y-1/2 text-slate-400" />
                    <input id="code" name="code" autofocus autocomplete="off" required maxlength="100"
                           class="form-input h-12 pl-10 font-mono text-lg" placeholder="Scan resi paket yang diserahkan...">
                </div>
                @if (session('success') || session('error'))
                    <div @class(['mt-3 rounded-lg px-4 py-3 text-base font-medium', 'bg-emerald-50 text-emerald-700' => ! session('scan_failed'), 'bg-rose-50 text-rose-700' => session('scan_failed')])>
                        {{ session('scan_failed') ? session('error') : session('success') }}
                    </div>
                    <script>
                        (() => { try { const c = new (window.AudioContext || window.webkitAudioContext)(); const o = c.createOscillator(); o.frequency.value = @js(session('scan_failed') ? 220 : 880); o.connect(c.destination); o.start(); o.stop(c.currentTime + @js(session('scan_failed') ? 0.35 : 0.08)); } catch (e) {} })();
                    </script>
                @endif
                <p class="mt-3 text-sm text-slate-500">Terdaftar <span class="font-semibold text-slate-900">{{ $handover->orders->count() }}</span> paket · menunggu <span class="font-semibold text-slate-900">{{ $waiting->count() }}</span> paket lolos QC.</p>
            </form>

            <form method="POST" action="{{ route('sales.handovers.close', $handover) }}" class="card p-5"
                  data-confirm-title="Selesaikan serah terima?" data-confirm="Sesi scan ditutup dan manifest siap dicetak." data-confirm-text="Selesai">
                @csrf
                <div class="space-y-4">
                    <x-input name="receiver_name" label="Nama Kurir Penerima" maxlength="100" placeholder="cth. Andi (SPX)" />
                    <x-input name="notes" label="Catatan" maxlength="255" />
                    <button type="submit" class="btn-primary w-full justify-center"><x-heroicon-o-check class="size-5" /> Selesai Serah Terima</button>
                </div>
            </form>
        </div>
    @else
        <div class="card mb-6 grid gap-5 p-5 sm:grid-cols-4">
            <div><p class="text-xs text-slate-500">Diserahkan</p><p class="mt-1 text-sm font-medium text-slate-900">{{ $handover->closed_at?->format('d/m/Y H:i') }}</p></div>
            <div><p class="text-xs text-slate-500">Kurir Penerima</p><p class="mt-1 text-sm font-medium text-slate-900">{{ $handover->receiver_name ?? '—' }}</p></div>
            <div><p class="text-xs text-slate-500">Petugas</p><p class="mt-1 text-sm font-medium text-slate-900">{{ $handover->creator?->name ?? '—' }}</p></div>
            <div><p class="text-xs text-slate-500">Jumlah Paket</p><p class="mt-1 text-sm font-medium text-slate-900">{{ $handover->orders->count() }}</p></div>
        </div>
    @endif

    <div class="grid gap-6 {{ $handover->isOpen() ? 'lg:grid-cols-2' : '' }}">
        <div class="card">
            <div class="border-b border-slate-100 px-5 py-3"><h2 class="text-base font-semibold text-slate-900">Sudah Diserahkan</h2></div>
            <div class="divide-y divide-slate-100 text-sm">
                @forelse ($handover->orders->sortByDesc('shipped_at') as $order)
                    <a href="{{ route('sales.orders.show', $order) }}" class="flex items-center justify-between gap-3 px-5 py-2.5 hover:bg-slate-50/60">
                        <span>
                            <span class="font-mono font-medium text-slate-900">{{ $order->tracking_number ?? $order->number }}</span>
                            <span class="block text-xs text-slate-500">{{ $order->number }} · {{ $order->customer_name }} · {{ $order->courier?->name ?? '—' }}</span>
                        </span>
                        <span class="text-xs text-slate-500">{{ $order->shipped_at?->format('H:i') }}</span>
                    </a>
                @empty
                    <p class="px-5 py-4 text-slate-500">Belum ada paket yang di-scan.</p>
                @endforelse
            </div>
        </div>

        @if ($handover->isOpen())
            <div class="card">
                <div class="border-b border-slate-100 px-5 py-3"><h2 class="text-base font-semibold text-slate-900">Menunggu Diserahkan</h2></div>
                <div class="divide-y divide-slate-100 text-sm">
                    @forelse ($waiting as $order)
                        <div class="flex items-center justify-between gap-3 px-5 py-2.5">
                            <span>
                                <span class="font-mono font-medium text-slate-900">{{ $order->tracking_number ?? $order->number }}</span>
                                <span class="block text-xs text-slate-500">{{ $order->number }} · {{ $order->customer_name }} · {{ $order->courier?->name ?? '—' }}</span>
                            </span>
                            @if ($order->shippingMethodLabel())
                                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{{ $order->shippingMethodLabel() }}</span>
                            @endif
                        </div>
                    @empty
                        <p class="px-5 py-4 text-slate-500">Semua paket yang lolos QC sudah di-scan.</p>
                    @endforelse
                </div>
            </div>
        @endif
    </div>
</x-layouts.app>
