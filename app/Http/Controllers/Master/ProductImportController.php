<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Services\ProductImporter;
use App\Support\SpreadsheetImport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductImportController extends Controller
{
    public function create(): View
    {
        return view('imports.page', [
            'title' => 'Impor Produk',
            'description' => 'Tambah atau perbarui banyak produk sekaligus dari Excel/CSV. Satu baris = satu varian.',
            'back' => route('master.products.index'),
            'backLabel' => 'Master Produk',
            'action' => route('master.products.import.store'),
            'template' => fn ($format) => route('master.products.import.template', $format),
            'columns' => ProductImporter::COLUMNS,
            'note' => 'Baris pertama harus judul kolom; urutan kolom bebas. SKU induk yang sudah ada akan diperbarui dan varian barunya ditambahkan; varian yang tidak ada di file tidak dihapus. Gambar produk tetap diunggah lewat form produk.',
        ]);
    }

    public function store(Request $request, ProductImporter $importer): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:10240'],
        ], ['file.mimes' => 'File harus .xlsx atau .csv.'], ['file' => 'file']);

        $file = $request->file('file');
        $result = $importer->import($file->getRealPath(), SpreadsheetImport::extension($file), $request->user()->can('products.edit'));

        return redirect()->route('master.products.import')
            ->with('import_summary', [
                ['label' => 'Produk baru', 'value' => count($result['created']), 'items' => $result['created'], 'tone' => 'success'],
                ['label' => 'Produk diperbarui', 'value' => count($result['updated']), 'items' => $result['updated']],
                ['label' => 'Varian baru ditambahkan', 'value' => $result['variants']],
            ])
            ->with('success', (count($result['created']) + count($result['updated'])).' produk berhasil diimpor.');
    }

    public function template(string $format): BinaryFileResponse|StreamedResponse
    {
        return SpreadsheetImport::template($format, 'template-impor-produk', array_keys(ProductImporter::COLUMNS), [
            ['KAOS', 'Kaos Polos', 'Pakaian', 'Optima', 'PCS', 'M', 'KAOS-M', '8991001', 200, 30, 25, 2, 10, 'Ya', 'Kaos katun 30s'],
            ['KAOS', 'Kaos Polos', 'Pakaian', 'Optima', 'PCS', 'L', 'KAOS-L', '8991002', 220, 32, 27, 2, 10, 'Ya', ''],
            ['TUMBLER', 'Tumbler 500ml', 'Peralatan', '', 'PCS', '', '', '8992001', 350, 8, 8, 22, 5, 'Ya', ''],
        ]);
    }
}
