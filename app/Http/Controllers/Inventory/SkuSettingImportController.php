<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use App\Services\FixedBins;
use App\Services\SkuSettingImporter;
use App\Support\SpreadsheetImport;
use App\Support\XlsxExport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Import update SKU per Gudang: pilih kolom → unduh template berisi data saat
 * ini → unggah → pratinjau perubahan → terapkan. File unggahan disimpan
 * sementara sampai perubahan diterapkan atau dibatalkan.
 */
class SkuSettingImportController extends Controller
{
    private const SESSION = 'sku_settings_import';

    private const DIRECTORY = 'bulk-updates';

    private const PREVIEW_LIMIT = 300;

    private const SCOPES = ['all', 'stocked', 'configured', 'empty'];

    public function create(Request $request): View
    {
        $warehouses = Warehouse::where('is_active', true)->orderBy('code')->get();

        return view('inventory.sku-settings.import', [
            'warehouses' => $warehouses,
            'warehouse' => $warehouses->firstWhere('id', $request->integer('warehouse')) ?? $warehouses->first(),
            'fields' => SkuSettingImporter::FIELDS,
            'selected' => SkuSettingImporter::normalize((array) session('sku_settings_import_fields', array_keys(SkuSettingImporter::FIELDS))),
            'pending' => $this->pending() !== null,
        ]);
    }

    public function template(Request $request, FixedBins $fixed): BinaryFileResponse|StreamedResponse
    {
        $data = $request->validate([
            'fields' => ['required', 'array', 'min:1'],
            'fields.*' => ['string', Rule::in(array_keys(SkuSettingImporter::FIELDS))],
            'warehouse' => ['required', 'string'],
            'scope' => ['nullable', Rule::in(self::SCOPES)],
            'format' => ['nullable', Rule::in(['xlsx', 'csv'])],
        ], ['fields.required' => 'Pilih minimal satu kolom yang akan diubah.']);

        $fields = SkuSettingImporter::normalize($data['fields']);
        $scope = $data['scope'] ?? 'all';
        $format = $data['format'] ?? 'xlsx';
        session(['sku_settings_import_fields' => $fields]);

        // "all" = semua gudang aktif yang bisa diakses; selain itu satu gudang.
        $warehouses = Warehouse::where('is_active', true)->orderBy('code')
            ->when($data['warehouse'] !== 'all', fn ($q) => $q->whereKey((int) $data['warehouse']))
            ->get();
        abort_if($warehouses->isEmpty(), 404);

        $header = SkuSettingImporter::header($fields);
        $rows = $scope === 'empty' ? [] : SkuSettingImporter::rows($warehouses, $fields, $scope, $fixed);
        $basename = 'sku-per-gudang-'.($warehouses->count() === 1 ? $warehouses->first()->code.'-' : '').now()->format('Ymd-His');

        if ($format === 'csv') {
            return response()->streamDownload(function () use ($header, $rows) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, $header, ';', '"', '');
                foreach ($rows as $row) {
                    fputcsv($out, $row, ';', '"', '');
                }
                fclose($out);
            }, "{$basename}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        // Kode gudang, SKU & lokasi dipaksa teks supaya angka nol di depan tidak hilang.
        $text = ['gudang', 'sku', 'info_nama_produk', 'lokasi_tetap'];
        $formatted = array_map(fn ($column) => in_array($column, $text, true) ? [$column, 'text'] : $column, $header);

        return XlsxExport::workbook("{$basename}.xlsx", [
            ['name' => 'SKU per Gudang', 'header' => $formatted, 'rows' => $rows],
            ['name' => 'Panduan', 'type' => 'info', 'rows' => $this->guide($fields)],
        ]);
    }

