    <form method="POST" action="{{ route('shopee.settings.update') }}" data-dirty-guard class="space-y-6"
          x-data="{ environment: @js(old('environment', $shopee->environment)) }">
        @csrf @method('PUT')

        <div class="card">
            <div class="border-b border-slate-100 px-5 py-3">
                <h2 class="text-base font-semibold text-slate-900">Mode Koneksi</h2>
            </div>
            <div class="grid gap-3 p-5 sm:grid-cols-2">
                @foreach (\App\Models\ShopeeSetting::ENVIRONMENTS as $value => $label)
                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border p-3"
                           :class="environment === @js($value) ? 'border-slate-900 bg-slate-50' : 'border-slate-200 hover:bg-slate-50'">
                        <input type="radio" name="environment" value="{{ $value }}" x-model="environment" class="mt-1">
                        <span>
                            <span class="block font-medium text-slate-900">{{ $label }}</span>
                            <span class="block text-xs text-slate-500">
                                {{ $value === 'test' ? 'Toko uji dari Shopee, aman untuk mencoba. Tidak menyentuh toko asli.' : 'Toko asli. Dipakai setelah app disetujui (Go Live) di Shopee Open Platform.' }}
                            </span>
                            <span class="mt-1 block font-mono text-[11px] text-slate-400">{{ $shopeeHosts[$value] }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
            <p class="border-t border-slate-100 px-5 py-3 text-xs text-slate-500">
                Toko yang dihubungkan memakai mode yang aktif saat tombol Hubungkan Toko diklik. Toko test dan live tersimpan terpisah.
            </p>
        </div>

        @foreach (['test' => 'Kredensial Test (Sandbox)', 'live' => 'Kredensial Live (Produksi)'] as $env => $title)
            <div class="card">
                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3">
                    <h2 class="text-base font-semibold text-slate-900">{{ $title }}</h2>
                    @if ($shopee->isConfigured($env))
                        <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs text-emerald-700"><x-heroicon-o-check-circle class="size-4" /> Lengkap</span>
                    @else
                        <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">Belum diisi</span>
                    @endif
                </div>
                <div class="grid gap-x-6 gap-y-4 p-5 sm:grid-cols-2">
                    <x-input name="{{ $env }}_partner_id" type="number" min="1" label="Partner ID" icon="heroicon-o-hashtag"
                             :value="$shopee->{$env.'_partner_id'}" placeholder="cth. 2001234" />
                    <div>
                        <x-input name="{{ $env }}_partner_key" type="password" label="Partner Key" icon="heroicon-o-lock-closed" autocomplete="new-password"
                                 :placeholder="$shopee->{$env.'_partner_key'} ? '•••••••• (tersimpan)' : 'Tempel partner key'"
                                 :hint="$shopee->{$env.'_partner_key'} ? 'Kosongkan untuk tetap memakai key yang tersimpan.' : 'Disimpan terenkripsi, tidak ditampilkan lagi.'" />
                        @if ($shopee->{$env.'_partner_key'})
                            <label class="mt-1.5 flex items-center gap-2 text-xs text-slate-500">
                                <input type="checkbox" name="clear_{{ $env }}_partner_key" value="1"> Hapus key yang tersimpan
                            </label>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach

        <div class="card">
            <div class="border-b border-slate-100 px-5 py-3">
                <h2 class="text-base font-semibold text-slate-900">Redirect URL & Live Push</h2>
            </div>
            <div class="space-y-4 p-5">
                @foreach (['Redirect URL (callback otorisasi)' => $shopee->callbackUrl(), 'Live Push URL (opsional)' => $shopee->webhookUrl()] as $label => $url)
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
                <p class="text-xs text-slate-400">Daftarkan domain Redirect URL sebagai Redirect URL Domain di console app Shopee Open Platform. Live Push URL diisi di menu Push Mechanism (centang order status &amp; tracking number) bila Optima bisa diakses dari internet; tanpa itu sinkron terjadwal tiap 5 menit tetap berjalan.</p>
                <x-input name="redirect_base_url" label="Domain redirect (opsional)" icon="heroicon-o-globe-alt" :value="$shopee->redirect_base_url"
                         placeholder="cth. https://optima.tokosaya.com"
                         hint="Isi bila domain yang didaftarkan di Shopee berbeda dengan alamat Optima saat ini, cth. domain publik atau tunnel. Kosong = {{ url('/') }}." />
            </div>
        </div>

        <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
            <p class="font-medium text-slate-800">Langkah menyiapkan app di Shopee</p>
            <ol class="mt-1 list-decimal space-y-1 pl-5">
                <li>Daftar di <span class="font-medium">open.shopee.com</span> sebagai seller, lalu buat app jenis <span class="font-medium">Seller In House System</span> (bukan ISV / pihak ketiga).</li>
                <li>Salin <span class="font-medium">Test Partner ID & Key</span> ke kredensial test di atas, dan daftarkan Redirect URL Domain.</li>
                <li>Buka menu Toko Marketplace, pilih platform Shopee dan gudang pengirim, klik <span class="font-medium">Hubungkan Toko</span>, lalu login dengan akun toko (test) dan setujui.</li>
                <li>Setelah app Go Live, isi kredensial live, ganti mode ke Live dan hubungkan toko asli. Otorisasi toko berlaku ± 1 tahun lalu perlu diulang.</li>
            </ol>
        </div>

        <div class="sticky bottom-0 flex justify-end gap-2 border-t border-slate-200 bg-white/90 py-3 backdrop-blur">
            <button type="submit" class="btn-primary"><x-heroicon-o-check class="size-5" /> Simpan Pengaturan Shopee</button>
        </div>
    </form>
