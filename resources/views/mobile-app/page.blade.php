<x-layouts.app title="Aplikasi Android">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Aplikasi Android</span>
    </x-slot:breadcrumb>

    <x-page-header title="Aplikasi Android" description="Optima Gudang untuk HP: QC kemas, serah terima kurir, penerimaan, stock opname, retur dan cetak label Bluetooth.">
        @can('mobile.releases')
            <a href="{{ route('mobile.releases.create') }}" class="btn-primary"><x-heroicon-o-arrow-up-tray class="size-5" /> Upload Versi Baru</a>
        @endcan
    </x-page-header>

    <div class="grid gap-5 lg:grid-cols-[1fr_320px]">
        <div class="card p-6">
            <div class="flex items-start gap-4">
                <span class="flex size-14 shrink-0 items-center justify-center rounded-2xl bg-slate-900 text-white">
                    <x-heroicon-o-device-phone-mobile class="size-7" />
                </span>
                <div class="min-w-0">
                    <h2 class="text-lg font-semibold text-slate-900">Optima Gudang</h2>
                    <p class="text-sm text-slate-500">Versi {{ $app['versionName'] }} (build {{ $app['versionCode'] }}) · Android 8.0 ke atas</p>
                </div>
            </div>

            <div class="mt-5 flex flex-wrap gap-2">
                <a href="{{ $app['url'] }}" class="btn-primary" rel="nofollow" data-no-loading><x-heroicon-o-arrow-down-tray class="size-5" /> Download APK</a>
            </div>

            @if ($app['changelog'])
                <div class="mt-5 rounded-xl border border-slate-200 p-4">
                    <p class="text-sm font-semibold text-slate-900">Yang baru di versi {{ $app['versionName'] }}</p>
                    <p class="mt-1 whitespace-pre-line text-sm text-slate-600">{{ $app['changelog'] }}</p>
                </div>
            @endif

            <h3 class="mt-8 text-sm font-semibold text-slate-900">Cara pasang</h3>
            <ol class="mt-3 space-y-3 text-sm text-slate-600">
                <li class="flex gap-3"><span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-700">1</span><span>Buka halaman ini di HP, atau scan QR di samping, lalu tekan <b>Download APK</b>.</span></li>
                <li class="flex gap-3"><span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-700">2</span><span>Buka file <span class="font-mono">optima-gudang.apk</span>. Jika muncul peringatan, izinkan <b>Instal aplikasi tidak dikenal</b> untuk browser/File Manager.</span></li>
                <li class="flex gap-3"><span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-700">3</span><span>Buka aplikasi, pilih server <b>{{ request()->getHost() }}</b>, lalu login dengan akun Optima yang sama.</span></li>
                <li class="flex gap-3"><span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-700">4</span><span>Izinkan kamera (scan & foto) dan Bluetooth (printer) saat diminta.</span></li>
            </ol>

            <div class="mt-6 rounded-xl bg-slate-50 p-4 text-sm text-slate-600">
                <b class="text-slate-800">Menu ikut hak akses.</b>
                Aplikasi hanya bisa dipakai akun yang role-nya punya hak <b>Aplikasi Android</b>. Menu di dalamnya (QC, serah terima, penerimaan, opname, retur, cetak label) tampil sesuai hak role masing-masing. Atur di Pengaturan → Role &amp; Permission.
            </div>
        </div>

        <div class="card flex flex-col items-center p-6 text-center">
            @if ($qr)
                <div class="rounded-xl bg-white p-3 [&_svg]:size-56">{!! $qr !!}</div>
            @endif
            <p class="mt-3 text-sm text-slate-500">Scan dengan kamera HP untuk download langsung.</p>
            <p class="mt-1 text-xs text-slate-400">Update berikutnya ditawarkan otomatis di dalam aplikasi dan bisa dipasang langsung dari sana.</p>
        </div>
    </div>

    @can('mobile.releases')
        <div class="card mt-5">
            <div class="border-b border-slate-100 px-5 py-3">
                <h2 class="text-base font-semibold text-slate-900">Versi Aplikasi</h2>
                <p class="mt-0.5 text-sm text-slate-500">Versi dengan nomor build tertinggi ditawarkan ke HP. Versi wajib memblokir aplikasi lama sampai update dipasang.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                        <tr>
                            <th class="h-8 px-4 py-0 font-normal">Versi</th>
                            <th class="h-8 px-4 py-0 font-normal">Catatan</th>
                            <th class="h-8 px-4 py-0 text-right font-normal">Ukuran</th>
                            <th class="h-8 px-4 py-0 font-normal">Diterbitkan</th>
                            <th class="h-8 w-24 px-4 py-0"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($releases as $release)
                            <tr class="border-t border-slate-100 align-top hover:bg-slate-50/60">
                                <td class="px-4 py-2.5 whitespace-nowrap">
                                    <span class="font-medium text-slate-900">{{ $release->version_name }}</span>
                                    <span class="text-xs text-slate-400">build {{ $release->version_code }}</span>
                                    <div class="mt-1 flex gap-1">
                                        @if ($loop->first)
                                            <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">Terbaru</span>
                                        @endif
                                        @if ($release->is_mandatory)
                                            <span class="rounded-full bg-rose-50 px-2 py-0.5 text-xs font-medium text-rose-700">Wajib</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="max-w-md px-4 py-2.5 text-slate-600"><p class="line-clamp-3 whitespace-pre-line">{{ $release->changelog ?: '-' }}</p></td>
                                <td class="px-4 py-2.5 text-right whitespace-nowrap text-slate-600 tabular-nums">{{ \Illuminate\Support\Number::fileSize($release->file_size, 1) }}</td>
                                <td class="px-4 py-2.5 whitespace-nowrap text-slate-700">
                                    {{ $release->created_at->format('d/m/Y H:i') }}
                                    <p class="text-xs text-slate-400">{{ $release->creator?->name ?? '-' }} · {{ $release->source === 'github' ? 'GitHub' : 'Upload' }}</p>
                                </td>
                                <td class="px-4 py-2.5">
                                    <div class="flex justify-end gap-1">
                                        <a href="{{ \App\Support\MobileApp::downloadUrl($release) }}" class="btn-icon" title="Unduh" data-no-loading><x-heroicon-o-arrow-down-tray class="size-5" /></a>
                                        <a href="{{ route('mobile.releases.edit', $release) }}" class="btn-icon" title="Ubah"><x-heroicon-o-pencil-square class="size-5" /></a>
                                        <form id="delete-release-{{ $release->id }}" method="POST" action="{{ route('mobile.releases.destroy', $release) }}">
                                            @csrf @method('DELETE')
                                        </form>
                                        <button type="button" class="btn-icon hover:bg-rose-50 hover:text-rose-600" title="Hapus"
                                                @click="$dispatch('confirm', {
                                                    form: 'delete-release-{{ $release->id }}',
                                                    title: 'Hapus versi {{ $release->version_name }}?',
                                                    message: 'File APK dihapus dari server. HP yang belum memasangnya akan ditawari versi sebelumnya.',
                                                    confirmText: 'Hapus',
                                                })">
                                            <x-heroicon-o-trash class="size-5" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <x-empty-state colspan="5" icon="heroicon-o-device-phone-mobile" title="Belum ada versi yang diunggah" description="Saat ini tombol download memakai APK dari GitHub Release. Upload versi supaya HP bisa update dari dalam aplikasi." />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endcan
</x-layouts.app>
