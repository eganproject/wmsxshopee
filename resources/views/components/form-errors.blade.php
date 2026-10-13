{{-- Ringkasan error validasi di atas halaman, supaya error di baris tabel / bagian bawah form tidak terlewat. --}}
@props(['bag' => 'default', 'limit' => 6])

@php
    $messages = collect($errors->getBag($bag)->all())->unique()->values();
@endphp

@if ($messages->isNotEmpty())
    <div {{ $attributes->class('mb-5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700') }} role="alert" data-error-summary>
        <p class="flex items-center gap-2 font-medium">
            <x-heroicon-o-exclamation-triangle class="size-5 shrink-0" />
            {{ $messages->count() === 1 ? 'Data belum bisa disimpan' : "Ada {$messages->count()} hal yang perlu diperbaiki" }}
        </p>
        <ul class="mt-1.5 space-y-0.5 pl-7">
            @foreach ($messages->take($limit) as $message)
                <li class="list-disc">{{ $message }}</li>
            @endforeach
            @if ($messages->count() > $limit)
                <li class="list-none text-rose-600">dan {{ $messages->count() - $limit }} lainnya, lihat isian yang ditandai merah.</li>
            @endif
        </ul>
    </div>
@endif
