<x-layouts.mobile title="Beranda" active="home" :header="false">
    <div x-data="mobileHome">
        <header class="top">
            <span style="color:var(--text)"><x-m-logo size="28" /></span>
            <div class="grow">
                <div class="sub">Halo,</div>
                <h1 class="truncate">{{ $user->name }}</h1>
            </div>
            <a href="{{ route('mobile.outbox') }}" class="iconbtn" aria-label="Antrean offline">
                <x-m-icon name="cloud" />
                <span class="dot" x-cloak x-show="$store.net.queued > 0"></span>
            </a>
        </header>

        <div class="content stack">
            <div class="banner info" x-cloak x-show="$store.update.available">
                <x-m-icon name="zap" size="s" />
                <div class="grow"><b>Versi baru <span x-text="$store.update.latest?.versionName"></span> tersedia</b>Perbarui aplikasi untuk fitur & perbaikan terbaru.</div>
                <button class="btn sm p" @click="$store.update.open = true">Update</button>
            </div>

            @if (! $warehouse)
                <div class="banner warn"><x-m-icon name="alert" size="s" /><div><b>Belum ada gudang</b>Akun ini belum punya akses ke gudang mana pun. Minta admin menambahkannya.</div></div>
            @else
                @if ($warehouses->count() > 1)
                    <form method="POST" action="{{ route('mobile.warehouse') }}">
                        @csrf
                        <select name="warehouse_id" class="select" onchange="this.form.submit()" aria-label="Gudang aktif">
                            @foreach ($warehouses as $w)
                                <option value="{{ $w->id }}" @selected($w->id === $warehouse->id)>{{ $w->code }} · {{ $w->name }}</option>
                            @endforeach
                        </select>
                    </form>
                @endif

                <div class="hero">
                    <div class="glow"></div>
                    <div class="row" style="position:relative">
                        <div class="grow">
                            <div class="muted" style="font-size:12px">{{ $warehouse->name }} · {{ now()->translatedFormat('l, d M') }}</div>
                            <div class="big-title" style="margin-top:6px">{{ number_format($stats['packed_today'], 0, ',', '.') }} <span class="muted" style="font-size:15px;font-weight:600">/ {{ number_format($target, 0, ',', '.') }} paket</span></div>
                            <div class="muted" style="font-size:12.5px;margin-top:2px">dikemas hari ini</div>
                        </div>
                        <div class="ring" style="--p:{{ $progress }};--track:var(--hero-line);--ringbg:var(--hero)"><div>{{ $progress }}%</div></div>
                    </div>
                    <div class="hero-stats">
                        <div><b>{{ $stats['picking'] }}</b><span>Antre QC</span></div>
                        <div><b>{{ $stats['packed'] }}</b><span>Siap kirim</span></div>
                        <div><b>{{ $stats['shipped_today'] }}</b><span>Terkirim</span></div>
                    </div>
                </div>

                <div class="h2">Pekerjaan</div>
                @if (empty($features))
                    <div class="card empty">
                        <div class="thumb tone-plain"><x-m-icon name="shield" size="l" /></div>
                        <b>Belum ada menu</b>
                        Role Anda belum punya hak untuk fitur gudang. Minta admin mengatur hak akses.
                    </div>
                @else
                    <div class="tiles">
                        @foreach ($features as $f)
                            <a href="{{ route($f['route']) }}" class="card tile">
                                <span class="thumb tone-{{ $f['tone'] }}"><x-m-icon :name="$f['icon']" /></span>
                                <div>
                                    <div class="t">{{ $f['label'] }}</div>
                                    <div class="m">{{ $f['stat'] ? str_replace(':n', number_format($stats[$f['stat']], 0, ',', '.'), $f['hint']) : $f['hint'] }}</div>
                                </div>
                            </a>
                        @endforeach
                    </div>
                @endif
            @endif
        </div>
    </div>
</x-layouts.mobile>
