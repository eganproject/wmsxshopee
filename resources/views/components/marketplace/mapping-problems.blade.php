{{--
    Card "Masalah pemetaan" di halaman Pemetaan SKU (Shopee & TikTok).
    Isi $problems dari App\Support\SkuMappingProblems::all(): jenis masalah,
    jumlah, penjelasan, dan tombol penyelesaiannya (GET = link, POST = form).

    <x-marketplace.mapping-problems :problems="$problems" platform="shopee" />
--}}
@props(['problems', 'platform'])

@php
    use App\Support\SkuMappingProblems;

    $issues = SkuMappingProblems::issueCount($problems);
    $name = $platform === 'shopee' ? 'Shopee' : 'TikTok';
    $styles = [
        'danger' => ['dot' => 'bg-rose-500', 'icon' => 'text-rose-500', 'title' => 'text-rose-700', 'badge' => 'bg-rose-50 text-rose-700', 'row' => 'bg-rose-50/40', 'svg' => 'heroicon-o-exclamation-circle'],
        'warning' => ['dot' => 'bg-amber-500', 'icon' => 'text-amber-500', 'title' => 'text-amber-800', 'badge' => 'bg-amber-50 text-amber-700', 'row' => 'bg-amber-50/40', 'svg' => 'heroicon-o-exclamation-triangle'],
        'info' => ['dot' => 'bg-slate-400', 'icon' => 'text-slate-400', 'title' => 'text-slate-700', 'badge' => 'bg-slate-100 text-slate-600', 'row' => '', 'svg' => 'heroicon-o-information-circle'],
    ];
@endphp

<div {{ $attributes->class('card mb-6') }} data-testid="mapping-problems">
    <div class="flex items-center gap-2 border-b border-slate-100 px-5 py-3">
        @if ($issues)
            <x-heroicon-o-shield-exclamation class="size-5 text-rose-500" />
            <h2 class="text-sm font-semibold text-slate-900">Masalah pemetaan</h2>
            <span class="rounded-full bg-rose-50 px-2 py-0.5 text-xs font-medium text-rose-700 tabular-nums">{{ $issues }}</span>
            <span class="text-sm text-slate-500">Selesaikan supaya stok yang dikirim dan pesanan yang masuk memakai SKU gudang yang benar.</span>
        @else
            <x-heroicon-o-shield-check class="size-5 text-emerald-500" />
            <h2 class="text-sm font-semibold text-slate-900">Tidak ada masalah pemetaan</h2>
            <span class="text-sm text-slate-500">Semua SKU {{ $name }} terhubung ke SKU gudang yang benar dan stok terakhirnya diterima {{ $name }}.</span>
        @endif
    </div>

    @if ($problems)
        <ul class="divide-y divide-slate-100">
            @foreach ($problems as $problem)
                @php($style = $styles[$problem['level']])
                <li class="flex flex-col gap-3 px-5 py-3 md:flex-row md:items-start {{ $style['row'] }}" data-problem="{{ $problem['key'] }}">
                    <x-dynamic-component :component="$style['svg']" class="mt-0.5 size-5 shrink-0 {{ $style['icon'] }}" />
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium {{ $style['title'] }}">{{ $problem['title'] }}</p>
                        <p class="mt-0.5 text-sm text-slate-500">{{ $problem['body'] }}</p>
                    </div>
                    @if ($problem['actions'])
                        <div class="flex shrink-0 flex-wrap items-center gap-2 md:ml-4">
                            @foreach ($problem['actions'] as $action)
                                @if ($action['method'] === 'POST')
                                    <form method="POST" action="{{ $action['href'] }}">
                                        @csrf
                                        <button type="submit" class="{{ $action['primary'] ? 'btn-primary' : 'btn-secondary' }}">
                                            <x-dynamic-component :component="$action['icon']" class="size-4" /> {{ $action['label'] }}
                                        </button>
                                    </form>
                                @else
                                    <a href="{{ $action['href'] }}" class="{{ $action['primary'] ? 'btn-primary' : 'btn-secondary' }}">
                                        <x-dynamic-component :component="$action['icon']" class="size-4" /> {{ $action['label'] }}
                                    </a>
                                @endif
                            @endforeach
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
