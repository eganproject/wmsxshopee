<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Services\BundleImporter;
use App\Support\SpreadsheetImport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BundleImportController extends Controller
{
    public function create(): View
    {
        return view('imports.page', [
            'title' => 'Impor Produk Paket',
            'description' => 'Tambah atau perbarui banyak paket (SKU bundling) sekaligus dari Excel/CSV. Satu baris = satu barang isi paket.',
            'back' => route('master.bundles.index'),
            'backLabel' => 'Produk Paket',
            'action' => route('master.bundles.import.store'),
            'template' => fn ($format) => route('master.bundles.import.template', $format),
            'columns' => BundleImporter::COLUMNS,
            'note' => 'Baris pertama harus judul kolom; urutan kolom bebas. Barang isi harus sudah ada di Master Produk. SKU paket yang sudah ada akan diperbarui; bila file berisi barang isinya, isi paket diganti seluruhnya sesuai file. Baris tanpa sku_isi hanya mengubah data paket (nama, harga, status).',
        ]);
    }

    public function store(Request $request, BundleImporter $importer): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:10240'],
        ], ['file.mimes' => 'File harus .xlsx atau .csv.'], ['file' => 'file']);

        $file = $request->file('file');
        $result = $importer->import($file->getRealPath(), SpreadsheetImport::extension($file), $request->user()->can('products.edit'));

        return redirect()->route('master.bundles.import')
            ->with('import_summary', [
                ['label' => 'Paket baru', 'value' => count($result['created']), 'items' => $result['created'], 'tone' => 'success'],
                ['label' => 'Paket diperbarui', 'value' => count($result['updated']), 'items' => $result['updated']],
            ])
            ->with('success', (count($result['created']) + count($result['updated'])).' paket berhasil diimpor.');
    }

    public function template(string $format): BinaryFileResponse|StreamedResponse
    {
        return SpreadsheetImport::template($format, 'template-impor-produk-paket', array_keys(BundleImporter::COLUMNS), [
            ['PAKET-KAOS-3', 'Paket Kaos Polos isi 3', '8993001', 120000, 'KAOS-M', 2, 'Ya', 'Isi 2 kaos M + 1 kaos L'],
            ['PAKET-KAOS-3', '', '', '', 'KAOS-L', 1, '', ''],
            ['PAKET-TUMBLER-2', 'Tumbler 500ml isi 2', '', 150000, 'TUMBLER', 2, 'Ya', ''],
        ]);
    }
}
