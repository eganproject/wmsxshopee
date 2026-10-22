<x-layouts.app title="Log Aktivitas">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('settings') }}" class="hover:text-slate-700">Pengaturan</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Log Aktivitas</span>
    </x-slot:breadcrumb>

    <x-page-header title="Log Aktivitas" description="Siapa mengubah apa dan kapan, lengkap dengan nilai sebelum dan sesudah." icon="heroicon-o-clock" />

    <div class="card">
        <form method="GET" class="space-y-3 border-b border-slate-100 p-4">
            <div class="flex gap-2">
                <div class="relative flex-1">
                    <x-heroicon-o-magnifying-glass class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-slate-400" />
                    <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari no. dokumen, kode, nama atau IP..." class="form-input pl-8">
                </div>
                <button type="submit" class="btn-secondary"><x-heroicon-o-funnel class="size-5" /> Filter</button>
                @if (request()->hasAny(['search', 'user', 'module', 'event', 'from', 'to']))
                    <a href="{{ route('admin.activity.index') }}" class="btn-icon" title="Reset filter"><x-heroicon-o-x-mark class="size-5" /></a>
                @endif
            </div>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <select name="user" class="form-input" onchange="this.form.submit()">
                    <option value="">Semua user</option>
                    @foreach ($users as $user)
                        <option value="{{ $user->id }}" @selected(request('user') == $user->id)>{{ $user->name }}</option>
                    @endforeach
                </select>
                <select name="module" class="form-input" onchange="this.form.submit()">
                    <option value="">Semua modul</option>
                    @foreach ($modules as $value => $label)
                        <option value="{{ $value }}" @selected(request('module') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <select name="event" class="form-input" onchange="this.form.submit()">
                    <option value="">Semua aktivitas</option>
                    @foreach ($events as $value => $meta)
                        <option value="{{ $value }}" @selected(request('event') === $value)>{{ $meta['label'] }}</option>
                    @endforeach
                </select>
                <input type="date" name="from" value="{{ request('from') }}" class="form-input" title="Dari tanggal" placeholder="Dari tanggal">
                <input type="date" name="to" value="{{ request('to') }}" class="form-input" title="Sampai tanggal" placeholder="Sampai tanggal">
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                    <tr>
                        <th class="h-8 px-4 py-0 font-normal">Waktu</th>
                        <th class="h-8 px-4 py-0 font-normal">User</th>
                        <th class="h-8 px-4 py-0 font-normal">Modul</th>
                        <th class="h-8 px-4 py-0 font-normal">Aktivitas</th>
                        <th class="h-8 px-4 py-0 font-normal">IP</th>
                        <th class="h-8 w-10 px-4 py-0"></th>
                    </tr>
                </thead>
                @forelse ($logs as $log)
                    @php($url = $log->subjectUrl())
                    <tbody x-data="{ open: false }" class="border-t border-slate-100">
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-4 py-2.5 whitespace-nowrap">
                                <p class="text-slate-900">{{ $log->created_at->format('d/m/Y') }}</p>
                                <p class="text-xs text-slate-500 tabular-nums">{{ $log->created_at->format('H:i:s') }}</p>
                            </td>
                            <td class="px-4 py-2.5 whitespace-nowrap text-slate-700">
                                {{ $log->user?->name ?? $log->user_name ?? 'Sistem' }}
                                @if ($log->user?->username)
                                    <p class="text-xs text-slate-400">{{ $log->user->username }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 whitespace-nowrap text-slate-600">{{ $log->moduleLabel() }}</td>
                            <td class="px-4 py-2.5">
                                <div class="flex flex-wrap items-center gap-2">
                                    <x-activity.event-badge :event="$log->event" />
                                    @if ($url)
                                        <a href="{{ $url }}" class="text-slate-900 hover:text-brand-600 hover:underline">{{ $log->description }}</a>
                                    @else
                                        <span class="text-slate-900">{{ $log->description }}</span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-4 py-2.5 font-mono text-xs whitespace-nowrap text-slate-500" title="{{ $log->user_agent }}">{{ $log->ip_address ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-right">
                                @if ($log->hasDiff())
                                    <button type="button" class="btn-icon" @click="open = !open" :aria-expanded="open" title="Lihat perubahan">
                                        <x-heroicon-o-chevron-down class="size-4 transition" ::class="open && 'rotate-180'" />
                                    </button>
                                @endif
                            </td>
                        </tr>
                        @if ($log->hasDiff())
                            <tr x-cloak x-show="open">
                                <td colspan="6" class="bg-slate-50/60 px-4 pt-1 pb-3">
                                    <x-activity.changes :log="$log" />
                                </td>
                            </tr>
                        @endif
                    </tbody>
                @empty
                    <tbody>
                        <x-empty-state colspan="6" icon="heroicon-o-clock" title="Belum ada aktivitas" description="Aktivitas akan tercatat otomatis saat user login atau mengubah data." />
                    </tbody>
                @endforelse
            </table>
        </div>

        @if ($logs->hasPages())
            <div class="border-t border-slate-100 px-5 py-4">{{ $logs->links() }}</div>
        @endif
    </div>
</x-layouts.app>
