    <form method="POST" action="{{ route('tiktok.settings.update') }}" data-dirty-guard class="space-y-6">
        @csrf @method('PUT')

        <div class="card">
            <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3">
                <h2 class="text-base font-semibold text-slate-900">Kredensial App</h2>
                @if ($tiktok->isConfigured())
                    <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs text-emerald-700"><x-heroicon-o-check-circle class="size-4" /> Lengkap</span>
                @else
                    <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">Belum diisi</span>
                @endif
            </div>
            <div class="grid gap-x-6 gap-y-4 p-5 sm:grid-cols-2">
                <x-input name="app_key" label="App Key" icon="heroicon-o-hashtag" :value="$tiktok->app_key" placeholder="cth. 6abc1d2ef3g4h" required />
                <x-input name="app_secret" type="password" label="App Secret" icon="heroicon-o-lock-closed" autocomplete="new-password"
                         :placeholder="$tiktok->app_secret ? '•••••••• (tersimpan)' : 'Tempel app secret'"
                         :hint="$tiktok->app_secret ? 'Kosongkan untuk tetap memakai secret yang tersimpan.' : 'Disimpan terenkripsi, tidak ditampilkan lagi.'" />
                <x-input name="service_id" label="Service ID" icon="heroicon-o-identification" :value="$tiktok->service_id" placeholder="cth. 7312345678901234567"
                         hint="Dari Partner Center › App › Authorization. Dipakai untuk tautan otorisasi toko." />
            </div>
            <p class="border-t border-slate-100 px-5 py-3 text-xs text-slate-500">
                Sejak API versi 202309 TikTok tidak punya sandbox terpisah. Uji coba memakai toko uji (test shop) dari Partner Center.
                <span class="font-mono text-slate-400">{{ $tiktokHosts['api'] }}</span>
            </p>
        </div>

        <div class="card">
            <div class="border-b border-slate-100 px-5 py-3">
                <h2 class="text-base font-semibold text-slate-900">Redirect URL & Webhook</h2>
            </div>
            <div class="space-y-4 p-5">
                @foreach (['Redirect URL (callback otorisasi)' => $tiktok->callbackUrl(), 'Webhook URL' => $tiktok->webhookUrl()] as $label => $url)
                    <div>
                        <p class="form-label">{{ $label }}</p>
                        <div class="flex items-center gap-2" x-data="{ copied: false }">
                            <code class="flex-1 truncate rounded-md border border-slate-200 bg-slate-50 px-2.5 py-1.5 font-mono text-[13px] text-slate-800" x-ref="url">{{ $url }}</code>
                            <button type="button" class="btn-secondary" @click="navigator.clipboard.writeText($refs.url.textContent); copied = true; setTimeout(() => copied = false, 1500)">
                                <x-heroicon-o-clipboard-document class="size-5" /> <span x-text="copied ? 'Tersalin' : 'Salin'">Salin</span>
                            </button>
                        </div>
                    </div>
                @endforeach
                <p class="text-xs text-slate-400">Redirect URL didaftarkan di pengaturan app Partner Center. Webhook (Partner Center › Manage Webhooks: Order status, Package update, Recipient address, Cancellation, Return status, Seller deauthorization, Upcoming authorization expiration) butuh alamat HTTPS publik; tanpa webhook, sinkron terjadwal tiap 5 menit tetap berjalan.</p>
                <x-input name="redirect_base_url" label="Domain publik (opsional)" icon="heroicon-o-globe-alt" :value="$tiktok->redirect_base_url"
                         placeholder="cth. https://optima.tokosaya.com"
                         hint="Isi bila domain yang didaftarkan di TikTok berbeda dengan alamat Optima saat ini, cth. domain publik atau tunnel. Kosong = {{ url('/') }}." />
            </div>
        </div>

        <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
            <p class="font-medium text-slate-800">Langkah menyiapkan app di TikTok Shop Partner Center</p>
            <ol class="mt-1 list-decimal space-y-1 pl-5">
                <li>Daftar di <span class="font-medium">partner.tiktokshop.com</span> sebagai developer seller, lalu buat app untuk toko sendiri (custom / in-house), bukan app publik.</li>
                <li>Aktifkan scope: Authorization, Order, Product, Logistics/Fulfillment, Finance dan Return/Refund. Daftarkan Redirect URL di atas.</li>
                <li>Salin App Key, App Secret dan Service ID ke form ini lalu simpan.</li>
                <li>Buka menu Toko Marketplace, pilih platform TikTok dan gudang pengirim, klik <span class="font-medium">Hubungkan Toko</span>, login dengan akun Seller Center lalu setujui. Satu akun seller bisa membawa beberapa toko sekaligus.</li>
            </ol>
        </div>

        <div class="sticky bottom-0 flex justify-end gap-2 border-t border-slate-200 bg-white/90 py-3 backdrop-blur">
            <button type="submit" class="btn-primary"><x-heroicon-o-check class="size-5" /> Simpan Pengaturan TikTok</button>
        </div>
    </form>
