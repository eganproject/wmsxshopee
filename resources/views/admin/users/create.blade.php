<x-layouts.app title="Buat Akun">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('admin.users.index') }}" class="hover:text-slate-700">Manajemen User</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Buat Akun</span>
    </x-slot:breadcrumb>

    <x-page-header title="Buat Akun Baru" description="Isi data user dan tentukan hak aksesnya." icon="heroicon-o-user-plus">
        <a href="{{ route('admin.users.index') }}" class="btn-secondary"><x-heroicon-o-arrow-left class="size-5" /> Kembali</a>
    </x-page-header>

    <form method="POST" action="{{ route('admin.users.store') }}" class="max-w-4xl">
        @csrf
        @include('admin.users._form')

        <div class="sticky bottom-0 mt-6 flex justify-end gap-2 border-t border-slate-200 bg-white/90 py-3 backdrop-blur">
            <a href="{{ route('admin.users.index') }}" class="btn-secondary">Batal</a>
            <button type="submit" class="btn-primary"><x-heroicon-o-check class="size-5" /> Simpan Akun</button>
        </div>
    </form>
</x-layouts.app>
