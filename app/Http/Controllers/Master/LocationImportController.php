<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Services\WarehouseLocationImporter;
use App\Support\SpreadsheetImport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LocationImportController extends Controller
{
    public function create(): View
    {
        return view('imports.page', [
            'title' => 'Impor Zona, Rak & Lokasi',
            'description' => 'Tambah banyak lokasi bin sekaligus dari Excel/CSV. Satu baris = gudang + zona + rak + lokasi.',
            'back' => route('master.locations.index'),
            'backLabel' => 'Lokasi Bin',
            'action' => route('master.locations.import.store'),
            'template' => fn ($format) => route('master.locations.import.template', $format),
            'columns' => WarehouseLocationImporter::COLUMNS,
            'note' => 'Baris pertama harus judul kolom; urutan kolom bebas. Zona dan rak yang belum ada dibuat otomatis, yang sudah ada tidak diubah. Lokasi yang kodenya sudah ada di gudang itu dilewati, jadi file yang sama aman diunggah ulang. File hasil Export lokasi bin juga bisa diimpor.',
        ]);
    }

    public function store(Request $request, WarehouseLocationImporter $importer): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:10240'],
        ], ['file.mimes' => 'File harus .xlsx atau .csv.'], ['file' => 'file']);

        $file = $request->file('file');
        $result = $importer->import($file->getRealPath(), SpreadsheetImport::extension($file));

        return redirect()->route('master.locations.import')
            ->with('import_summary', [
                ['label' => 'Lokasi baru', 'value' => count($result['locations']), 'items' => $result['locations'], 'tone' => 'success',
                    'link' => route('master.locations.index'), 'linkLabel' => 'Lihat lokasi bin'],
                ['label' => 'Zona & rak baru', 'value' => count($result['zones']).' zona · '.count($result['racks']).' rak',
                    'items' => [...$result['zones'], ...$result['racks']]],
                ['label' => 'Lokasi sudah ada (dilewati)', 'value' => count($result['skipped']), 'items' => $result['skipped']],
            ])
            ->with('success', count($result['locations']).' lokasi berhasil diimpor.');
    }

    public function template(string $format): BinaryFileResponse|StreamedResponse
    {
        return SpreadsheetImport::template($format, 'template-impor-lokasi', array_keys(WarehouseLocationImporter::COLUMNS), [
            ['JKT01', 'A', 'Zona A', 'Penyimpanan', 'A01', 'Rak A01', 3, '', 1, 1, 'Bin', 50, '', 'Ya'],
            ['JKT01', 'A', 'Zona A', 'Penyimpanan', 'A01', 'Rak A01', 3, '', 1, 2, 'Bin', 50, '', 'Ya'],
            ['JKT01', 'A', 'Zona A', 'Penyimpanan', 'A01', 'Rak A01', 3, '', 2, 1, 'Shelf', '', 25, 'Ya'],
            ['JKT01', 'B', 'Zona Pallet', 'Penyimpanan', 'B01', '', 1, 'PLT-01', '', '', 'Pallet', '', 500, 'Ya'],
        ]);
    }
}
