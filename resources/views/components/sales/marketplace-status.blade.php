@props(['order', 'issue' => null])

{{-- Status pesanan di marketplace (Shopee / TikTok) dengan label yang disamakan, kode aslinya, dan selisih dengan status gudang. --}}
@if ($platform = $order->marketplacePlatform())
    @php
        $colors = [
            'slate' => 'border-slate-200 text-slate-600',
            'sky' => 'border-sky-200 text-sky-700',
            'violet' => 'border-violet-200 text-violet-700',
            'amber' => 'border-amber-200 text-amber-700',
            'emerald' => 'border-emerald-200 text-emerald-700',
            'rose' => 'border-rose-200 text-rose-700',
        ];
        $key = $order->marketplaceStatus();
        $color = \App\Models\SalesOrder::MARKETPLACE_STATUSES[$key]['color'] ?? 'slate';
        $platformName = $platform === 'shopee' ? 'Shopee' : 'TikTok Shop';
        $raw = $order->marketplaceRawStatus();
        $syncedAt = $order->marketplaceSyncedAt();
    @endphp
    <div {{ $attributes->class('space-y-0.5') }}>
        @if ($raw)
            <span class="inline-flex cursor-help items-center gap-1.5 rounded-full border bg-white px-2 py-0.5 text-xs whitespace-nowrap {{ $colors[$color] }}"
                  data-hint-title="Status di {{ $platformName }}: {{ $platform === 'shopee' ? $order->shopeeStatusLabel() : $order->tiktokStatusLabel() }}"
                  data-hint="{{ \App\Models\SalesOrder::MARKETPLACE_STATUSES[$key]['hint'] ?? 'Status pesanan terakhir yang diambil dari '.$platformName.'.' }}{{ $order->tiktok_cancel_requested ? ' Pembeli mengajukan batal.' : '' }}">
                <x-marketplace-logo :platform="$platform" class="size-3.5" />
                {{ $order->marketplaceStatusLabel() }}
            </span>
            <p class="font-mono text-[10.5px] whitespace-nowrap text-slate-400" title="Kode status asli dari {{ $platformName }}{{ $syncedAt ? ' · disinkron '.$syncedAt->format('d/m/Y H:i') : '' }}">
                {{ $raw }}@if ($syncedAt)<span class="font-sans"> · {{ $syncedAt->diffForHumans(short: true) }}</span>@endif
            </p>
        @else
            <span class="inline-flex items-center gap-1.5 text-xs text-slate-400">
                <x-marketplace-logo :platform="$platform" class="size-3.5" /> Belum disinkron
            </span>
        @endif

        @if ($issue)
            <p class="flex items-center gap-1 text-[11px] font-medium text-rose-600">
                <x-heroicon-o-exclamation-triangle class="size-3.5 shrink-0" /> {{ $issue }}
            </p>
        @elseif ($order->status === 'shipped' && ($order->shopee_shipped_at ?? $order->tiktok_shipped_at))
            <p class="flex cursor-help items-center gap-1 text-[11px] whitespace-nowrap text-emerald-600" data-hint="Marketplace melaporkan paket sudah di tangan kurir.">
                <x-heroicon-o-check-circle class="size-3.5" /> Diterima kurir {{ ($order->shopee_shipped_at ?? $order->tiktok_shipped_at)->format('d/m H:i') }}
            </p>
        @elseif ($order->awaitingCourierScan() || $order->tiktokAwaitingCourierScan())
            <p class="flex cursor-help items-center gap-1 text-[11px] whitespace-nowrap text-amber-600" data-hint-title="Belum discan kurir" data-hint="Paket sudah diserahkan {{ $order->shipped_at?->diffForHumans() }}, tapi marketplace belum mencatat scan kurir." data-hint-next="Bila lebih dari sehari, cek ke kurir apakah paket sudah benar diterima.">
                <x-heroicon-o-clock class="size-3.5" /> Belum discan kurir
            </p>
        @endif
        @if ($order->shipped_automatically)
            <p class="text-[11px] whitespace-nowrap text-slate-500">Dikirim otomatis dari {{ $platform === 'shopee' ? 'Shopee' : 'TikTok' }}</p>
            @if ($order->needsQcSkipCheck())
                <p class="flex cursor-help items-center gap-1 text-[11px] font-medium whitespace-nowrap text-amber-600" data-hint-title="Terlewat QC" data-hint="Paket diambil kurir sebelum QC kemas / serah terima di gudang. Stok keluar dari bin usulan otomatis." data-hint-next="Buka pesanan, cek barang & bin, lalu tandai sudah dicek.">
                    <x-heroicon-o-shield-exclamation class="size-3.5" /> Terlewat QC, belum dicek
                </p>
            @elseif ($order->qc_skip_checked_at)
                <p class="text-[11px] whitespace-nowrap text-emerald-600">Terlewat QC · sudah dicek</p>
            @endif
        @endif
    </div>
@else
    <span class="text-slate-300">—</span>
@endif
