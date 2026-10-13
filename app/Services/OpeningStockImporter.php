<?php

namespace App\Services;

use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\WarehouseLocation;
use App\Support\SpreadsheetImport;
use Illuminate\Validation\ValidationException;

/**
 * Impor stok awal dari Excel/CSV: semua baris dicatat sebagai satu dokumen
 * Barang Masuk di gudang yang dipilih, sehingga tetap tercatat di kartu stok.
 * Lokasi bin opsional (kosong = "Tanpa bin"). Satu baris salah = tidak ada
 * yang disimpan.
 */
class OpeningStockImporter
{
    public const MAX_ROWS = 2000;

    public const COLUMNS = [
        'sku' => 'Wajib. SKU varian atau barcode.',
        'lokasi' => 'Kode lokasi bin di gudang terpilih. Kosong = Tanpa bin.',
        'qty' => 'Wajib. Bilangan bulat lebih dari 0. Ditambahkan ke saldo yang ada.',
        'catatan' => 'Keterangan baris. Opsional.',
    ];

    private const ALIASES = [
        'sku' => ['sku_varian', 'kode_barang', 'barcode'],
        'lokasi' => ['bin', 'lokasi_bin', 'kode_lokasi', 'location'],
        'qty' => ['jumlah', 'stok', 'stok_awal', 'kuantitas', 'quantity'],
        'catatan' => ['keterangan', 'notes'],
    ];

    public function __construct(private StockService $stock) {}

    /**
     * @param  array{warehouse_id: int, movement_date: string, reference: ?string, notes: ?string, created_by: ?int}  $header
     *
     * @throws ValidationException
     */
    public function import(string $path, string $extension, array $header): StockMovement
    {
        $records = SpreadsheetImport::records($path, $extension, self::ALIASES, ['sku', 'qty'], self::MAX_ROWS, 'File tidak berisi baris stok.');

        $codes = $records->map(fn ($r) => mb_strtoupper((string) SpreadsheetImport::text($r['sku'])))->filter()->unique()->values();
        $variants = ProductVariant::query()
            ->with('product:id,is_active')
            ->where(fn ($q) => $q->whereIn('sku', $codes)->orWhereIn('barcode', $codes))
            ->get(['id', 'product_id', 'sku', 'barcode', 'is_active']);
        $variantByCode = $variants->keyBy(fn ($v) => mb_strtoupper($v->sku))
            ->union($variants->filter(fn ($v) => $v->barcode)->keyBy(fn ($v) => mb_strtoupper($v->barcode)));

        $locations = WarehouseLocation::where('warehouse_id', $header['warehouse_id'])->get(['id', 'code', 'is_active'])
            ->keyBy(fn ($l) => mb_strtoupper($l->code));

        $errors = [];
        $items = [];

        foreach ($records as $record) {
            $line = $record['line'];
            $sku = SpreadsheetImport::text($record['sku']);
            $variant = $sku !== null ? $variantByCode->get(mb_strtoupper($sku)) : null;
            $locationCode = SpreadsheetImport::text($record['lokasi']);
            $location = $locationCode !== null ? $locations->get(mb_strtoupper($locationCode)) : null;
            $qty = $record['qty'];

            if ($sku === null) {
                $errors[] = "Baris {$line}: SKU wajib diisi.";
            } elseif (! $variant) {
                $errors[] = "Baris {$line}: SKU {$sku} tidak ditemukan.";
            } elseif (! $variant->is_active || ! $variant->product?->is_active) {
                $errors[] = "Baris {$line}: SKU {$sku} nonaktif.";
            }
            if ($locationCode !== null && ! $location) {
                $errors[] = "Baris {$line}: lokasi {$locationCode} tidak ada di gudang ini.";
            } elseif ($location && ! $location->is_active) {
                $errors[] = "Baris {$line}: lokasi {$locationCode} nonaktif.";
            }
            if (! is_numeric($qty) || (int) $qty != $qty || (int) $qty < 1 || (int) $qty > 1000000) {
                $errors[] = "Baris {$line}: qty harus bilangan bulat 1 sampai 1.000.000.";
            }

            if (count($errors) >= 50) {
                break;
            }

            if ($variant && ! $errors) {
                $items[] = [
                    'product_variant_id' => $variant->id,
                    'to_location_id' => $location?->id,
                    'qty' => (int) $qty,
                    'notes' => filled($record['catatan']) ? mb_substr((string) $record['catatan'], 0, 255) : null,
                ];
            }
        }

        if ($errors) {
            throw ValidationException::withMessages(['rows' => $errors]);
        }

        return $this->stock->record(['type' => 'in', ...$header], $items);
    }
}
