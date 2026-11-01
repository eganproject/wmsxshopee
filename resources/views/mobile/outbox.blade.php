<x-layouts.mobile title="Antrean Offline" sub="Data yang menunggu dikirim ke server" active="outbox" with-bar>
    <div x-data="outboxApp" class="content stack">
        <div class="banner" :class="$store.net.online ? 'ok' : 'warn'">
            <x-m-icon name="wifi" size="s" x-show="$store.net.online" /><x-m-icon name="wifioff" size="s" x-show="!$store.net.online" />
            <div x-text="$store.net.online ? 'Online. Antrean dikirim otomatis.' : 'Offline. Semua scan disimpan di HP dan dikirim begitu sinyal kembali.'"></div>
        </div>

        <div class="seg">
            <button type="button" :class="filter === 'all' && 'on'" @click="filter = 'all'">Semua <span x-text="items.length"></span></button>
            <button type="button" :class="filter === 'pending' && 'on'" @click="filter = 'pending'">Menunggu <span x-text="pending"></span></button>
            <button type="button" :class="filter === 'failed' && 'on'" @click="filter = 'failed'">Gagal <span x-text="failed"></span></button>
        </div>

        <div class="card empty" x-show="!shown.length">
            <div class="thumb tone-ok"><x-m-icon name="check" size="l" /></div>
            <b>Semua sudah terkirim</b>Tidak ada data yang tertahan di HP.
        </div>

        <div class="card list" x-show="shown.length">
            <template x-for="item in shown" :key="item.id">
                <div class="it" style="align-items:flex-start">
                    <span class="thumb" :class="`tone-${tone(item.kind)}`">
                        @foreach (['pkgcheck', 'truck', 'inbox', 'clip', 'layers', 'undo', 'cloud'] as $icon)
                            <x-m-icon :name="$icon" x-show="icon(item.kind) === '{{ $icon }}'" />
                        @endforeach
                    </span>
                    <div class="grow">
                        <div class="t" style="font-size:13.5px" x-text="item.label"></div>
                        <div class="m" x-text="when(item)"></div>
                        <div class="m" style="color:var(--bad)" x-show="item.status === 'failed'" x-text="item.error"></div>
                        <div class="row" style="gap:8px;margin-top:8px" x-show="item.status === 'failed'">
                            <button type="button" class="btn s sm" @click="retry(item)">Coba lagi</button>
                            <button type="button" class="btn danger sm" @click="discard(item)">Hapus</button>
                        </div>
                    </div>
                    <span class="chip" :class="item.status === 'failed' ? 'bad' : 'warn'" x-text="item.status === 'failed' ? 'Gagal' : 'Menunggu'"></span>
                </div>
            </template>
        </div>
        <p class="muted" style="font-size:12px;margin:0 2px">Data gagal biasanya karena sudah tidak valid (cth. pesanan dibatalkan). Periksa, lalu coba lagi atau hapus.</p>

        <div class="bar">
            <button type="button" class="btn p" @click="sync()" :disabled="!pending"><x-m-icon name="refresh" />Kirim sekarang</button>
        </div>
    </div>
</x-layouts.mobile>
