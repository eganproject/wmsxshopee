<x-layouts.mobile title="Serah Terima Kurir" :sub="$warehouse?->name" :back="route('mobile.home')">
    <div x-data="handoverApp" class="content stack">
        <div class="card pad">
            <div class="row">
                <span class="thumb tone-info"><x-m-icon name="truck" /></span>
                <div class="grow">
                    <div class="t" style="font-weight:650">Scan resi paket ke kurir</div>
                    <div class="m muted" style="font-size:12px">Kamera tetap terbuka, scan beruntun. Manifest dibuat otomatis per kurir.</div>
                </div>
            </div>
            <div class="row" style="margin-top:12px;gap:8px">
                <button type="button" class="btn b" @click="toggleCamera()">
                    <x-m-icon name="scan" /><span x-text="camera ? 'Tutup kamera' : 'Mulai scan'"></span>
                </button>
            </div>
            <form class="scanfield" style="margin-top:10px" @submit.prevent="scan()">
                <input x-model="code" class="input" placeholder="atau ketik resi" autocomplete="off" autocapitalize="off" enterkeyhint="go">
                <button class="btn s sq" aria-label="Kirim"><x-m-icon name="arrow" /></button>
            </form>
        </div>

        <template x-if="results.length">
            <div>
                <div class="h2"><span>Hasil scan</span><span class="chip ok" x-text="`${sent} terkirim`"></span></div>
                <div class="card list">
                    <template x-for="r in results.slice(0, 40)" :key="r.id">
                        <div class="it">
                            <span class="check" :class="{ done: r.status === 'ok', over: r.status === 'bad', todo: r.status === 'sending' || r.status === 'queued' || r.status === 'warn' }">
                                <x-m-icon name="check" size="xs" x-show="r.status === 'ok'" />
                                <x-m-icon name="x" size="xs" x-show="r.status === 'bad'" />
                            </span>
                            <div class="grow">
                                <div class="t mono truncate" style="font-size:13px" x-text="r.code"></div>
                                <div class="m" :style="r.status === 'bad' ? 'color:var(--bad)' : (r.status === 'warn' || r.status === 'queued' ? 'color:var(--warn)' : '')" x-text="r.text"></div>
                            </div>
                            <span class="muted" style="font-size:11px" x-text="time(r.at)"></span>
                        </div>
                    </template>
                </div>
            </div>
        </template>

        <div class="h2">Siap diserahkan</div>
        @if ($ready->isEmpty())
            <div class="card empty" style="padding:22px">
                <b>Tidak ada paket menunggu</b>Semua paket yang sudah QC sudah diserahkan.
            </div>
        @else
            <div class="card list">
                @foreach ($ready as $courier => $count)
                    <div class="it">
                        <span class="thumb tone-plain">{{ mb_strtoupper(mb_substr($courier, 0, 2)) }}</span>
                        <div class="grow"><div class="t">{{ $courier }}</div><div class="m">menunggu diambil</div></div>
                        <span class="chip info">{{ $count }} paket</span>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="h2">Manifest hari ini</div>
        @if ($manifests->isEmpty())
            <div class="card empty" style="padding:22px"><b>Belum ada manifest</b>Manifest muncul setelah resi pertama discan.</div>
        @else
            <div class="card list">
                @foreach ($manifests as $m)
                    <a href="{{ route('mobile.handover.show', $m) }}" class="it">
                        <span class="thumb tone-info"><x-m-icon name="file" /></span>
                        <div class="grow">
                            <div class="t mono" style="font-size:13px">{{ $m->number }}</div>
                            <div class="m">{{ $m->courier?->name ?? 'Tanpa kurir' }} · {{ $m->orders_count }} paket · {{ $m->created_at->format('H.i') }}</div>
                        </div>
                        @if ($m->signed_at)
                            <span class="chip ok"><x-m-icon name="pen" size="xs" />TTD</span>
                        @else
                            <span class="chip warn">Minta TTD</span>
                        @endif
                        <x-m-icon name="right" size="s" class="muted" />
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</x-layouts.mobile>
