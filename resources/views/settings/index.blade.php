<x-layouts.app title="Pengaturan">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Pengaturan</span>
    </x-slot:breadcrumb>

    <x-page-header title="Pengaturan" description="Semua pengaturan aplikasi dalam satu tempat: akun, pengguna & akses, integrasi, gudang dan sistem." />

    <div class="space-y-6">
        @foreach ($groups as $group)
            <section>
                <h2 class="mb-2 text-xs font-medium tracking-wide text-slate-500 uppercase">{{ $group['heading'] }}</h2>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($group['items'] as $item)
                        <a href="{{ route($item['route']) }}" class="card group flex items-start gap-3 p-4 transition hover:bg-slate-50/60 hover:ring-1 hover:ring-slate-300" data-testid="settings-card">
                            <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-600 group-hover:bg-white group-hover:text-slate-900">
                                <x-dynamic-component :component="$item['icon']" class="size-5" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="flex items-center gap-1 text-sm font-medium text-slate-900">
                                    {{ $item['label'] }}
                                    <x-heroicon-o-chevron-right class="size-3.5 text-slate-400 transition group-hover:translate-x-0.5" />
                                </span>
                                <span class="mt-0.5 block text-xs leading-relaxed text-slate-500">{{ $item['description'] }}</span>
                            </span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
</x-layouts.app>
