<x-layouts.guest title="Masuk">
    <div class="flex min-h-full">
        {{-- Panel brand --}}
        <div class="relative hidden w-1/2 overflow-hidden bg-slate-900 lg:flex lg:flex-col lg:justify-between lg:p-12">
            <div class="absolute -top-32 -right-32 size-96 rounded-full bg-brand-500/20 blur-3xl"></div>
            <div class="absolute -bottom-40 -left-20 size-96 rounded-full bg-brand-500/10 blur-3xl"></div>

            <div class="relative flex items-center gap-3">
                <span class="flex size-11 items-center justify-center rounded-xl bg-brand-500 text-white shadow-lg">
                    <x-heroicon-o-cube class="size-6" />
                </span>
                <span class="text-lg font-semibold text-white">{{ config('app.name') }}</span>
            </div>

            <div class="relative max-w-md">
                <h2 class="text-3xl leading-tight font-semibold text-white">Kelola gudang & pesanan Shopee dalam satu tempat.</h2>
                <ul class="mt-8 space-y-4 text-slate-300">
                    <li class="flex items-center gap-3">
                        <span class="flex size-9 items-center justify-center rounded-lg bg-white/10"><x-heroicon-o-archive-box class="size-5 text-brand-300" /></span>
                        Stok & inventori gudang real-time
                    </li>
                    <li class="flex items-center gap-3">
                        <span class="flex size-9 items-center justify-center rounded-lg bg-white/10"><x-heroicon-o-building-storefront class="size-5 text-brand-300" /></span>
                        Terintegrasi dengan Shopee Seller
                    </li>
                    <li class="flex items-center gap-3">
                        <span class="flex size-9 items-center justify-center rounded-lg bg-white/10"><x-heroicon-o-shield-check class="size-5 text-brand-300" /></span>
                        Hak akses tiap user dapat diatur
                    </li>
                </ul>
            </div>

            <p class="relative text-sm text-slate-500">&copy; {{ date('Y') }} {{ config('app.name') }}</p>
        </div>

        {{-- Form --}}
        <div class="flex flex-1 items-center justify-center px-6 py-12 sm:px-12">
            <div class="w-full max-w-sm">
                <div class="mb-8 flex items-center gap-3 lg:hidden">
                    <span class="flex size-10 items-center justify-center rounded-xl bg-brand-500 text-white">
                        <x-heroicon-o-cube class="size-6" />
                    </span>
                    <span class="text-lg font-semibold text-slate-900">{{ config('app.name') }}</span>
                </div>

                <h1 class="text-2xl font-semibold text-slate-900">Selamat datang kembali</h1>
                <p class="mt-1.5 text-sm text-slate-500">Masuk menggunakan akun yang diberikan administrator.</p>

                @if (session('status'))
                    <div class="mt-6 flex items-center gap-2 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700 ring-1 ring-emerald-200">
                        <x-heroicon-o-check-circle class="size-5 shrink-0" /> {{ session('status') }}
                    </div>
                @endif

                <form method="POST" action="{{ route('login.store') }}" class="mt-8 space-y-5" x-data="{ loading: false }" @submit="loading = true">
                    @csrf

                    <x-input name="login" label="Email atau Username" icon="heroicon-o-user"
                             placeholder="nama@perusahaan.com" autocomplete="username" autofocus required />

                    <x-input name="password" type="password" label="Password" icon="heroicon-o-lock-closed"
                             placeholder="••••••••" autocomplete="current-password" required />

                    <label class="flex items-center gap-2 text-sm text-slate-600">
                        <input type="checkbox" name="remember" class="form-checkbox" @checked(old('remember'))>
                        Ingat saya
                    </label>

                    <button type="submit" class="btn-primary w-full py-3" :disabled="loading">
                        <x-heroicon-o-arrow-path class="size-5 animate-spin" x-show="loading" x-cloak />
                        <x-heroicon-o-arrow-right-start-on-rectangle class="size-5 rotate-180" x-show="!loading" />
                        <span x-text="loading ? 'Memproses...' : 'Masuk'">Masuk</span>
                    </button>
                </form>

                <p class="mt-8 flex items-start gap-2 rounded-lg bg-slate-50 p-3 text-xs text-slate-500">
                    <x-heroicon-o-information-circle class="size-4 shrink-0 text-slate-400" />
                    Belum punya akun atau lupa password? Hubungi administrator untuk dibuatkan / direset.
                </p>
            </div>
        </div>
    </div>
</x-layouts.guest>
