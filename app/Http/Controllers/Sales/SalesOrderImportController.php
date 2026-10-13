<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use App\Rules\AccessibleRecord;
use App\Services\SalesOrderImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalesOrderImportController extends Controller
{
    public function create(): View
    {
        return view('sales.orders.import', [
            'warehouses' => Warehouse::where('is_active', true)->orderBy('code')->get(),
            'columns' => SalesOrderImporter::COLUMNS,
        ]);
    }

    public function store(Request $request, SalesOrderImporter $importer): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true), new AccessibleRecord(Warehouse::class)],
            'file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:10240'],
            'confirm' => ['nullable', 'boolean'],
        ], [
            'file.mimes' => 'File harus .xlsx atau .csv.',
        ], [
            'warehouse_id' => 'gudang',
            'file' => 'file',
        ]);

        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension()) === 'xlsx' ? 'xlsx' : 'csv';

        $result = $importer->import($file->getRealPath(), $extension, (int) $data['warehouse_id'], $request->boolean('confirm') && $request->user()->can('sales.confirm'), $request->user()->id);

        return redirect()->route('sales.orders.import')->with('import', $result)
            ->with(count($result['created']) ? 'success' : 'error', count($result['created'])
                ? count($result['created']).' pesanan berhasil diimpor.'
                : 'Tidak ada pesanan baru yang diimpor.');
    }

    public function template(string $format): BinaryFileResponse|StreamedResponse
    {
        abort_unless(in_array($format, ['xlsx', 'csv'], true), 404);

        $header = array_keys(SalesOrderImporter::COLUMNS);
        $samples = [
            ['250101ABCD', now()->toDateString(), 'Shopee Toko A', 'Budi Santoso', '081234567890', 'Jl. Merdeka 1, Bandung', 'JNE', 'REG', 12000, 'KAOS-M', 2, 75000, 'Bungkus rapi'],
            ['250101ABCD', now()->toDateString(), 'Shopee Toko A', 'Budi Santoso', '081234567890', 'Jl. Merdeka 1, Bandung', 'JNE', 'REG', 12000, 'KAOS-L', 1, 75000, 'Bungkus rapi'],
            ['', now()->toDateString(), 'WhatsApp', 'Siti', '', 'Jl. Mawar 5, Depok', '', '', '', 'KAOS-M', 3, 70000, ''],
        ];

        if ($format === 'csv') {
            return response()->streamDownload(function () use ($header, $samples) {
                $out = fopen('php://output', 'w');
                fputcsv($out, $header, ';', '"', '');
                foreach ($samples as $row) {
                    fputcsv($out, $row, ';', '"', '');
                }
                fclose($out);
            }, 'template-impor-pesanan.csv', ['Content-Type' => 'text/csv']);
        }

        $path = tempnam(sys_get_temp_dir(), 'so').'.xlsx';
        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValuesWithStyle($header, (new Style)->withFontBold(true)));
        foreach ($samples as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return response()->download($path, 'template-impor-pesanan.xlsx')->deleteFileAfterSend();
    }
}
