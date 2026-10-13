<x-layouts.app title="Backup Database">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span>Administrasi</span>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Backup Database</span>
    </x-slot:breadcrumb>

    <x-page-header title="Backup Database" description="Salinan seluruh data Optima. Dibuat otomatis tiap hari pukul {{ $time }} dan disimpan {{ $keepDays }} hari." icon="heroicon-o-circle-stack">
        @can('backups.create')
            <form method="POST" action="{{ route('admin.backups.store') }}" x-data="{ busy: false }" @submit="busy = true">
                @csrf
                <button type="submit" class="btn-primary" :disabled="busy">
                    <x-heroicon-o-arrow-path class="size-5 animate-spin" x-show="busy" x-cloak />
                    <x-heroicon-o-plus class="size-5" x-show="!busy" />
                    <span x-text="busy ? 'Membuat backup...' : 'Backup sekarang'">Backup sekarang</span>
                </button>
            </form>
        @endcan
    </x-page-header>

    <div class="card">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                    <tr>
                        <th class="h-8 px-4 py-0 font-normal">File</th>
                        <th class="h-8 px-4 py-0 font-normal">Dibuat</th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Ukuran</th>
                        <th class="h-8 w-24 px-4 py-0"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($backups as $backup)
                        <tr class="border-t border-slate-100 hover:bg-slate-50/60">
                            <td class="px-4 py-2.5 font-mono text-[13px] text-slate-900">{{ $backup['name'] }}</td>
                            <td class="px-4 py-2.5 whitespace-nowrap text-slate-700">
                                {{ $backup['created_at']->format('d/m/Y H:i') }}
                                <p class="text-xs text-slate-400">{{ $backup['created_at']->diffForHumans() }}</p>
                            </td>
                            <td class="px-4 py-2.5 text-right whitespace-nowrap text-slate-600 tabular-nums">{{ \Illuminate\Support\Number::fileSize($backup['size'], 1) }}</td>
                            <td class="px-4 py-2.5">
                                <div class="flex justify-end gap-1">
                                    <a href="{{ route('admin.backups.download', $backup['name']) }}" class="btn-icon" title="Unduh">
                                        <x-heroicon-o-arrow-down-tray class="size-5" />
                                    </a>
                                    @can('backups.delete')
                                        <form id="delete-{{ $loop->index }}" method="POST" action="{{ route('admin.backups.destroy', $backup['name']) }}">
                                            @csrf @method('DELETE')
                                        </form>
                                        <button type="button" class="btn-icon hover:bg-rose-50 hover:text-rose-600" title="Hapus"
                                                @click="$dispatch('confirm', {
                                                    form: 'delete-{{ $loop->index }}',
                                                    title: 'Hapus backup?',
                                                    message: @js("File {$backup['name']} akan dihapus permanen dan tidak bisa dipakai untuk memulihkan data."),
                                                    confirmText: 'Hapus',
                                                })">
                                            <x-heroicon-o-trash class="size-5" />
                                        </button>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-5 py-16 text-center">
                                <x-heroicon-o-circle-stack class="mx-auto size-12 text-slate-300" />
                                <p class="mt-3 font-medium text-slate-700">Belum ada backup</p>
                                <p class="text-sm text-slate-500">Backup pertama akan dibuat otomatis malam ini, atau klik "Backup sekarang".</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
        <p class="font-medium text-slate-800">Cara memulihkan</p>
        <p class="mt-1">Unduh file backup, ekstrak (.gz), lalu buka di HeidiSQL (Laragon › Database) dan pilih <span class="font-medium">File › Run SQL file</span> pada database Optima. Semua tabel akan diganti dengan isi backup.</p>
        <p class="mt-2">Backup otomatis butuh scheduler berjalan: biarkan <code class="rounded bg-white px-1 font-mono text-[12px]">php artisan schedule:work</code> terbuka, atau jadwalkan <code class="rounded bg-white px-1 font-mono text-[12px]">php artisan schedule:run</code> tiap menit di Task Scheduler Windows.</p>
    </div>
</x-layouts.app>
