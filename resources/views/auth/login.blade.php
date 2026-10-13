<x-layouts.guest title="Masuk">
    <div class="flex min-h-full items-center justify-center bg-slate-50 px-4 py-12">
        <div class="w-full max-w-sm">
            <div class="mb-6 flex flex-col items-center text-center">
                <x-app-logo variant="horizontal" class="h-10 w-auto" />
                <h1 class="mt-6 text-xl font-semibold tracking-normal text-slate-900">Masuk ke akun Anda</h1>
                <p class="mt-1 text-sm text-slate-500">{{ config('app.tagline') }}</p>
            </div>

            <div class="card p-6">
                @if (session('status'))
                    <div class="mb-4 flex items-center gap-2 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-700 ring-1 ring-emerald-200">
                        <x-heroicon-o-check-circle class="size-4 shrink-0" /> {{ session('status') }}
                    </div>
                @endif

                <form method="POST" action="{{ route('login.store') }}" class="space-y-4" x-data="{ loading: false }" @submit="loading = true">
                    @csrf

                    <x-input name="login" label="Email atau Username" icon="heroicon-o-user"
                             placeholder="nama@perusahaan.com" autocomplete="username" autofocus required />

                    <x-input name="password" type="password" label="Password" icon="heroicon-o-lock-closed"
                             placeholder="••••••••" autocomplete="current-password" required />

                    <label class="flex items-center gap-2 text-sm text-slate-600">
                        <input type="checkbox" name="remember" class="form-checkbox" @checked(old('remember'))>
                        Ingat saya
                    </label>

                    <button type="submit" class="btn-primary w-full" :disabled="loading">
                        <x-heroicon-o-arrow-path class="size-5 animate-spin" x-show="loading" x-cloak />
                        <x-heroicon-o-arrow-right-start-on-rectangle class="size-5 rotate-180" x-show="!loading" />
                        <span x-text="loading ? 'Memproses...' : 'Masuk'">Masuk</span>
                    </button>
                </form>
            </div>

            <p class="mt-4 text-center text-xs text-slate-500">
                Belum punya akun atau lupa password? Hubungi administrator untuk dibuatkan / direset.
            </p>
        </div>
    </div>
</x-layouts.guest>