    public function store(Request $request, SkuSettingImporter $importer): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:10240'],
        ], ['file.mimes' => 'File harus .xlsx atau .csv.'], ['file' => 'file']);

        $this->discard();
        $this->prune();

        $file = $request->file('file');
        $extension = SpreadsheetImport::extension($file);
        $path = $file->storeAs(self::DIRECTORY, Str::uuid().'.'.$extension, 'local');

        try {
            $importer->analyze(Storage::disk('local')->path($path), $extension);
        } catch (ValidationException $e) {
            Storage::disk('local')->delete($path);

            return back()->withErrors($e->errors(), 'import')->with('import_file', $file->getClientOriginalName());
        }

        session([self::SESSION => ['path' => $path, 'extension' => $extension, 'name' => $file->getClientOriginalName()]]);

        return redirect()->route('inventory.sku-settings.import.preview');
    }

    public function preview(Request $request, SkuSettingImporter $importer): View|RedirectResponse
    {
        if (! $pending = $this->pending()) {
            return redirect()->route('inventory.sku-settings.import')->with('warning', 'Pratinjau sudah tidak tersedia. Unggah file lagi.');
        }

        try {
            $analysis = $importer->analyze(Storage::disk('local')->path($pending['path']), $pending['extension']);
        } catch (ValidationException $e) {
            $this->discard();

            return redirect()->route('inventory.sku-settings.import')->withErrors($e->errors(), 'import')->with('import_file', $pending['name']);
        }

        $field = $request->query('field');
        $changes = collect($analysis['changes'])->when(isset($analysis['counts'][$field]), fn ($c) => $c->where('field', $field));

        return view('inventory.sku-settings.import-preview', [
            'analysis' => $analysis,
            'file' => $pending['name'],
            'fields' => SkuSettingImporter::FIELDS,
            'field' => isset($analysis['counts'][$field]) ? $field : null,
            'changes' => $changes->take(self::PREVIEW_LIMIT)->values(),
            'total' => $changes->count(),
            'limit' => self::PREVIEW_LIMIT,
            'checksum' => $this->checksum($analysis),
        ]);
    }

    public function apply(Request $request, SkuSettingImporter $importer, FixedBins $fixed): RedirectResponse
    {
        if (! $pending = $this->pending()) {
            return redirect()->route('inventory.sku-settings.import')->with('warning', 'Pratinjau sudah tidak tersedia. Unggah file lagi.');
        }

        try {
            $analysis = $importer->analyze(Storage::disk('local')->path($pending['path']), $pending['extension']);
        } catch (ValidationException $e) {
            $this->discard();

            return redirect()->route('inventory.sku-settings.import')->withErrors($e->errors(), 'import')->with('import_file', $pending['name']);
        }

        // Pengaturan bisa diubah pengguna lain setelah pratinjau dibuka.
        if (! hash_equals($this->checksum($analysis), (string) $request->input('checksum'))) {
            return redirect()->route('inventory.sku-settings.import.preview')
                ->with('warning', 'Data SKU per Gudang berubah sejak pratinjau dibuka. Periksa lagi daftar perubahan di bawah sebelum menerapkan.');
        }

        $updated = $importer->apply($analysis, $fixed);
        $this->discard();

        return redirect()->route('inventory.sku-settings.import')
            ->with('import_result', [
                'skus' => $updated,
                'changes' => $analysis['changed'],
                'rows' => $analysis['rows'],
                'fields' => collect($analysis['counts'])->filter()->keys()->map(fn ($f) => SkuSettingImporter::FIELDS[$f]['label'])->all(),
            ])
            ->with('success', $updated ? "{$updated} SKU per gudang berhasil diperbarui." : 'Tidak ada perubahan yang perlu disimpan.');
    }

    public function destroy(): RedirectResponse
    {
        $this->discard();

        return redirect()->route('inventory.sku-settings.import')->with('info', 'Import dibatalkan. Tidak ada data yang diubah.');
    }

    /**
     * @param  list<string>  $fields
     * @return list<array<int, string>>
     */
    private function guide(array $fields): array
    {
        return [
            ['Panduan Import SKU per Gudang'],
            ['Cara pakai', 'Ubah nilai di sheet "SKU per Gudang", simpan, lalu unggah di Inventori → SKU per Gudang → Import. Perubahan ditampilkan dulu sebelum disimpan.'],
            ['Satu baris', 'Satu SKU di satu gudang. Satu file boleh berisi beberapa gudang.'],
            ['Sel kosong', 'Mengosongkan nilai di kolom itu. Hapus barisnya bila SKU itu tidak ingin diubah.'],
            ['Kolom tidak ada', 'Kolom yang tidak ada di file tidak diubah sama sekali.'],
            [''],
            ['Kolom'],
            ['gudang', 'Kunci: kode gudang, cth. GB01. Jangan diubah.'],
            ['sku', 'Kunci: SKU varian (produk tanpa varian memakai SKU induk). Jangan diubah.'],
            ...collect(SkuSettingImporter::INFO_COLUMNS)->map(fn ($text, $column) => [$column, $text.' Hanya keterangan, diabaikan saat diunggah.'])->values()->all(),
            ...array_map(fn ($field) => [$field, SkuSettingImporter::FIELDS[$field]['label'].': '.SkuSettingImporter::FIELDS[$field]['hint']], $fields),
        ];
    }

    /** @return array{path: string, extension: string, name: string}|null */
    private function pending(): ?array
    {
        $pending = session(self::SESSION);

        return $pending && Storage::disk('local')->exists($pending['path']) ? $pending : null;
    }

    private function discard(): void
    {
        if ($pending = session()->pull(self::SESSION)) {
            Storage::disk('local')->delete($pending['path']);
        }
    }

    private function prune(): void
    {
        $disk = Storage::disk('local');
        foreach ($disk->files(self::DIRECTORY) as $path) {
            if ($disk->lastModified($path) < now()->subDay()->getTimestamp()) {
                $disk->delete($path);
            }
        }
    }

    private function checksum(array $analysis): string
    {
        return hash('sha256', json_encode($analysis['changes']));
    }
}
