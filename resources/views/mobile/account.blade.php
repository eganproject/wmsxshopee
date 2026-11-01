<x-layouts.mobile title="Akun" active="account">
    <div x-data="accountApp" class="content stack">
        <div class="card pad row">
            <span class="thumb tone-plain" style="width:52px;height:52px;border-radius:16px;font-size:17px">{{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}</span>
            <div class="grow">
                <div style="font-size:16px;font-weight:650">{{ $user->name }}</div>
                <div class="muted" style="font-size:12.5px">{{ $user->username ?? $user->email }} · {{ $user->roles->pluck('name')->join(', ') ?: 'Tanpa role' }}</div>
            </div>
        </div>

        <div class="card pad">
            <div class="kv"><span>Gudang aktif</span><span>{{ $warehouse?->name ?? '-' }}</span></div>
            <div class="kv"><span>Server</span><span class="mono" style="font-size:12px">{{ request()->getHost() }}</span></div>
            <div class="kv"><span>Versi aplikasi</span><span x-text="info ? `${info.versionName} (${info.versionCode})` : 'Browser'"></span></div>
            <div class="kv"><span>Versi terbaru</span><span>{{ $app['versionName'] }} ({{ $app['versionCode'] }})</span></div>
        </div>

        <div class="field-label">Tema</div>
        <div class="seg">
            <button type="button" :class="theme === 'light' && 'on'" @click="setTheme('light')"><x-m-icon name="sun" size="s" />Terang</button>
            <button type="button" :class="theme === 'dark' && 'on'" @click="setTheme('dark')">Gelap</button>
            <button type="button" :class="theme === 'system' && 'on'" @click="setTheme('system')">Ikuti HP</button>
        </div>

        <div class="card list" style="margin-top:16px">
            <a href="{{ route('mobile.outbox') }}" class="it"><x-m-icon name="cloud" /><div class="grow t">Antrean offline</div><x-m-icon name="right" size="s" class="muted" /></a>
            <a href="{{ route('mobile.print') }}" class="it"><x-m-icon name="printer" /><div class="grow t">Printer & label</div><x-m-icon name="right" size="s" class="muted" /></a>
            <button type="button" class="it" x-show="info" @click="changeServer()"><x-m-icon name="refresh" /><div class="grow t">Ganti server (Live / Test)</div><x-m-icon name="right" size="s" class="muted" /></button>
        </div>

        <form method="POST" action="{{ route('logout') }}" x-ref="logout" @submit.prevent="logout($refs.logout)">
            @csrf
            <button class="btn danger" style="width:100%;margin-top:6px">Keluar</button>
        </form>
    </div>
</x-layouts.mobile>
