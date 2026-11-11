<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\ProductBulkUpdater;
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
 * Update massal produk per kolom: pilih kolom → unduh template → unggah →
 * pratinjau perubahan → terapkan. File unggahan disimpan sementara sampai
 * perubahan diterapkan atau dibatalkan.
 */
class ProductBulkUpdateController extends Controller
{
    private const SESSION = 'product_bulk_update';

    private const DIRECTORY = 'bulk-updates';

    /** Batas baris perubahan yang ditampilkan di pratinjau. */
    private const PREVIEW_LIMIT = 300;

    private const FILTERS = ['search', 'category', 'status'];

    public function create(Request $request): View
    {
        $filters = array_filter($request->only(self::FILTERS), 'filled');
        $selected = ProductBulkUpdater::normalize((array) $request->query('fields', session('product_bulk_update_fields', [])));

        return view('master.products.bulk-update.create', [
            'groups' => ProductBulkUpdater::groups(),
            'fields' => ProductBulkUpdater::FIELDS,
            'selected' => $selected,
            'filters' => $filters,
            'counts' => [
                'all' => Product::count(),
                'filtered' => $filters ? Product::filter($filters)->count() : null,
            ],
            'pending' => $this->pending() !== null,
        ]);
    }

    public function template(Request $request): BinaryFileResponse|StreamedResponse
    {
        $data = $request->validate([
            'fields' => ['required', 'array', 'min:1'],
            'fields.*' => ['string', Rule::in(array_keys(ProductBulkUpdater::FIELDS))],
            'scope' => ['nullable', Rule::in(['all', 'filtered', 'empty'])],
            'format' => ['nullable', Rule::in(['xlsx', 'csv'])],
        ], ['fields.required' => 'Pilih minimal satu kolom yang akan diubah.']);

        $fields = ProductBulkUpdater::normalize($data['fields']);
        $scope = $data['scope'] ?? 'all';
        $format = $data['format'] ?? 'xlsx';
        // Pilihan kolom diingat, jadi saat kembali ke halaman ini tidak perlu memilih ulang.
        session(['product_bulk_update_fields' => $fields]);

        $query = Product::query()->when($scope === 'filtered', fn ($q) => $q->filter($request->only(self::FILTERS)));
        $header = ProductBulkUpdater::header($fields);
        $rows = $scope === 'empty' ? [] : ProductBulkUpdater::rows($query, $fields);
        $basename = 'update-massal-produk-'.now()->format('Ymd-His');

        if ($format === 'csv') {
            return response()->streamDownload(function () use ($header, $rows) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF"); // BOM supaya huruf non-ASCII terbaca benar di Excel
                fputcsv($out, $header, ';', '"', '');
                foreach ($rows as $row) {
                    fputcsv($out, $row, ';', '"', '');
                }
                fclose($out);
            }, "{$basename}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        // SKU & barcode dipaksa teks supaya angka nol di depan tidak hilang di Excel.
        $textColumns = [...array_values(ProductBulkUpdater::KEYS), 'barcode', ...array_keys(ProductBulkUpdater::infoColumns($fields))];
        $formatted = array_map(fn ($column) => in_array($column, $textColumns, true) ? [$column, 'text'] : $column, $header);

        return XlsxExport::workbook("{$basename}.xlsx", [
            ['name' => 'Update Produk', 'header' => $formatted, 'rows' => $rows],
            ['name' => 'Panduan', 'type' => 'info', 'rows' => $this->guide($fields)],
        ]);
    }

    public function store(Request $request, ProductBulkUpdater $updater): RedirectResponse
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
            $updater->analyze(Storage::disk('local')->path($path), $extension);
        } catch (ValidationException $e) {
            Storage::disk('local')->delete($path);

            return back()->withErrors($e->errors(), 'bulk')->with('bulk_file', $file->getClientOriginalName());
        }

        session([self::SESSION => ['path' => $path, 'extension' => $extension, 'name' => $file->getClientOriginalName()]]);

