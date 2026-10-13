<x-layouts.app title="Profil Saya">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Profil Saya</span>
    </x-slot:breadcrumb>

    <x-page-header title="Profil Saya" description="Kelola informasi akun dan password Anda." icon="heroicon-o-user-circle" />

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="card p-6 text-center lg:row-span-2 lg:self-start">
            <x-avatar :user="$user" class="mx-auto size-20 text-2xl" />
            <h2 class="mt-4 font-semibold text-slate-900">{{ $user->name }}</h2>
            <p class="text-sm text-slate-500">{{ '@'.$user->username }}</p>
            <div class="mt-4 flex flex-wrap justify-center gap-2">
                @forelse ($user->roles as $role)
                    <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs text-slate-700">{{ $role->name }}</span>
                @empty
                    <span class="text-xs text-slate-400">Tanpa role</span>
                @endforelse
            </div>
            <dl class="mt-6 space-y-3 border-t border-slate-100 pt-5 text-left text-sm">
                <div class="flex items-center gap-2 text-slate-600">
                    <x-heroicon-o-envelope class="size-4 text-slate-400" /> {{ $user->email }}
                </div>
                <div class="flex items-center gap-2 text-slate-600">
                    <x-heroicon-o-clock class="size-4 text-slate-400" />
                    Login terakhir: {{ $user->last_login_at?->diffForHumans() ?? '-' }}
                </div>
            </dl>
        </div>

        <form method="POST" action="{{ route('profile.update') }}" class="card lg:col-span-2">
            @csrf
            @method('PUT')
            <div class="border-b border-slate-100 px-5 py-3">
                <h2 class="flex items-center gap-2 text-base font-semibold text-slate-900">
                    Informasi Akun
                </h2>
            </div>
            <div class="grid gap-x-6 gap-y-4 p-5 sm:grid-cols-2">
                <x-input name="name" label="Nama Lengkap" icon="heroicon-o-user" :value="$user->name" required class="sm:col-span-2" />
                <x-input name="email" type="email" label="Email" icon="heroicon-o-envelope" :value="$user->email" required />
                <x-input name="phone" label="No. Telepon" icon="heroicon-o-phone" :value="$user->phone" />
                <x-input name="username_display" label="Username" icon="heroicon-o-at-symbol" :value="$user->username" disabled
                         hint="Username hanya bisa diubah oleh administrator." class="sm:col-span-2" />
            </div>
            <div class="flex justify-end border-t border-slate-100 px-5 py-3">
                <button type="submit" class="btn-primary"><x-heroicon-o-check class="size-5" /> Simpan Perubahan</button>
            </div>
        </form>

        <form method="POST" action="{{ route('profile.password') }}" class="card lg:col-span-2">
            @csrf
            @method('PUT')
            <div class="border-b border-slate-100 px-5 py-3">
                <h2 class="flex items-center gap-2 text-base font-semibold text-slate-900">
                    Ubah Password
                </h2>
            </div>
            <div class="grid gap-x-6 gap-y-4 p-5 sm:grid-cols-2">
                <x-input name="current_password" type="password" bag="password" label="Password Saat Ini" autocomplete="current-password" required class="sm:col-span-2" />
                <x-input name="password" type="password" bag="password" label="Password Baru" autocomplete="new-password"
                         hint="Minimal 8 karakter, kombinasi huruf & angka." required />
                <x-input name="password_confirmation" type="password" bag="password" label="Konfirmasi Password Baru" autocomplete="new-password" required />
            </div>
            <div class="flex justify-end border-t border-slate-100 px-5 py-3">
                <button type="submit" class="btn-primary"><x-heroicon-o-key class="size-5" /> Ubah Password</button>
            </div>
        </form>
    </div>
</x-layouts.app>
