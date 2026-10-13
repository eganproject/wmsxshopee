<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use App\Rules\AccessibleRecord;
use App\Services\OpeningStockImporter;
use App\Support\SpreadsheetImport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OpeningStockImportController extends Controller
{
    public function create(): View
    {
        return view('imports.page', [
            'title' => 'Impor Stok Awal',
            'description' => 'Masukkan saldo stok banyak barang sekaligus. Semua baris dicatat sebagai satu dokumen Barang Masuk.',
            'back' => route('inventory.stocks.index'),
            'backLabel' => 'Stok',
            'action' => route('inventory.opening-stock.store'),
            'template' => fn ($format) => route('inventory.opening-stock.template', $format),
            'columns' => OpeningStockImporter::COLUMNS,
            'note' => 'Baris pertama harus judul kolom; urutan kolom bebas. SKU yang sama boleh muncul di beberapa baris untuk lokasi berbeda. Qty ditambahkan ke saldo yang ada, jadi jangan unggah file yang sama dua kali.',
            'fields' => 'inventory.opening-stock._fields',
            'warehouses' => Warehouse::where('is_active', true)->orderBy('code')->get(),
        ]);
    }

    public function store(Request $request, OpeningStockImporter $importer): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true), new AccessibleRecord(Warehouse::class)],
            'movement_date' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:100'],
            'file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:10240'],
        ], [
            'file.mimes' => 'File harus .xlsx atau .csv.',
            'movement_date.before_or_equal' => 'Tanggal tidak boleh di masa depan.',
        ], [
            'warehouse_id' => 'gudang',
            'movement_date' => 'tanggal',
            'reference' => 'referensi',
            'file' => 'file',
        ]);

        $file = $request->file('file');
        $movement = $importer->import($file->getRealPath(), SpreadsheetImport::extension($file), [
            'warehouse_id' => (int) $data['warehouse_id'],
            'movement_date' => $data['movement_date'],
            'reference' => $data['reference'] ?? 'Stok awal',
            'notes' => 'Impor stok awal dari '.mb_substr($file->getClientOriginalName(), 0, 200),
            'created_by' => $request->user()->id,
        ]);
        $movement->loadCount('items')->loadSum('items', 'qty');

        return redirect()->route('inventory.opening-stock.create')
            ->with('import_summary', [
                ['label' => 'Dokumen Barang Masuk', 'value' => $movement->number, 'tone' => 'success', 'link' => route('inventory.movements.show', $movement), 'linkLabel' => 'Lihat dokumen'],
                ['label' => 'Baris diimpor', 'value' => number_format($movement->items_count, 0, ',', '.')],
                ['label' => 'Total qty masuk', 'value' => number_format((int) $movement->items_sum_qty, 0, ',', '.')],
            ])
            ->with('success', "Stok awal berhasil diimpor ke {$movement->number}.");
    }

    public function template(string $format): BinaryFileResponse|StreamedResponse
    {
        return SpreadsheetImport::template($format, 'template-impor-stok-awal', array_keys(OpeningStockImporter::COLUMNS), [
            ['KAOS-M', 'A01-01-01', 50, ''],
            ['KAOS-M', 'A01-01-02', 20, 'Sisa rak lama'],
            ['8992001', '', 12, 'Tanpa bin'],
        ]);
    }
}
