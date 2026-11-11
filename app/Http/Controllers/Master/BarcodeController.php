<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\BarcodeScanMiss;
use App\Models\ProductBarcode;
use App\Models\ProductVariant;
use App\Support\Barcodes;
use App\Support\SpreadsheetImport;
use App\Support\XlsxExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Barcode alias per varian (satu SKU bisa punya banyak barcode) dan daftar
 * barcode yang di-scan tapi tidak dikenali, yang bisa dijadikan alias atau
 * ditandai selesai.
 */
class BarcodeController extends Controller
{
    private const IMPORT_COLUMNS = [
        'sku' => 'Wajib. SKU varian pemilik barcode.',
        'barcode' => 'Wajib. Barcode tambahan: huruf, angka, - dan _ (maks. 50), belum dipakai barang lain.',
        'catatan' => 'Opsional, cth. "barcode supplier lama".',
    ];

    private const IMPORT_ALIASES = [
        'sku' => ['sku_varian', 'kode_barang'],
        'barcode' => ['barcode_alias', 'alias', 'ean'],
        'catatan' => ['keterangan', 'notes', 'catatan_barcode'],
    ];

    public function index(Request $request): View|BinaryFileResponse
    {
        $search = trim((string) $request->input('search'));
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';

        $query = ProductBarcode::query()
            ->with(['variant.product', 'creator'])
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('barcode', 'like', $like)
                ->orWhere('notes', 'like', $like)
                ->orWhereHas('variant', fn (Builder $v) => $v->where('sku', 'like', $like)
                    ->orWhereHas('product', fn (Builder $p) => $p->where('name', 'like', $like)))))
            ->orderBy('barcode');

        if ($request->input('export') === 'xlsx') {
            return XlsxExport::download('barcode-alias-'.now()->format('Ymd-His').'.xlsx',
                ['SKU', 'Produk / Varian', 'Barcode Utama', 'Barcode Alias', 'Catatan', 'Ditambahkan Oleh', 'Ditambahkan'],
                $query->lazy(500)->map(fn (ProductBarcode $alias) => [
                    $alias->variant?->sku, $alias->variant?->fullName(), $alias->variant?->barcode, $alias->barcode,
                    $alias->notes, $alias->creator?->name, $alias->created_at?->format('d/m/Y H:i'),
                ]));
        }

        return view('master.barcodes.index', [
            'aliases' => $query->paginate(50)->withQueryString(),
            'openMisses' => BarcodeScanMiss::query()->open()->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $variant = ProductVariant::findOrFail($data['product_variant_id']);
        DB::transaction(function () use ($data, $request, $variant) {
            ProductBarcode::create([...$data, 'created_by' => $request->user()->id]);
            BarcodeScanMiss::query()->open()->where('code', $data['barcode'])->get()
                ->each(fn (BarcodeScanMiss $miss) => $this->closeMiss($miss, 'alias', "Ditambahkan sebagai alias {$variant->sku}", $request->user()->id));
        });

        return back()->with('success', "Barcode {$data['barcode']} ditambahkan ke {$variant->sku}.");
    }

    public function destroy(ProductBarcode $barcode): RedirectResponse
    {
        $barcode->load('variant');
        $barcode->delete();

        return back()->with('success', "Barcode alias {$barcode->barcode} milik {$barcode->variant?->sku} dihapus.");
    }

    /** Pilihan varian untuk form (Tom Select AJAX): SKU, barcode, alias atau nama. */
    public function lookup(Request $request): JsonResponse
    {
        $search = trim($request->string('q')->toString());
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';

        $variants = ProductVariant::query()
            ->with('product:id,name')
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('sku', 'like', $like)
                ->orWhere('barcode', 'like', $like)
                ->orWhere('name', 'like', $like)
                ->orWhereHas('aliases', fn (Builder $a) => $a->where('barcode', 'like', $like))
                ->orWhereHas('product', fn (Builder $p) => $p->where('name', 'like', $like))))
            ->orderBy('sku')
            ->limit(30)
            ->get();

        return response()->json($variants->map(fn (ProductVariant $variant) => ['value' => $variant->id, 'text' => $variant->displayName()]));
    }

    /** Barcode yang di-scan tapi tidak dikenali. */
    public function misses(Request $request): View|BinaryFileResponse
    {
        $status = $request->input('status', 'open');
        $search = trim((string) $request->input('search'));

        $query = BarcodeScanMiss::query()
            ->with(['warehouse', 'user', 'resolver', 'order'])
            ->when($status === 'open', fn ($q) => $q->whereNull('resolved_at'))
            ->when($status === 'resolved', fn ($q) => $q->whereNotNull('resolved_at'))
            ->when($request->filled('context'), fn ($q) => $q->where('context', $request->input('context')))
            ->when($search !== '', fn ($q) => $q->where('code', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%'))
            ->orderByRaw('resolved_at IS NOT NULL')
            ->latest('last_seen_at')
            ->latest('id');

        if ($request->input('export') === 'xlsx') {
            return XlsxExport::download('barcode-tidak-dikenali-'.now()->format('Ymd-His').'.xlsx',
                ['Kode', 'Tempat Scan', 'Jumlah Scan', 'Terakhir Di-scan', 'Gudang', 'Pesanan', 'Di-scan Oleh', 'Status', 'Penyelesaian', 'Catatan', 'Diselesaikan Oleh'],
                $query->lazy(500)->map(fn (BarcodeScanMiss $miss) => [
                    $miss->code, $miss->contextLabel(), $miss->scan_count, $miss->last_seen_at?->format('d/m/Y H:i'),
                    $miss->warehouse?->code, $miss->order?->number, $miss->user?->name,
                    $miss->resolved_at ? 'Selesai' : 'Terbuka', $miss->resolutionLabel(), $miss->resolution_note, $miss->resolver?->name,
                ]));
        }

        return view('master.barcodes.misses', [
            'misses' => $query->paginate(50)->withQueryString(),
            'status' => $status,
            'openMisses' => BarcodeScanMiss::query()->open()->count(),
        ]);
    }

    /**
     * Selesaikan barcode tidak dikenali: jadikan alias sebuah varian, atau
     * tandai selesai (master data sudah dibetulkan / diabaikan) dengan catatan.
     */
    public function resolve(Request $request, BarcodeScanMiss $miss): RedirectResponse
    {
        if ($miss->resolved_at) {
            return back()->with('error', "Kode {$miss->code} sudah diselesaikan.");
        }

        $data = $request->validate([
            'resolution' => ['required', Rule::in(array_keys(BarcodeScanMiss::RESOLUTIONS))],
            'product_variant_id' => ['required_if:resolution,alias', 'nullable', 'integer', Rule::exists('product_variants', 'id')],
            'resolution_note' => ['nullable', 'string', 'max:255'],
        ], ['product_variant_id.required_if' => 'Pilih varian pemilik barcode ini.'], [
            'resolution' => 'penyelesaian', 'product_variant_id' => 'varian', 'resolution_note' => 'catatan',
        ]);

        DB::transaction(function () use ($request, $miss, $data) {
            if ($data['resolution'] === 'alias') {
                $this->ensureFree($miss->code, 'resolution');
                ProductBarcode::create([
                    'product_variant_id' => (int) $data['product_variant_id'],
                    'barcode' => $miss->code,
                    'notes' => $data['resolution_note'] ?? 'Dari barcode tidak dikenali',
                    'created_by' => $request->user()->id,
                ]);
            }

            $this->closeMiss($miss, $data['resolution'], $data['resolution_note'] ?? null, $request->user()->id);
        });

        $message = $data['resolution'] === 'alias'
            ? "Kode {$miss->code} dijadikan barcode alias ".ProductVariant::whereKey($data['product_variant_id'])->value('sku').'.'
            : "Kode {$miss->code} ditandai selesai.";

        return back()->with('success', $message);
    }

    public function importCreate(): View
    {
        return view('imports.page', [
            'title' => 'Impor Barcode Alias',
            'description' => 'Tambah banyak barcode alias sekaligus dari Excel/CSV. Satu baris = satu barcode tambahan untuk satu SKU.',
            'back' => route('master.barcodes.index'),
            'backLabel' => 'Barcode',
            'action' => route('master.barcodes.import.store'),
            'template' => fn ($format) => route('master.barcodes.import.template', $format),
            'columns' => self::IMPORT_COLUMNS,
            'note' => 'Baris pertama harus judul kolom; urutan kolom bebas. Satu SKU boleh punya banyak baris. Bila ada baris yang salah, tidak ada yang disimpan.',
        ]);
    }

    public function importStore(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:10240'],
        ], ['file.mimes' => 'File harus .xlsx atau .csv.'], ['file' => 'file']);

        $file = $request->file('file');
        $records = SpreadsheetImport::records($file->getRealPath(), SpreadsheetImport::extension($file), self::IMPORT_ALIASES, ['sku', 'barcode'], SpreadsheetImport::MAX_ROWS, 'File tidak berisi baris barcode.');

        $skus = $records->pluck('sku')->map(fn ($v) => SpreadsheetImport::text($v))->filter()->unique();
        $variants = ProductVariant::whereIn('sku', $skus)->get(['id', 'sku'])->keyBy(fn ($v) => mb_strtoupper($v->sku));
        $errors = [];
        $seen = [];
        $rows = [];

        foreach ($records as $record) {
            $line = $record['line'];
            $sku = SpreadsheetImport::text($record['sku']);
            $barcode = SpreadsheetImport::text($record['barcode']);
            $variant = $sku ? $variants[mb_strtoupper($sku)] ?? null : null;

            if (! $sku || ! $barcode) {
                $errors[] = "Baris {$line}: sku dan barcode wajib diisi.";
            } elseif (! $variant) {
                $errors[] = "Baris {$line}: SKU {$sku} tidak ada di master produk.";
            } elseif (! preg_match('/^[A-Za-z0-9_-]+$/', $barcode) || mb_strlen($barcode) > 50) {
                $errors[] = "Baris {$line}: barcode {$barcode} hanya boleh huruf, angka, - dan _ (maks. 50 karakter).";
            } elseif (isset($seen[mb_strtoupper($barcode)])) {
                $errors[] = "Baris {$line}: barcode {$barcode} dobel dengan baris {$seen[mb_strtoupper($barcode)]}.";
            } elseif ($owner = Barcodes::takenBy($barcode)) {
                $errors[] = "Baris {$line}: barcode {$barcode} sudah dipakai {$owner}.";
            } else {
                $seen[mb_strtoupper($barcode)] = $line;
                $rows[] = ['product_variant_id' => $variant->id, 'barcode' => $barcode, 'notes' => SpreadsheetImport::text($record['catatan']), 'sku' => $variant->sku];
            }

            if (count($errors) >= 50) {
                break;
            }
        }

        if ($errors) {
            throw ValidationException::withMessages(['rows' => $errors]);
        }

        DB::transaction(function () use ($rows, $request) {
            foreach ($rows as $row) {
                ProductBarcode::create([...collect($row)->except('sku')->all(), 'created_by' => $request->user()->id]);
                BarcodeScanMiss::query()->open()->where('code', $row['barcode'])->get()
                    ->each(fn (BarcodeScanMiss $miss) => $this->closeMiss($miss, 'alias', "Diimpor sebagai alias {$row['sku']}", $request->user()->id));
            }
        });

        return redirect()->route('master.barcodes.import')
            ->with('import_summary', [
                ['label' => 'Barcode alias baru', 'value' => count($rows), 'items' => collect($rows)->map(fn ($r) => "{$r['barcode']} → {$r['sku']}")->all(), 'tone' => 'success'],
            ])
            ->with('success', count($rows).' barcode alias berhasil diimpor.');
    }

    public function template(string $format): BinaryFileResponse|StreamedResponse
    {
        return SpreadsheetImport::template($format, 'template-impor-barcode-alias', array_keys(self::IMPORT_COLUMNS), [
            ['KAOS-M', '8991234567890', 'Barcode supplier lama'],
            ['KAOS-M', 'KM-001', ''],
            ['TUMBLER', '8999876543210', ''],
        ]);
    }

    /**
     * @return array{product_variant_id: int, barcode: string, notes: ?string}
     */
    private function validated(Request $request): array
    {
        $request->merge(['barcode' => trim((string) $request->input('barcode'))]);

        $data = $request->validate([
            'product_variant_id' => ['required', 'integer', Rule::exists('product_variants', 'id')],
            'barcode' => ['required', 'string', 'alpha_dash', 'max:50'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [], ['product_variant_id' => 'varian', 'barcode' => 'barcode', 'notes' => 'catatan']);

        $this->ensureFree($data['barcode'], 'barcode');

        return ['product_variant_id' => (int) $data['product_variant_id'], 'barcode' => $data['barcode'], 'notes' => $data['notes'] ?? null];
    }

    private function ensureFree(string $code, string $field): void
    {
        if ($owner = Barcodes::takenBy($code)) {
            throw ValidationException::withMessages([$field => "Barcode {$code} sudah dipakai {$owner}."]);
        }
    }

    private function closeMiss(BarcodeScanMiss $miss, string $resolution, ?string $note, int $userId): void
    {
        $miss->update([
            'resolved_at' => now(),
            'resolved_by' => $userId,
            'resolution' => $resolution,
            'resolution_note' => $note,
        ]);
    }
}