        return redirect()->route('master.products.bulk-update.preview');
    }

    public function preview(Request $request, ProductBulkUpdater $updater): View|RedirectResponse
    {
        if (! $pending = $this->pending()) {
            return redirect()->route('master.products.bulk-update')->with('warning', 'Pratinjau sudah tidak tersedia. Unggah file lagi.');
        }

        try {
            $analysis = $updater->analyze(Storage::disk('local')->path($pending['path']), $pending['extension']);
        } catch (ValidationException $e) {
            $this->discard();

            return redirect()->route('master.products.bulk-update')->withErrors($e->errors(), 'bulk')->with('bulk_file', $pending['name']);
        }

        $field = $request->query('field');
        $changes = collect($analysis['changes'])->when(isset($analysis['counts'][$field]), fn ($c) => $c->where('field', $field));

        return view('master.products.bulk-update.preview', [
            'analysis' => $analysis,
            'file' => $pending['name'],
            'fields' => ProductBulkUpdater::FIELDS,
            'field' => isset($analysis['counts'][$field]) ? $field : null,
            'changes' => $changes->take(self::PREVIEW_LIMIT)->values(),
            'total' => $changes->count(),
            'limit' => self::PREVIEW_LIMIT,
            'checksum' => $this->checksum($analysis),
        ]);
    }

    public function apply(Request $request, ProductBulkUpdater $updater): RedirectResponse
    {
        if (! $pending = $this->pending()) {
            return redirect()->route('master.products.bulk-update')->with('warning', 'Pratinjau sudah tidak tersedia. Unggah file lagi.');
        }

        try {
            $analysis = $updater->analyze(Storage::disk('local')->path($pending['path']), $pending['extension']);
        } catch (ValidationException $e) {
            $this->discard();

            return redirect()->route('master.products.bulk-update')->withErrors($e->errors(), 'bulk')->with('bulk_file', $pending['name']);
        }

        // Data produk bisa berubah oleh pengguna lain setelah pratinjau dibuka.
        if (! hash_equals($this->checksum($analysis), (string) $request->input('checksum'))) {
            return redirect()->route('master.products.bulk-update.preview')
                ->with('warning', 'Data produk berubah sejak pratinjau dibuka. Periksa lagi daftar perubahan di bawah sebelum menerapkan.');
        }

        $updated = $updater->apply($analysis);
        $this->discard();

        return redirect()->route('master.products.bulk-update')
            ->with('bulk_result', [
                'products' => $updated,
                'changes' => $analysis['changed'],
                'rows' => $analysis['rows'],
                'fields' => collect($analysis['counts'])->filter()->keys()->map(fn ($f) => ProductBulkUpdater::FIELDS[$f]['label'])->all(),
            ])
            ->with('success', $updated ? "{$updated} produk berhasil diperbarui." : 'Tidak ada perubahan yang perlu disimpan.');
    }

    public function destroy(): RedirectResponse
    {
        $this->discard();

        return redirect()->route('master.products.bulk-update')->with('info', 'Update massal dibatalkan. Tidak ada data yang diubah.');
    }

    /**
     * Isi sheet Panduan di template Excel.
     *
     * @param  list<string>  $fields
     * @return list<array<int, string>>
     */
    private function guide(array $fields): array
    {
        $level = ProductBulkUpdater::level($fields);
        $key = ProductBulkUpdater::KEYS[$level];

        return [
            ['Panduan Update Massal Produk'],
            ['Cara pakai', 'Ubah nilai di sheet "Update Produk", simpan, lalu unggah di Master Produk → Update Massal. Perubahan ditampilkan dulu sebelum disimpan.'],
            ['Satu baris', $level === ProductBulkUpdater::LEVEL_VARIANT ? 'Satu SKU varian. Kolom data produk harus sama di semua varian produk yang sama.' : 'Satu produk (SKU induk).'],
            ['Sel kosong', 'Mengosongkan nilai di kolom itu (kecuali kolom wajib). Hapus barisnya bila produk itu tidak ingin diubah.'],
            ['Tidak berubah', 'SKU, varian baru, gambar & stok tidak pernah diubah lewat file ini.'],
            [''],
            ['Kolom'],
            [$key, $level === ProductBulkUpdater::LEVEL_VARIANT ? 'Kunci: SKU varian (produk tanpa varian memakai SKU induk). Jangan diubah.' : 'Kunci: SKU induk produk. Jangan diubah.'],
            ...collect(ProductBulkUpdater::infoColumns($fields))->map(fn ($text, $column) => [$column, $text.' Hanya keterangan, diabaikan saat diunggah.'])->values()->all(),
            ...array_map(fn ($field) => [$field, ProductBulkUpdater::FIELDS[$field]['label'].': '.ProductBulkUpdater::FIELDS[$field]['hint']], $fields),
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

    /** Hapus file pratinjau lama yang ditinggal tanpa diterapkan / dibatalkan. */
    private function prune(): void
    {
        $disk = Storage::disk('local');
        foreach ($disk->files(self::DIRECTORY) as $path) {
            if ($disk->lastModified($path) < now()->subDay()->getTimestamp()) {
                $disk->delete($path);
            }
        }
    }

    /** Sidik jari daftar perubahan, untuk memastikan yang diterapkan sama dengan yang dilihat. */
    private function checksum(array $analysis): string
    {
        return hash('sha256', json_encode($analysis['changes']));
    }
}
