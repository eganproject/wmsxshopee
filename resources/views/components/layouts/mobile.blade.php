@props([
    'title' => 'Optima Gudang',
    'sub' => null,
    'back' => null,
    'nav' => true,
    'active' => null,
    'header' => true,
    'withBar' => false,
])
@php
    $currentWarehouse = \App\Support\MobileApp::warehouse();
    $hasBar = $withBar || (isset($bar) && trim((string) $bar) !== '');
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="warehouse-id" content="{{ $currentWarehouse?->id }}">
    <meta name="theme-color" content="#f5f5f5" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#121212" media="(prefers-color-scheme: dark)">
    <title>{{ $title }} · Optima Gudang</title>
    <x-theme-script />
    @vite(['resources/css/mobile.css', 'resources/js/mobile.js'])
</head>
<body x-data="mobileShell" @class(['m-page', 'has-nav' => $nav, 'has-bar' => $hasBar])>
    <div class="toasts" aria-live="polite">
        <template x-for="t in $store.ui.toasts" :key="t.id">
            <div class="toast" :class="t.kind" x-text="t.message"></div>
        </template>
    </div>

    <a href="{{ route('mobile.outbox') }}" class="offline-strip" x-cloak x-show="!$store.net.online || $store.net.queued > 0">
        <x-m-icon name="wifioff" size="s" x-show="!$store.net.online" />
        <x-m-icon name="cloud" size="s" x-show="$store.net.online" />
        <span class="grow" x-text="$store.net.online ? `${$store.net.queued} data menunggu dikirim` : 'Offline. Scan tetap disimpan di HP.'"></span>
        <b x-show="$store.net.queued > 0" x-text="`${$store.net.queued} antre`"></b>
    </a>

    @if ($header)
        <header class="top">
            @if ($back)
                <a href="{{ $back }}" class="iconbtn ghost" aria-label="Kembali"><x-m-icon name="left" /></a>
            @endif
            <div class="grow">
                <h1 class="truncate">{{ $title }}</h1>
                @if ($sub)
                    <div class="sub truncate">{{ $sub }}</div>
                @endif
            </div>
            {{ $actions ?? '' }}
        </header>
    @endif

    {{ $slot }}

    @isset($bar)
        <div class="bar">{{ $bar }}</div>
    @endisset

    @if ($nav)
        <nav class="nav">
            <a href="{{ route('mobile.home') }}" @class(['on' => $active === 'home'])><x-m-icon name="home" />Beranda</a>
            <a href="{{ route('mobile.outbox') }}" @class(['on' => $active === 'outbox'])>
                <x-m-icon name="tasks" />Antrean
                <span class="badge" x-cloak x-show="$store.net.queued > 0" x-text="$store.net.queued"></span>
            </a>
            <button type="button" class="fab" @click="scan()" aria-label="Scan"><span><x-m-icon name="scan" size="l" /></span></button>
            <a href="{{ route('mobile.print') }}" @class(['on' => $active === 'print'])><x-m-icon name="printer" />Cetak</a>
            <a href="{{ route('mobile.account') }}" @class(['on' => $active === 'account'])><x-m-icon name="user" />Akun</a>
        </nav>
    @endif

    {{-- Kolom scan cadangan: dibuka di browser biasa (tanpa kamera aplikasi). --}}
    <template x-if="fallbackOpen">
        <div>
            <div class="sheet-bg" @click="fallbackOpen = false"></div>
            <form class="sheet" @submit.prevent="submitFallback()">
                <div class="grab"></div>
                <h3>Masukkan kode</h3>
                <p class="muted" style="font-size:13px" x-text="fallbackHint"></p>
                <div class="scanfield" style="margin-top:14px">
                    <input x-ref="fallbackInput" x-model="fallbackCode" class="input" autocomplete="off" autocapitalize="off" enterkeyhint="go" placeholder="Kode / resi / SKU">
                    <button class="btn p sq" aria-label="Cari"><x-m-icon name="arrow" /></button>
                </div>
            </form>
        </div>
    </template>

    {{-- Update aplikasi: ditawarkan saat ada versi baru; versi wajib tidak bisa ditutup. --}}
    <template x-if="$store.update.open && $store.update.available">
        <div>
            <div class="sheet-bg" :style="$store.update.mandatory && 'background:var(--bg)'" @click="$store.update.later()"></div>
            <div class="sheet" role="dialog" aria-modal="true" aria-labelledby="update-title">
                <div class="grab" x-show="!$store.update.mandatory"></div>
                <div class="row" style="margin-bottom:12px">
                    <span class="thumb tone-info"><x-m-icon name="zap" /></span>
                    <div class="grow">
                        <h3 id="update-title" x-text="$store.update.mandatory ? 'Update wajib' : 'Update tersedia'"></h3>
                        <div class="muted" style="font-size:12.5px">
                            Versi <span x-text="$store.update.latest.versionName"></span>
                            <span x-show="$store.update.sizeLabel" x-text="`· ${$store.update.sizeLabel}`"></span>
                            · terpasang <span x-text="$store.update.installedName"></span>
                        </div>
                    </div>
                </div>

                <div class="banner warn" x-show="$store.update.mandatory" style="margin-bottom:12px">
                    <x-m-icon name="alert" size="s" />
                    <div>Versi ini wajib dipasang sebelum aplikasi bisa dipakai lagi. Data & antrean offline tetap aman.</div>
                </div>

                <div x-show="$store.update.latest.changelog" class="card pad" style="margin-bottom:12px">
                    <div class="field-label" style="margin-top:0">Yang baru</div>
                    <p style="white-space:pre-line;font-size:13.5px;line-height:1.5" x-text="$store.update.latest.changelog"></p>
                </div>

                <div x-show="$store.update.status === 'downloading'" style="margin-bottom:12px">
                    <div class="row muted" style="font-size:12.5px;margin-bottom:6px">
                        <span class="grow">Mengunduh update...</span>
                        <span x-text="$store.update.total ? `${$store.update.percent}%` : ''"></span>
                    </div>
                    <div class="prog"><i :style="`width:${$store.update.total ? $store.update.percent : 15}%`"></i></div>
                </div>

                <div class="banner bad" x-show="$store.update.status === 'error'" style="margin-bottom:12px">
                    <x-m-icon name="alert" size="s" />
                    <div x-text="$store.update.error"></div>
                </div>

                <p class="muted" x-show="$store.update.status === 'installing'" style="font-size:12.5px;margin-bottom:12px">Tekan <b>Install</b> / <b>Perbarui</b> di layar Android. Bila diminta, izinkan Optima Gudang memasang aplikasi.</p>

                <div class="row">
                    <button type="button" class="btn s" x-show="!$store.update.mandatory" @click="$store.update.later()" :disabled="$store.update.status === 'downloading'">Nanti</button>
                    <button type="button" class="btn p" @click="$store.update.start()" :class="$store.update.status === 'downloading' && 'is-busy'"
                            x-text="$store.update.status === 'error' ? 'Coba lagi' : ($store.update.status === 'installing' ? 'Pasang lagi' : 'Update sekarang')"></button>
                </div>
            </div>
        </div>
    </template>
</body>
</html>
