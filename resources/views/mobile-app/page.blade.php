<x-layouts.app title="Aplikasi Android">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Aplikasi Android</span>
    </x-slot:breadcrumb>

    <x-page-header title="Aplikasi Android" description="Optima Gudang untuk HP: QC kemas, serah terima kurir, penerimaan, stock opname, retur dan cetak label Bluetooth." />

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
                <a href="{{ $app['url'] }}" class="btn-primary" rel="nofollow"><x-heroicon-o-arrow-down-tray class="size-5" /> Download APK</a>
            </div>

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
            <p class="mt-1 text-xs text-slate-400">Update berikutnya muncul otomatis di beranda aplikasi.</p>
        </div>
    </div>
</x-layouts.app>
