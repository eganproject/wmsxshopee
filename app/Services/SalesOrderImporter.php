<?php

namespace App\Services;

use App\Models\Bundle;
use App\Models\Courier;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Support\SpreadsheetImport;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Impor pesanan manual dari Excel (.xlsx) atau CSV. Satu baris file = satu barang;
 * baris dengan no. pesanan yang sama digabung jadi satu pesanan. Semua baris
 * dicek dulu: bila ada yang salah, tidak ada pesanan yang dibuat.
 */
class SalesOrderImporter
{
    /** Kolom template, urut sesuai file contoh. */
    public const COLUMNS = [
        'no_pesanan' => 'No. pesanan marketplace. Baris dengan nomor sama digabung jadi satu pesanan.',
        'tanggal' => 'Tanggal pesanan (YYYY-MM-DD atau DD/MM/YYYY). Kosong = hari ini.',
        'toko' => 'Nama saluran / toko, cth. Shopee Toko A.',
        'nama_pembeli' => 'Wajib.',
        'telepon' => 'Opsional.',
        'alamat' => 'Alamat kirim.',
        'kurir' => 'Kode atau nama kurir di master kurir. Opsional.',
        'layanan' => 'Layanan kurir, cth. REG. Opsional.',
        'ongkir' => 'Ongkos kirim. Opsional.',
        'sku' => 'Wajib. SKU varian, SKU produk paket, atau barcode.',
        'qty' => 'Wajib. Bilangan bulat lebih dari 0.',
        'harga' => 'Harga satuan. Opsional.',
        'catatan' => 'Catatan pesanan. Opsional.',
    ];

    /** Nama kolom alternatif yang juga dikenali. */
    private const ALIASES = [
        'no_pesanan' => ['no_pesanan', 'nomor_pesanan', 'no_order', 'order_id', 'order_sn', 'no_pesanan_marketplace'],
        'tanggal' => ['tanggal', 'tanggal_pesanan', 'tgl', 'order_date'],
        'toko' => ['toko', 'saluran', 'channel', 'nama_toko', 'saluran_toko'],
        'nama_pembeli' => ['nama_pembeli', 'pembeli', 'nama_penerima', 'penerima', 'customer'],
        'telepon' => ['telepon', 'no_telepon', 'hp', 'no_hp', 'phone'],
        'alamat' => ['alamat', 'alamat_kirim', 'alamat_pengiriman', 'address'],
        'kurir' => ['kurir', 'ekspedisi', 'courier'],
        'layanan' => ['layanan', 'service'],
        'ongkir' => ['ongkir', 'ongkos_kirim', 'shipping_fee'],
        'sku' => ['sku', 'kode_barang', 'barcode', 'sku_varian'],
        'qty' => ['qty', 'jumlah', 'kuantitas', 'quantity'],
        'harga' => ['harga', 'harga_satuan', 'price'],
        'catatan' => ['catatan', 'keterangan', 'notes'],
    ];

    public function __construct(private SalesOrderService $orders) {}

