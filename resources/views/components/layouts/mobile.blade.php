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
</body>
</html>
