<x-layouts.app title="Buat Role">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('admin.roles.index') }}" class="hover:text-slate-700">Role & Permission</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Buat Role</span>
    </x-slot:breadcrumb>

    <x-page-header title="Buat Role Baru" description="Tentukan nama role dan hak aksesnya." icon="heroicon-o-plus">
        <a href="{{ route('admin.roles.index') }}" class="btn-secondary"><x-heroicon-o-arrow-left class="size-5" /> Kembali</a>
    </x-page-header>

    <form method="POST" action="{{ route('admin.roles.store') }}" class="mx-auto max-w-4xl">
        @csrf
        @include('admin.roles._form')

        <div class="sticky bottom-0 mt-6 flex justify-end gap-3 border-t border-slate-200 bg-slate-50/90 py-4 backdrop-blur">
            <a href="{{ route('admin.roles.index') }}" class="btn-secondary">Batal</a>
            <button type="submit" class="btn-primary"><x-heroicon-o-check class="size-5" /> Simpan Role</button>
        </div>
    </form>
</x-layouts.app>
