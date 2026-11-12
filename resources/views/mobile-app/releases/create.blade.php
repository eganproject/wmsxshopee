<x-layouts.app title="Upload Versi Aplikasi">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('mobile.app') }}" class="hover:text-slate-700">Aplikasi Android</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Upload Versi</span>
    </x-slot:breadcrumb>

    <x-page-header title="Upload Versi Aplikasi" description="Terbitkan APK baru. Aplikasi di HP mengecek versi saat dibuka lalu menawarkan update yang bisa dipasang langsung dari dalam aplikasi.">
        <a href="{{ route('mobile.app') }}" class="btn-secondary"><x-heroicon-o-arrow-left class="size-5" /> Kembali</a>
    </x-page-header>

    <form method="POST" action="{{ route('mobile.releases.store') }}" enctype="multipart/form-data" data-dirty-guard class="max-w-3xl space-y-6"
          x-data="{ source: @js(old('source', 'github')), name: @js(old('version_name', $release->version_name)), busy: false }"
          @submit="busy = true" @pageshow.window="busy = false">
        @csrf

        <div class="card">
            <div class="border-b border-slate-100 px-5 py-3">
                <h2 class="text-base font-semibold text-slate-900">Versi</h2>
                <p class="mt-0.5 text-sm text-slate-500">
                    @if ($latest)
                        Versi terakhir: {{ $latest->version_name }} (build {{ $latest->version_code }}).
                    @else
                        Belum ada versi yang diunggah.
                    @endif
                    Isi sama persis dengan <span class="font-mono text-xs">android/version.properties</span> saat APK dibuat.
                </p>
            </div>
            <div class="grid gap-x-6 gap-y-4 p-5 sm:grid-cols-2">
                <x-input name="version_name" label="Nama Versi" tip="Versi yang tampil ke pengguna, cth. 1.2.0 (versionName)." :value="$release->version_name" placeholder="cth. 1.2.0" x-model="name" required />
                <x-input name="version_code" type="number" min="1" label="Nomor Build" tip="Angka versionCode di APK. Harus lebih besar dari versi sebelumnya, karena HP memakai angka ini untuk tahu ada update." :value="$release->version_code" required />
                <x-textarea name="changelog" label="Catatan Perubahan" tip="Ditampilkan di HP saat update ditawarkan. Tulis singkat, satu perubahan per baris." :value="$release->changelog" rows="4" class="sm:col-span-2" />
            </div>
        </div>

        <div class="card">
            <div class="border-b border-slate-100 px-5 py-3">
                <h2 class="text-base font-semibold text-slate-900">File APK</h2>
            </div>
            <div class="space-y-4 p-5">
                <label class="flex cursor-pointer gap-3 rounded-lg border p-3" :class="source === 'github' ? 'border-slate-900 bg-slate-50' : 'border-slate-200'">
                    <input type="radio" name="source" value="github" x-model="source" class="mt-1">
                    <span>
                        <span class="block font-medium text-slate-900">Ambil dari GitHub Release</span>
                        <span class="block text-sm text-slate-500">Server mengunduh APK yang dibuat otomatis oleh GitHub Actions setelah perubahan Android di-merge ke main. Tidak terkena batas ukuran upload.</span>
                        <span class="mt-1 block break-all font-mono text-xs text-slate-400" x-text="@js($githubTemplate).replace('{version}', name || '{versi}')"></span>
                    </span>
                </label>
                <label class="flex cursor-pointer gap-3 rounded-lg border p-3" :class="source === 'upload' ? 'border-slate-900 bg-slate-50' : 'border-slate-200'">
                    <input type="radio" name="source" value="upload" x-model="source" class="mt-1">
                    <span>
                        <span class="block font-medium text-slate-900">Upload file APK</span>
                        <span class="block text-sm text-slate-500">Pilih file .apk dari komputer.@if ($maxUploadMb) Batas upload server saat ini {{ $maxUploadMb }} MB.@endif</span>
                    </span>
                </label>
                @error('source')
                    <p class="flex items-center gap-1 text-sm text-rose-600"><x-heroicon-o-exclamation-triangle class="size-4 shrink-0" /> {{ $message }}</p>
                @enderror

                <div x-show="source === 'upload'" x-cloak>
                    <label for="apk" class="form-label">File APK <span class="text-rose-500">*</span></label>
                    <input id="apk" name="apk" type="file" accept=".apk,application/vnd.android.package-archive" :disabled="source !== 'upload'"
                           @class(['form-input file:mr-3 file:border-0 file:bg-transparent file:text-sm file:font-medium', 'form-input-error' => $errors->has('apk')])>
                    @error('apk')
                        <p class="mt-1 flex items-center gap-1 text-xs text-rose-600"><x-heroicon-o-exclamation-triangle class="size-4 shrink-0" /> {{ $message }}</p>
                    @enderror
                </div>
            </div>
        </div>

        @include('mobile-app.releases._mandatory')

        <div class="flex gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            <x-heroicon-o-key class="mt-0.5 size-5 shrink-0" />
            <p>APK harus ditandatangani kunci (keystore) yang sama dengan versi sebelumnya. Kalau berbeda, Android menolak memasang update dan aplikasi harus di-uninstall dulu.</p>
        </div>

        <div class="sticky bottom-0 flex justify-end gap-2 border-t border-slate-200 bg-white/90 py-3 backdrop-blur">
            <a href="{{ route('mobile.app') }}" class="btn-secondary">Batal</a>
            <button type="submit" class="btn-primary" :disabled="busy">
                <x-heroicon-o-arrow-path class="size-5 animate-spin" x-show="busy" x-cloak />
                <x-heroicon-o-arrow-up-tray class="size-5" x-show="!busy" />
                <span x-text="busy ? 'Menyimpan APK...' : 'Terbitkan Versi'">Terbitkan Versi</span>
            </button>
        </div>
    </form>
</x-layouts.app>
