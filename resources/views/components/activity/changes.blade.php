@props(['log'])

{{-- Tabel nilai sebelum → sesudah. Kolom berisi daftar (item, varian, permission)
     ditampilkan per baris: yang dibuang merah, yang ditambah hijau. --}}
@php
    $isCreate = $log->event === 'created';
    $isDelete = $log->event === 'deleted';
    $format = fn ($value) => $value === null || $value === '' ? '—' : (is_bool($value) ? ($value ? 'Ya' : 'Tidak') : $value);
@endphp

<div {{ $attributes->class(['overflow-x-auto rounded-lg ring-1 ring-slate-200']) }}>
    <table class="min-w-full text-[13px]">
        <thead class="bg-slate-50 text-left text-xs text-slate-500">
            <tr>
                <th class="h-7 w-44 px-3 py-0 font-normal">Kolom</th>
                @unless ($isCreate)
                    <th class="h-7 px-3 py-0 font-normal">{{ $isDelete ? 'Nilai terakhir' : 'Sebelum' }}</th>
                @endunless
                @unless ($isDelete)
                    <th class="h-7 px-3 py-0 font-normal">{{ $isCreate ? 'Nilai' : 'Sesudah' }}</th>
                @endunless
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 bg-white align-top">
            @foreach ($log->diffRows() as $change)
                @php
                    $isList = is_array($change['old']) || is_array($change['new']);
                    $oldList = (array) ($change['old'] ?? []);
                    $newList = (array) ($change['new'] ?? []);
                @endphp
                <tr>
                    <td class="px-3 py-1.5 text-slate-500">{{ $change['field'] }}</td>
                    @if ($isList)
                        @unless ($isCreate)
                            <td class="px-3 py-1.5">
                                @forelse ($oldList as $line)
                                    <p @class(['font-mono text-xs', 'text-rose-600 line-through decoration-rose-300' => ! $isDelete && ! in_array($line, $newList, true), 'text-slate-600' => $isDelete || in_array($line, $newList, true)])>{{ $line }}</p>
                                @empty
                                    <span class="text-slate-400">—</span>
                                @endforelse
                            </td>
                        @endunless
                        @unless ($isDelete)
                            <td class="px-3 py-1.5">
                                @forelse ($newList as $line)
                                    <p @class(['font-mono text-xs', 'text-emerald-700' => ! $isCreate && ! in_array($line, $oldList, true), 'text-slate-600' => $isCreate || in_array($line, $oldList, true)])>{{ $line }}</p>
                                @empty
                                    <span class="text-slate-400">—</span>
                                @endforelse
                            </td>
                        @endunless
                    @else
                        @unless ($isCreate)
                            <td @class(['px-3 py-1.5 break-words whitespace-pre-line', 'text-rose-600' => ! $isDelete, 'text-slate-700' => $isDelete])>{{ $format($change['old']) }}</td>
                        @endunless
                        @unless ($isDelete)
                            <td @class(['px-3 py-1.5 break-words whitespace-pre-line', 'text-emerald-700' => ! $isCreate, 'text-slate-700' => $isCreate])>{{ $format($change['new']) }}</td>
                        @endunless
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
