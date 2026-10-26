<x-layouts.app title="Master Kurir">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span>Master Data</span>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Kurir</span>
    </x-slot:breadcrumb>

    <x-page-header title="Master Kurir" description="Ekspedisi pengiriman beserta jenis layanannya." icon="heroicon-o-truck">
        <x-reports.export-button />
        @can('couriers.create')
            <a href="{{ route('master.couriers.create') }}" class="btn-primary">
                <x-heroicon-o-plus class="size-5" /> Tambah Kurir
            </a>
        @endcan
    </x-page-header>

    <div class="card">
        <form method="GET" class="flex flex-col gap-3 border-b border-slate-100 p-4 md:flex-row md:items-center">
            <div class="relative flex-1">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-slate-400" />
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari kode atau nama kurir..." class="form-input pl-8">
            </div>
            <select name="status" class="form-input md:w-40" onchange="this.form.submit()">
                <option value="">Semua status</option>
                <option value="active" @selected(request('status') === 'active')>Aktif</option>
                <option value="inactive" @selected(request('status') === 'inactive')>Nonaktif</option>
            </select>
            <div class="flex gap-2">
                <button type="submit" class="btn-secondary"><x-heroicon-o-funnel class="size-5" /> Filter</button>
                @if (request()->hasAny(['search', 'status']))
                    <a href="{{ route('master.couriers.index') }}" class="btn-icon" title="Reset filter"><x-heroicon-o-x-mark class="size-5" /></a>
                @endif
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                    <tr>
                        <th class="h-8 px-4 py-0 font-normal">Kurir</th>
                        <th class="h-8 px-4 py-0 font-normal"><span class="whitespace-nowrap">Layanan <x-hint text="Jenis layanan kurir yang tersedia, misalnya REG atau YES." /></span></th>
                        <th class="h-8 px-4 py-0 font-normal">Batas <span class="whitespace-nowrap">Pickup <x-hint text="Jam terakhir kurir menjemput. Pesanan setelah jam ini dikirim hari berikutnya." /></span></th>
                        <th class="h-8 px-4 py-0 font-normal">Kontak</th>
                        <th class="h-8 px-4 py-0 font-normal"><span class="whitespace-nowrap">Status <x-hint text="Data nonaktif tidak bisa dipilih di transaksi baru, tapi riwayatnya tetap tersimpan." /></span></th>
                        <th class="h-8 px-4 py-0 font-normal text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($couriers as $courier)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-4 py-2.5">
                                <p class="font-medium text-slate-900">{{ $courier->name }}</p>
                                <p class="font-mono text-xs text-slate-500">{{ $courier->code }}</p>
                            </td>
                            <td class="px-4 py-2.5">
                                <div class="flex flex-wrap gap-1.5">
                                    @forelse ($courier->services ?? [] as $service)
                                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-700" title="{{ $service['name'] }}">
                                            {{ $service['code'] }}
                                        </span>
                                    @empty
                                        <span class="text-xs text-slate-400">—</span>
                                    @endforelse
                                </div>
                            </td>
                            <td class="px-4 py-2.5 text-slate-600">{{ $courier->pickup_cutoff ? substr($courier->pickup_cutoff, 0, 5) : '—' }}</td>
                            <td class="px-4 py-2.5 text-slate-600">
                                {{ $courier->contact_name ?? '—' }}
                                @if ($courier->contact_phone)
                                    <p class="text-xs text-slate-400">{{ $courier->contact_phone }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-2.5"><x-status-badge :active="$courier->is_active" /></td>
                            <td class="px-4 py-2.5">
                                <div class="flex items-center justify-end gap-1">
                                    @can('couriers.edit')
                                        <a href="{{ route('master.couriers.edit', $courier) }}" class="btn-icon" title="Edit">
                                            <x-heroicon-o-pencil-square class="size-5" />
                                        </a>
                                    @endcan
                                    @can('couriers.delete')
                                        <form id="delete-{{ $courier->id }}" method="POST" action="{{ route('master.couriers.destroy', $courier) }}">
                                            @csrf @method('DELETE')
                                        </form>
                                        <button type="button" class="btn-icon hover:bg-rose-50 hover:text-rose-600" title="Hapus"
                                                @click="$dispatch('confirm', {
                                                    form: 'delete-{{ $courier->id }}',
                                                    title: 'Hapus kurir?',
                                                    message: @js("Kurir {$courier->name} akan dihapus permanen."),
                                                    confirmText: 'Hapus',
                                                })">
                                            <x-heroicon-o-trash class="size-5" />
                                        </button>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <x-empty-state colspan="6" icon="heroicon-o-truck" title="Belum ada kurir" description="Tambahkan ekspedisi yang dipakai untuk pengiriman.">
                            @can('couriers.create')
                                <a href="{{ route('master.couriers.create') }}" class="btn-primary"><x-heroicon-o-plus class="size-5" /> Tambah Kurir</a>
                            @endcan
                        </x-empty-state>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($couriers->hasPages())
            <div class="border-t border-slate-100 px-5 py-4">{{ $couriers->links() }}</div>
        @endif
    </div>
</x-layouts.app>
