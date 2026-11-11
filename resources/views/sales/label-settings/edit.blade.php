<x-layouts.app title="Pengaturan Cetak Resi">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('settings') }}" class="hover:text-slate-700">Pengaturan</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Cetak Resi</span>
    </x-slot:breadcrumb>

    <x-page-header title="Pengaturan Cetak Resi" description="QR berisi nomor resi (AWB) yang ikut tercetak di label, supaya resi bisa di-scan dengan kamera HP atau scanner 2D." icon="heroicon-o-qr-code" />

    @php
        $size = (int) old('qr_size_mm', $setting->qr_size_mm);
        $platforms = collect(\App\Models\LabelSetting::PLATFORMS)->mapWithKeys(fn ($label, $key) => [$key => [
            'on' => (bool) old("{$key}_qr", $setting->{"{$key}_qr"}),
            'position' => old("{$key}_qr_position", $setting->{"{$key}_qr_position"}),
            'x' => (float) old("{$key}_qr_margin_x_mm", $setting->{"{$key}_qr_margin_x_mm"}),
            'y' => (float) old("{$key}_qr_margin_y_mm", $setting->{"{$key}_qr_margin_y_mm"}),
        ]]);
    @endphp

    <form method="POST" action="{{ route('sales.label-settings.update') }}" data-dirty-guard class="max-w-4xl space-y-6"
          x-data="{ enabled: @js((bool) old('qr_enabled', $setting->qr_enabled)), size: @js($size), p: @js($platforms) }">
        @csrf @method('PUT')

        <div class="card flex items-center justify-between gap-4 px-5 py-4">
            <div>
                <p class="font-medium text-slate-900">QR nomor resi</p>
                <p class="text-sm text-slate-500">Matikan untuk mencetak label persis seperti dari marketplace.</p>
            </div>
            <label class="relative inline-flex cursor-pointer items-center">
                <input type="hidden" name="qr_enabled" value="0">
                <input type="checkbox" name="qr_enabled" value="1" class="peer sr-only" x-model="enabled">
                <span class="h-5 w-9 rounded-full bg-slate-300 transition peer-checked:bg-slate-900"></span>
                <span class="absolute left-0.5 size-4 rounded-full bg-white shadow-sm transition peer-checked:translate-x-4"></span>
            </label>
        </div>

        <div class="space-y-6" :class="!enabled && 'pointer-events-none opacity-50'">
            <div class="card">
                <div class="border-b border-slate-100 px-5 py-3">
                    <h2 class="text-base font-semibold text-slate-900">Ukuran QR</h2>
                </div>
                <div class="grid gap-x-6 gap-y-4 p-5 sm:grid-cols-2">
                    <x-input name="qr_size_mm" type="number" min="10" max="40" step="1" label="Sisi QR (mm)" icon="heroicon-o-arrows-pointing-out"
                             :value="$size" x-model.number="size" required
                             hint="Termasuk bingkai putih di sekelilingnya. 16–20 mm mudah di-scan di label 100 x 150 mm." />
                    <label class="flex items-start gap-2 pt-6 text-sm text-slate-700">
                        <input type="hidden" name="manual_qr" value="0">
                        <input type="checkbox" name="manual_qr" value="1" class="mt-0.5 rounded border-slate-300" @checked(old('manual_qr', $setting->manual_qr))>
                        <span>Tampilkan QR di label pesanan manual <span class="block text-xs text-slate-400">Diletakkan di samping barcode resi.</span></span>
                    </label>
                </div>
            </div>

            @foreach (\App\Models\LabelSetting::PLATFORMS as $key => $label)
                <div class="card">
                    <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3">
                        <h2 class="text-base font-semibold text-slate-900">Label {{ $label }}</h2>
                        <label class="flex items-center gap-2 text-sm text-slate-700">
                            <input type="hidden" name="{{ $key }}_qr" value="0">
                            <input type="checkbox" name="{{ $key }}_qr" value="1" class="rounded border-slate-300" x-model="p.{{ $key }}.on">
                            Tempel QR
                        </label>
                    </div>
                    <div class="grid gap-6 p-5 sm:grid-cols-[1fr_auto]" :class="!p.{{ $key }}.on && 'opacity-50'">
                        <div class="space-y-4">
                            <x-select name="{{ $key }}_qr_position" label="Posisi" x-model="p.{{ $key }}.position" required>
                                @foreach (\App\Models\LabelSetting::POSITIONS as $value => $text)
                                    <option value="{{ $value }}" @selected($platforms[$key]['position'] === $value)>{{ $text }}</option>
                                @endforeach
                            </x-select>
                            <div class="grid grid-cols-2 gap-4">
                                <x-input name="{{ $key }}_qr_margin_x_mm" type="number" min="0" max="100" step="0.5" label="Jarak dari tepi samping (mm)"
                                         :value="$platforms[$key]['x']" x-model.number="p.{{ $key }}.x" required />
                                <x-input name="{{ $key }}_qr_margin_y_mm" type="number" min="0" max="140" step="0.5" label="Jarak dari tepi atas/bawah (mm)"
                                         :value="$platforms[$key]['y']" x-model.number="p.{{ $key }}.y" required />
                            </div>
                            <p class="text-xs text-slate-500">
                                QR diberi latar putih dan menutupi apa pun di bawahnya, jadi pilih area kosong di label {{ $label }}.
                                Cetak satu resi untuk mengecek posisinya, lalu geser dengan jarak di atas bila perlu.
                            </p>
                        </div>
                        {{-- Pratinjau posisi pada label 100 x 150 mm --}}
                        <div class="relative mx-auto h-[150px] w-[100px] rounded-sm border border-slate-300" style="background: #fff;" aria-hidden="true">
                            <div class="absolute inset-x-2 top-2 h-1.5 rounded bg-slate-200"></div>
                            <div class="absolute inset-x-2 top-5 h-5 rounded bg-slate-300"></div>
                            <div class="absolute inset-x-2 top-12 space-y-1"><div class="h-1 rounded bg-slate-200"></div><div class="h-1 w-3/4 rounded bg-slate-200"></div><div class="h-1 w-1/2 rounded bg-slate-200"></div></div>
                            <div class="absolute" style="background: #111;" x-show="p.{{ $key }}.on"
                                 x-bind:style="{
                                    background: '#111',
                                    width: size + 'px', height: size + 'px',
                                    [p.{{ $key }}.position.endsWith('left') ? 'left' : 'right']: p.{{ $key }}.x + 'px',
                                    [p.{{ $key }}.position.startsWith('top') ? 'top' : 'bottom']: p.{{ $key }}.y + 'px',
                                 }"></div>
                            <span class="absolute -bottom-5 inset-x-0 text-center text-[10px] text-slate-400">100 x 150 mm</span>
                        </div>
                    </div>
                </div>
            @endforeach

            <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
                <p class="font-medium text-slate-800">Cara kerjanya</p>
                <ul class="mt-1 list-disc space-y-1 pl-5">
                    <li>Label Shopee & TikTok tetap PDF resmi dari marketplace (barcode, kode sortir dan isinya tidak diubah). Optima hanya menempelkan QR di atasnya saat Cetak Resi dari komputer.</li>
                    <li>QR hanya ditempel di halaman yang memuat nomor resinya. Halaman yang tidak bisa dicocokkan dibiarkan tanpa QR dan disebutkan di halaman cetak.</li>
                    <li>Cetak langsung dari aplikasi Android ke printer Bluetooth belum memakai QR ini.</li>
                </ul>
            </div>
        </div>

        <div class="sticky bottom-0 flex justify-end gap-2 border-t border-slate-200 bg-white/90 py-3 backdrop-blur">
            <button type="submit" class="btn-primary"><x-heroicon-o-check class="size-5" /> Simpan Pengaturan</button>
        </div>
    </form>
</x-layouts.app>
