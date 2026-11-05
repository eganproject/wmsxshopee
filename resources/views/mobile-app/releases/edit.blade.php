<x-layouts.app title="Ubah Versi {{ $release->version_name }}">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('mobile.app') }}" class="hover:text-slate-700">Aplikasi Android</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Versi {{ $release->version_name }}</span>
    </x-slot:breadcrumb>

    <x-page-header title="Ubah Versi {{ $release->version_name }}" description="Build {{ $release->version_code }} · {{ \Illuminate\Support\Number::fileSize($release->file_size, 1) }}. File APK tidak bisa diganti; untuk APK baru, upload versi baru.">
        <a href="{{ route('mobile.app') }}" class="btn-secondary"><x-heroicon-o-arrow-left class="size-5" /> Kembali</a>
    </x-page-header>

    <form method="POST" action="{{ route('mobile.releases.update', $release) }}" data-dirty-guard class="max-w-3xl space-y-6">
        @csrf @method('PUT')

        <div class="card p-5">
            <x-textarea name="changelog" label="Catatan Perubahan" tip="Ditampilkan di HP saat update ditawarkan." :value="$release->changelog" rows="5" />
        </div>

        @include('mobile-app.releases._mandatory')

        <div class="sticky bottom-0 flex justify-end gap-2 border-t border-slate-200 bg-white/90 py-3 backdrop-blur">
            <a href="{{ route('mobile.app') }}" class="btn-secondary">Batal</a>
            <button type="submit" class="btn-primary"><x-heroicon-o-check class="size-5" /> Simpan</button>
        </div>
    </form>
</x-layouts.app>
