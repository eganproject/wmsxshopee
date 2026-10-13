@php($result = session('import'))

<x-layouts.app title="Impor Pesanan">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('sales.orders.index') }}" class="hover:text-slate-700">Pesanan</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Impor</span>
    </x-slot:breadcrumb>

    <x-page-header title="Impor Pesanan" description="Unggah file Excel (.xlsx) atau CSV. Satu baris = satu barang; no. pesanan yang sama digabung jadi satu pesanan." icon="heroicon-o-arrow-up-tray">
        <a href="{{ route('sales.orders.index') }}" class="btn-secondary"><x-heroicon-o-arrow-left class="size-5" /> Kembali</a>
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

    @if ($result)
        <div class="card mb-6 p-5 text-sm">
            <h2 class="flex items-center gap-2 text-base font-semibold text-slate-900"><x-heroicon-o-clipboard-document-check class="size-5 text-slate-400" /> Hasil Impor</h2>
            <div class="mt-3 grid gap-4 sm:grid-cols-3">
                <div>
                    <p class="text-xs text-slate-500">Pesanan dibuat</p>
                    <p class="mt-1 text-lg font-semibold text-emerald-600">{{ count($result['created']) }}</p>
                    @if ($result['created'])
                        <p class="mt-1 font-mono text-xs text-slate-600">{{ implode(', ', array_slice($result['created'], 0, 20)) }}{{ count($result['created']) > 20 ? ', …' : '' }}</p>
                    @endif
                </div>
                <div>
                    <p class="text-xs text-slate-500">Dilewati (sudah pernah diimpor)</p>
                    <p class="mt-1 text-lg font-semibold text-slate-700">{{ count($result['skipped']) }}</p>
                    @foreach (array_slice($result['skipped'], 0, 10) as $line)
                        <p class="text-xs text-slate-500">{{ $line }}</p>
                    @endforeach
                </div>
                <div>
                    <p class="text-xs text-slate-500">Belum terkonfirmasi (stok kurang)</p>
                    <p @class(['mt-1 text-lg font-semibold', 'text-amber-600' => $result['unconfirmed'], 'text-slate-700' => ! $result['unconfirmed']])>{{ count($result['unconfirmed']) }}</p>
                    @foreach (array_slice($result['unconfirmed'], 0, 10) as $line)
                        <p class="text-xs text-slate-500">{{ $line }}</p>
                    @endforeach
                </div>
            </div>
            <a href="{{ route('sales.orders.index') }}" class="btn-secondary mt-4">Lihat daftar pesanan</a>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)]">
        <form method="POST" action="{{ route('sales.orders.import.store') }}" enctype="multipart/form-data" class="card">
            @csrf
            <div class="border-b border-slate-100 px-5 py-3">
                <h2 class="flex items-center gap-2 text-base font-semibold text-slate-900"><x-heroicon-o-document-arrow-up class="size-5 text-slate-400" /> Unggah File</h2>
            </div>
            <div class="space-y-4 p-5">
                <x-select name="warehouse_id" label="Gudang Pengirim" required>
                    <option value="">Pilih gudang</option>
                    @foreach ($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}" @selected((string) old('warehouse_id', $warehouses->count() === 1 ? $warehouse->id : '') === (string) $warehouse->id)>{{ $warehouse->code }} · {{ $warehouse->name }}</option>
                    @endforeach
                </x-select>
                <div>
                    <label for="file" class="form-label">File Pesanan <span class="text-rose-500">*</span></label>
                    <input id="file" name="file" type="file" required accept=".xlsx,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                           @class(['form-input file:mr-3 file:border-0 file:bg-transparent file:text-sm file:font-medium', 'form-input-error' => $errors->has('file')])>
                    @error('file')
                        <p class="mt-1 flex items-center gap-1 text-xs text-rose-600"><x-heroicon-o-exclamation-triangle class="size-4 shrink-0" /> {{ $message }}</p>
                    @else
                        <p class="mt-1 text-xs text-slate-400">.xlsx atau .csv (pemisah koma atau titik koma), maks. 10 MB / 2.000 baris.</p>
                    @enderror
                </div>
                @can('sales.confirm')
                    <label class="flex items-start gap-2 text-sm text-slate-700">
                        <input type="checkbox" name="confirm" value="1" class="mt-0.5 rounded border-slate-300" @checked(old('confirm'))>
                        <span>Langsung konfirmasi (cadangkan stok). Pesanan yang stoknya kurang tetap jadi draft.</span>
                    </label>
                @endcan
                <button type="submit" class="btn-primary"><x-heroicon-o-arrow-up-tray class="size-5" /> Impor</button>
            </div>
        </form>

        <div class="card">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-5 py-3">
                <h2 class="flex items-center gap-2 text-base font-semibold text-slate-900"><x-heroicon-o-table-cells class="size-5 text-slate-400" /> Format Kolom</h2>
                <div class="flex gap-2">
                    <a href="{{ route('sales.orders.import.template', 'xlsx') }}" class="btn-secondary"><x-heroicon-o-arrow-down-tray class="size-5" /> Template Excel</a>
                    <a href="{{ route('sales.orders.import.template', 'csv') }}" class="btn-secondary"><x-heroicon-o-arrow-down-tray class="size-5" /> CSV</a>
                </div>
            </div>
            <table class="min-w-full text-sm">
                <tbody class="divide-y divide-slate-100">
                    @foreach ($columns as $column => $description)
                        <tr>
                            <td class="px-5 py-2 font-mono text-slate-900">{{ $column }}</td>
                            <td class="px-5 py-2 text-slate-600">{{ $description }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="border-t border-slate-100 px-5 py-3 text-xs text-slate-500">
                Baris pertama harus judul kolom. Urutan kolom bebas. No. pesanan yang sudah pernah diimpor (di toko yang sama) otomatis dilewati, jadi file yang sama aman diunggah ulang.
            </p>
        </div>
    </div>
</x-layouts.app>
