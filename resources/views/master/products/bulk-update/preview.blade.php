@php
    $number = fn ($value) => number_format($value, 0, ',', '.');
    $stats = [
        ['label' => 'Baris di file', 'value' => $analysis['rows'], 'icon' => 'heroicon-o-table-cells'],
        ['label' => 'Produk berubah', 'value' => $analysis['products'], 'icon' => 'heroicon-o-cube', 'tone' => 'brand'],
        ['label' => 'Nilai diubah', 'value' => $analysis['changed'], 'icon' => 'heroicon-o-pencil-square', 'tone' => 'brand'],
    ];
@endphp

<x-layouts.app title="Pratinjau Update Massal">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('master.products.index') }}" class="hover:text-slate-700">Master Produk</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('master.products.bulk-update') }}" class="hover:text-slate-700">Update Massal</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Pratinjau</span>
    </x-slot:breadcrumb>

    <x-page-header title="Pratinjau Perubahan" :description="'File '.$file.' sudah dicek. Belum ada data yang disimpan.'" icon="heroicon-o-document-magnifying-glass" />

    <div class="mx-auto max-w-5xl space-y-6">
        <div class="grid gap-4 sm:grid-cols-3">
            @foreach ($stats as $stat)
                <div class="card flex items-center gap-3 p-4">
                    <span @class([
                        'flex size-10 shrink-0 items-center justify-center rounded-lg',
                        'bg-brand-50 text-brand-600' => ($stat['tone'] ?? null) === 'brand' && $stat['value'],
                        'bg-slate-100 text-slate-500' => ($stat['tone'] ?? null) !== 'brand' || ! $stat['value'],
                    ])>
                        <x-dynamic-component :component="$stat['icon']" class="size-5" />
                    </span>
                    <div>
                        <p class="text-xs text-slate-500">{{ $stat['label'] }}</p>
                        <p class="text-lg font-semibold text-slate-900">{{ $number($stat['value']) }}</p>
                    </div>
                </div>
            @endforeach
        </div>

        @if ($analysis['ignored'])
            <div class="flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                <x-heroicon-o-exclamation-triangle class="size-5 shrink-0" />
                <p>Kolom berikut tidak dikenali dan <strong class="font-medium">diabaikan</strong>: <span class="font-mono text-xs">{{ implode(', ', $analysis['ignored']) }}</span>. Pastikan judul kolom sesuai template bila kolom itu ingin diubah.</p>
            </div>
        @endif

        <section class="card">
            <div class="flex flex-col gap-3 border-b border-slate-100 px-5 py-4">
                <div>
                    <h2 class="font-semibold text-slate-900">Daftar perubahan</h2>
                    <p class="text-sm text-slate-500">Hanya nilai yang berbeda dari data saat ini yang ditampilkan dan akan disimpan.</p>
                </div>
                <div class="flex flex-wrap gap-1.5">
                    <a href="{{ route('master.products.bulk-update.preview') }}"
                       @class(['rounded-full px-2.5 py-1 text-xs font-medium ring-1 transition', 'bg-slate-900 text-white ring-slate-900' => ! $field, 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50' => $field])>
                        Semua kolom · {{ $number($analysis['changed']) }}
                    </a>
                    @foreach ($analysis['counts'] as $key => $count)
                        <a href="{{ route('master.products.bulk-update.preview', ['field' => $key]) }}"
                           @class([
                               'rounded-full px-2.5 py-1 text-xs font-medium ring-1 transition',
                               'bg-slate-900 text-white ring-slate-900' => $field === $key,
                               'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50' => $field !== $key && $count,
                               'bg-white text-slate-400 ring-slate-100' => $field !== $key && ! $count,
                           ])>
                            {{ $fields[$key]['label'] }} · {{ $number($count) }}
                        </a>
                    @endforeach
                </div>
            </div>

            @if ($changes->isEmpty())
                <div class="flex flex-col items-center gap-2 px-5 py-12 text-center">
                    <span class="flex size-11 items-center justify-center rounded-full bg-slate-100 text-slate-400"><x-heroicon-o-check class="size-6" /></span>
                    <p class="font-medium text-slate-900">{{ $field ? 'Tidak ada perubahan di kolom ini' : 'Tidak ada perubahan' }}</p>
                    <p class="max-w-md text-sm text-slate-500">{{ $field ? 'Semua nilai di kolom ini sama dengan data saat ini.' : 'Semua nilai di file sama dengan data saat ini, jadi tidak ada yang perlu disimpan.' }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                            <tr>
                                <th class="px-5 py-3">SKU</th>
                                <th class="px-5 py-3">Kolom</th>
                                <th class="px-5 py-3">Sebelum</th>
                                <th class="w-6 px-0 py-3"></th>
                                <th class="px-5 py-3">Sesudah</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($changes as $change)
                                <tr class="align-top">
                                    <td class="cell-wrap px-5 py-2.5">
                                        <p class="font-mono text-xs text-slate-900">{{ $change['sku'] }}</p>
                                        <p class="text-xs text-slate-500">{{ $change['name'] }}</p>
                                    </td>
                                    <td class="px-5 py-2.5 whitespace-nowrap text-slate-600">{{ $fields[$change['field']]['label'] }}</td>
                                    <td class="cell-wrap px-5 py-2.5">
                                        @if ($change['old'] === null)
                                            <span class="text-xs text-slate-400 italic">kosong</span>
                                        @else
                                            <span class="text-slate-500 line-through decoration-slate-300">{{ \Illuminate\Support\Str::limit($change['old'], 120) }}</span>
                                        @endif
                                    </td>
                                    <td class="px-0 py-2.5 text-slate-300"><x-heroicon-o-arrow-right class="size-4" /></td>
                                    <td class="cell-wrap px-5 py-2.5">
                                        @if ($change['new'] === null)
                                            <span class="rounded bg-rose-50 px-1.5 py-0.5 text-xs text-rose-700">dikosongkan</span>
                                        @else
                                            <span class="font-medium text-emerald-700">{{ \Illuminate\Support\Str::limit($change['new'], 120) }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($total > $limit)
                    <p class="border-t border-slate-100 px-5 py-3 text-xs text-slate-500">
                        Menampilkan {{ $number($limit) }} dari {{ $number($total) }} perubahan. Semuanya tetap disimpan saat diterapkan; pilih kolom di atas untuk melihat per kolom.
                    </p>
                @endif
            @endif
        </section>

        <div class="sticky bottom-0 flex flex-col-reverse gap-3 border-t border-slate-200 bg-slate-50/90 py-4 backdrop-blur sm:flex-row sm:items-center sm:justify-end">
            <form method="POST" action="{{ route('master.products.bulk-update.destroy') }}">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn-secondary w-full sm:w-auto">Batal</button>
            </form>
            @if ($analysis['products'])
                <form method="POST" action="{{ route('master.products.bulk-update.apply') }}"
                      data-confirm-title="Terapkan perubahan?" data-confirm-text="Terapkan"
                      data-confirm="{{ $number($analysis['changed']) }} nilai di {{ $number($analysis['products']) }} produk akan diperbarui. Perubahan tercatat di log aktivitas tiap produk.">
                    @csrf
                    <input type="hidden" name="checksum" value="{{ $checksum }}">
                    <button type="submit" class="btn-primary w-full sm:w-auto">
                        <x-heroicon-o-check class="size-5" /> Terapkan {{ $number($analysis['changed']) }} Perubahan
                    </button>
                </form>
            @else
                <a href="{{ route('master.products.bulk-update') }}" class="btn-primary"><x-heroicon-o-arrow-up-tray class="size-5" /> Unggah File Lain</a>
            @endif
        </div>
    </div>
</x-layouts.app>