    /**
     * @return array{created: array<int, string>, skipped: array<int, string>, unconfirmed: array<int, string>}
     *
     * @throws ValidationException bila file tidak terbaca atau ada baris yang salah
     */
    public function import(string $path, string $extension, int $warehouseId, bool $confirm, ?int $userId): array
    {
        $groups = $this->parse(SpreadsheetImport::records($path, $extension, self::ALIASES, ['nama_pembeli', 'sku', 'qty'], SpreadsheetImport::MAX_ROWS, 'File tidak berisi baris pesanan.'));

        $created = [];
        $skipped = [];

        DB::transaction(function () use ($groups, $warehouseId, $userId, &$created, &$skipped) {
            foreach ($groups as $group) {
                $header = $group['header'];

                if ($header['external_number'] && ($existing = SalesOrder::duplicateOf($header['channel_name'], $header['external_number']))) {
                    $skipped[] = "{$header['external_number']} (sudah ada di {$existing})";

                    continue;
                }

                $order = SalesOrder::create([
                    ...$header,
                    'warehouse_id' => $warehouseId,
                    'number' => SalesOrder::nextNumber(),
                    'status' => 'draft',
                    'created_by' => $userId,
                ]);
                $this->orders->fillLines($order, $group['items'], $group['bundles']);
                $created[$order->id] = $order->number;
            }
        });

        // Konfirmasi per pesanan di luar transaksi impor: pesanan yang stoknya kurang tetap draft.
        $unconfirmed = [];
        if ($confirm) {
            foreach ($created as $id => $number) {
                try {
                    $this->orders->confirm(SalesOrder::findOrFail($id));
                } catch (ValidationException $e) {
                    $unconfirmed[] = "{$number}: ".collect($e->errors())->flatten()->first();
                }
            }
        }

        return ['created' => array_values($created), 'skipped' => $skipped, 'unconfirmed' => $unconfirmed];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $records
     * @return Collection<int, array{header: array<string, mixed>, items: array<int, array<string, mixed>>, bundles: array<int, array<string, mixed>>}>
     *
     * @throws ValidationException
     */
    private function parse(Collection $records): Collection
    {
        $codes = $records->pluck('sku')->filter()->map(fn ($v) => mb_strtoupper((string) $v))->unique()->values();
        $variants = ProductVariant::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereIn('sku', $codes)->orWhereIn('barcode', $codes))
            ->get(['id', 'sku', 'barcode']);
        $variantByCode = $variants->keyBy(fn ($v) => mb_strtoupper($v->sku))
            ->union($variants->filter(fn ($v) => $v->barcode)->keyBy(fn ($v) => mb_strtoupper($v->barcode)));

        $bundles = Bundle::query()
            ->where('is_active', true)
            ->whereHas('items')
            ->where(fn ($q) => $q->whereIn('sku', $codes)->orWhereIn('barcode', $codes))
            ->get(['id', 'sku', 'barcode']);
        $bundleByCode = $bundles->keyBy(fn ($b) => mb_strtoupper($b->sku))
            ->union($bundles->filter(fn ($b) => $b->barcode)->keyBy(fn ($b) => mb_strtoupper($b->barcode)));

        $couriers = Courier::where('is_active', true)->get(['id', 'code', 'name']);
        $courierByName = $couriers->keyBy(fn ($c) => mb_strtoupper($c->code))->union($couriers->keyBy(fn ($c) => mb_strtoupper($c->name)));

        $errors = [];
        $groups = [];

        foreach ($records as $record) {
            $line = $record['line'];
            $sku = mb_strtoupper((string) ($record['sku'] ?? ''));
            $variant = $sku !== '' ? $variantByCode->get($sku) : null;
            $bundle = $sku !== '' && ! $variant ? $bundleByCode->get($sku) : null;
            $qty = $record['qty'] ?? null;
            $date = $this->date($record['tanggal'] ?? null);
            $courier = filled($record['kurir'] ?? null) ? $courierByName->get(mb_strtoupper((string) $record['kurir'])) : null;

            if (blank($record['nama_pembeli'] ?? null)) {
                $errors[] = "Baris {$line}: nama pembeli wajib diisi.";
            }
            if ($sku === '') {
                $errors[] = "Baris {$line}: SKU wajib diisi.";
            } elseif (! $variant && ! $bundle) {
                $errors[] = "Baris {$line}: SKU {$record['sku']} tidak ditemukan atau nonaktif.";
            }
            if (! is_numeric($qty) || (int) $qty != $qty || (int) $qty < 1) {
                $errors[] = "Baris {$line}: qty harus bilangan bulat lebih dari 0.";
            }
            if ($date === false) {
                $errors[] = "Baris {$line}: tanggal {$record['tanggal']} tidak dikenali (pakai YYYY-MM-DD atau DD/MM/YYYY).";
            } elseif ($date && $date->isFuture()) {
                $errors[] = "Baris {$line}: tanggal tidak boleh di masa depan.";
            }
            if (filled($record['kurir'] ?? null) && ! $courier) {
                $errors[] = "Baris {$line}: kurir {$record['kurir']} tidak ada di master kurir.";
            }
            foreach (['harga', 'ongkir'] as $money) {
                if (filled($record[$money] ?? null) && (! is_numeric($record[$money]) || $record[$money] < 0)) {
                    $errors[] = "Baris {$line}: {$money} harus angka.";
                }
            }

            if (count($errors) >= 50) {
                break;
            }
            if (! $variant && ! $bundle) {
                continue;
            }

            $channel = filled($record['toko'] ?? null) ? mb_substr((string) $record['toko'], 0, 100) : null;
            $external = filled($record['no_pesanan'] ?? null) ? mb_substr((string) $record['no_pesanan'], 0, 100) : null;
            $key = $external ? mb_strtoupper(($channel ?? '').'|'.$external) : "line-{$line}";

            $groups[$key] ??= [
                'header' => [
                    'order_date' => ($date ?: now())->toDateString(),
                    'channel_name' => $channel,
                    'external_number' => $external,
                    'customer_name' => mb_substr((string) ($record['nama_pembeli'] ?? ''), 0, 150),
                    'customer_phone' => filled($record['telepon'] ?? null) ? mb_substr((string) $record['telepon'], 0, 30) : null,
                    'shipping_address' => $record['alamat'] ?? null,
                    'courier_id' => $courier?->id,
                    'courier_service' => filled($record['layanan'] ?? null) ? mb_substr((string) $record['layanan'], 0, 50) : null,
                    'shipping_fee' => is_numeric($record['ongkir'] ?? null) ? $record['ongkir'] : 0,
                    'notes' => $record['catatan'] ?? null,
                ],
                'items' => [],
                'bundles' => [],
            ];
            $row = [
                'qty' => (int) $qty,
                'price' => is_numeric($record['harga'] ?? null) ? $record['harga'] : 0,
            ];
            if ($variant) {
                $groups[$key]['items'][] = ['product_variant_id' => $variant->id, ...$row];
            } else {
                $groups[$key]['bundles'][] = ['bundle_id' => $bundle->id, ...$row];
            }
        }

        if ($errors) {
            throw ValidationException::withMessages(['rows' => $errors]);
        }

        return collect($groups)->values();
    }

    /** @return Carbon|null|false null = kosong, false = tidak dikenali */
    private function date(mixed $value): Carbon|null|false
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->startOfDay();
        }
        // Tanggal Excel yang terbaca sebagai angka seri.
        if (is_numeric($value) && $value > 20000 && $value < 80000) {
            return Carbon::create(1899, 12, 30)->addDays((int) $value);
        }

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d', 'Y-m-d H:i:s', 'd/m/Y H:i'] as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, (string) $value);
                if ($date && $date->format($format) === (string) $value) {
                    return $date->startOfDay();
                }
            } catch (Throwable) {
                // coba format berikutnya
            }
        }

        return false;
    }
}
