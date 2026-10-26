{{--
    Halaman impor Excel/CSV bersama. Variabel:
    $title, $description, $section (breadcrumb induk), $back (url kembali), $backLabel,
    $action (url POST), $template (fn(format) => url), $columns, $note, $fields (partial view opsional).
    Hasil impor dibaca dari session('import_summary'): [['label', 'value', 'items' => [], 'tone']].
--}}
@php($summary = session('import_summary'))

<x-layouts.app :title="$title">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ $back }}" class="hover:text-slate-700">{{ $backLabel }}</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Impor</span>
    </x-slot:breadcrumb>

    <x-page-header :title="$title" :description="$description" icon="heroicon-o-arrow-up-tray">
        <a href="{{ $back }}" class="btn-secondary"><x-heroicon-o-arrow-left class="size-5" /> Kembali</a>
    </x-page-header>

    @if ($errors->has('rows'))
        <div class="mb-6 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
            <p class="flex items-center gap-1.5 font-medium"><x-heroicon-o-exclamation-triangle class="size-4" /> File belum diimpor. Perbaiki baris berikut lalu unggah lagi:</p>
            <ul class="mt-2 list-disc space-y-0.5 pl-6">
                @foreach ($errors->get('rows') as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($summary)
        <div class="card mb-6 p-5 text-sm">
            <h2 class="flex items-center gap-2 text-base font-semibold text-slate-900"><x-heroicon-o-clipboard-document-check class="size-5 text-slate-400" /> Hasil Impor</h2>
            <div class="mt-3 grid gap-4 sm:grid-cols-3">
                @foreach ($summary as $stat)
                    <div>
                        <p class="text-xs text-slate-500">{{ $stat['label'] }}</p>
                        <p @class(['mt-1 text-lg font-semibold', 'text-emerald-600' => ($stat['tone'] ?? null) === 'success', 'text-slate-700' => ($stat['tone'] ?? null) !== 'success'])>{{ $stat['value'] }}</p>
                        @if (! empty($stat['items']))
                            <p class="mt-1 font-mono text-xs text-slate-600">{{ implode(', ', array_slice($stat['items'], 0, 20)) }}{{ count($stat['items']) > 20 ? ', …' : '' }}</p>
                        @endif
                        @if (! empty($stat['link']))
                            <a href="{{ $stat['link'] }}" class="mt-1 inline-block text-xs font-medium text-brand-600 hover:underline">{{ $stat['linkLabel'] ?? 'Lihat' }}</a>
                        @endif
                    </div>
                @endforeach
            </div>
            <a href="{{ $back }}" class="btn-secondary mt-4">{{ $backLabel }}</a>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)]">
        <form method="POST" action="{{ $action }}" enctype="multipart/form-data" class="card"
              data-confirm-title="Impor file ini?" data-confirm="Semua baris dicek dulu. Jika ada yang salah, tidak ada data yang disimpan dan baris yang perlu diperbaiki ditampilkan." data-confirm-text="Impor">
            @csrf
            <div class="border-b border-slate-100 px-5 py-3">
                <h2 class="flex items-center gap-2 text-base font-semibold text-slate-900"><x-heroicon-o-document-arrow-up class="size-5 text-slate-400" /> Unggah File</h2>
            </div>
            <div class="space-y-4 p-5">
                @isset($fields)
                    @include($fields)
                @endisset
                <div>
                    <label for="file" class="form-label">File <span class="text-rose-500">*</span> <x-hint text="File .xlsx atau .csv sesuai Format Kolom di samping. Semua baris dicek dulu; bila ada yang salah, tidak ada yang disimpan." /></label>
                    <input id="file" name="file" type="file" required accept=".xlsx,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                           @class(['form-input file:mr-3 file:border-0 file:bg-transparent file:text-sm file:font-medium', 'form-input-error' => $errors->has('file')])>
                    @error('file')
                        <p class="mt-1 flex items-center gap-1 text-xs text-rose-600"><x-heroicon-o-exclamation-triangle class="size-4 shrink-0" /> {{ $message }}</p>
                    @else
                        <p class="mt-1 text-xs text-slate-400">.xlsx atau .csv (pemisah koma atau titik koma), maks. 10 MB / {{ number_format(\App\Support\SpreadsheetImport::MAX_ROWS, 0, ',', '.') }} baris.</p>
                    @enderror
                </div>
                <button type="submit" class="btn-primary">
                    <x-heroicon-o-arrow-up-tray class="size-5" /> Impor
                </button>
            </div>
        </form>

        <div class="card">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-5 py-3">
                <h2 class="flex items-center gap-2 text-base font-semibold text-slate-900"><x-heroicon-o-table-cells class="size-5 text-slate-400" /> Format Kolom</h2>
                <div class="flex gap-2">
                    <a href="{{ $template('xlsx') }}" class="btn-secondary" data-no-loading><x-heroicon-o-arrow-down-tray class="size-5" /> Template Excel</a>
                    <a href="{{ $template('csv') }}" class="btn-secondary" data-no-loading><x-heroicon-o-arrow-down-tray class="size-5" /> CSV</a>
                </div>
            </div>
            <table class="min-w-full text-sm">
                <tbody class="divide-y divide-slate-100">
                    @foreach ($columns as $column => $text)
                        <tr>
                            <td class="px-5 py-2 font-mono text-slate-900">{{ $column }}</td>
                            <td class="px-5 py-2 text-slate-600">{{ $text }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="border-t border-slate-100 px-5 py-3 text-xs text-slate-500">{{ $note }}</p>
        </div>
    </div>
</x-layouts.app>
