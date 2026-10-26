<?php

namespace App\Services;

use App\Models\Bundle;
use App\Models\ProductVariant;
use App\Support\Activity;
use App\Support\SpreadsheetImport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Impor massal produk paket dari Excel/CSV. Satu baris = satu barang isi paket;
 * baris dengan SKU paket sama digabung jadi satu paket. Paket yang sudah ada
 * diperbarui: kolom kosong tidak mengubah nilai lama, dan bila file berisi
 * barang isi, isi paket diganti seluruhnya dengan isi di file. Semua baris
 * dicek dulu: bila ada yang salah, tidak ada yang disimpan.
 */
class BundleImporter
{
    public const MAX_ROWS = SpreadsheetImport::MAX_ROWS;

    public const MAX_ITEMS = 50;

    /** Kolom template, urut sesuai file contoh. */
    public const COLUMNS = [
        'sku_paket' => 'Wajib. SKU paket. Baris dengan SKU paket sama jadi satu paket.',
        'nama_paket' => 'Wajib untuk paket baru.',
        'barcode' => 'Opsional, unik.',
        'harga' => 'Opsional. Harga jual paket.',
        'sku_isi' => 'Wajib untuk paket baru. SKU varian barang isi (atau barcodenya), harus sudah ada di master produk.',
        'jumlah_isi' => 'Jumlah barang isi per 1 paket. Kosong = 1.',
        'aktif' => 'Ya / Tidak. Kosong = Ya untuk paket baru, tidak berubah untuk paket lama.',
        'deskripsi' => 'Opsional.',
    ];

    private const ALIASES = [
        'sku_paket' => ['sku_bundle', 'sku_bundling', 'bundle_sku', 'kode_paket'],
        'nama_paket' => ['nama', 'nama_bundle', 'nama_bundling', 'bundle_name'],
        'barcode' => ['barcode_paket', 'ean'],
        'harga' => ['harga_jual', 'price'],
        'sku_isi' => ['sku_komponen', 'sku_barang', 'sku_varian', 'component_sku', 'isi'],
        'jumlah_isi' => ['qty', 'qty_isi', 'jumlah', 'jumlah_komponen'],
        'aktif' => ['status', 'is_active'],
        'deskripsi' => ['description', 'keterangan'],
    ];

    /**
     * @return array{created: array<int, string>, updated: array<int, string>}
     *
     * @throws ValidationException
     */
    public function import(string $path, string $extension, bool $canUpdate): array
    {
        $records = SpreadsheetImport::records($path, $extension, self::ALIASES, ['sku_paket'], self::MAX_ROWS, 'File tidak berisi baris paket.');
        $plans = $this->plan($records, $canUpdate);

        $result = ['created' => [], 'updated' => []];

        DB::transaction(function () use ($plans, &$result) {
            foreach ($plans as $plan) {
                $bundle = $plan['bundle'];
                $isNew = ! $bundle->exists;

                $save = function () use ($bundle, $plan) {
                    $bundle->fill($plan['attributes'])->save();

                    // Isi paket di pesanan lama tersimpan sendiri, jadi aman diganti seluruhnya.
                    if ($plan['items'] !== null) {
                        $bundle->items()->delete();
                        $bundle->items()->createMany(collect($plan['items'])->values()->map(fn ($row, $index) => [...$row, 'sort_order' => $index])->all());
                    }
                };

                $isNew ? $save() : Activity::track($bundle, $save);
                $result[$isNew ? 'created' : 'updated'][] = $bundle->sku;
            }
        });

        return $result;
    }

    /**
     * Cek semua baris dan susun rencana simpan per paket.
     *
     * @param  Collection<int, array<string, mixed>>  $records
     * @return array<int, array{bundle: Bundle, attributes: array<string, mixed>, items: ?array<int, array{product_variant_id: int, qty: int}>}>
     *
     * @throws ValidationException
     */
    private function plan(Collection $records, bool $canUpdate): array
    {
        $records = $records->map(fn ($r) => [
            ...$r,
            'sku_paket' => mb_strtoupper((string) SpreadsheetImport::text($r['sku_paket'])),
            'sku_isi' => mb_strtoupper((string) SpreadsheetImport::text($r['sku_isi'])),
            'barcode' => SpreadsheetImport::text($r['barcode']),
        ]);

        $bundleSkus = $records->pluck('sku_paket')->filter()->unique();
        $barcodes = $records->pluck('barcode')->filter()->unique();
        $bundles = Bundle::whereIn('sku', $bundleSkus)->get()->keyBy(fn ($b) => mb_strtoupper($b->sku));
        $bundleByBarcode = Bundle::whereIn('barcode', $barcodes)->get()->keyBy(fn ($b) => mb_strtoupper((string) $b->barcode));

        // Kode yang sudah dipakai varian produk: SKU paket & barcode paket tidak boleh bentrok.
        $variantCodes = ProductVariant::where(fn ($q) => $q->whereIn('sku', $bundleSkus->merge($barcodes))->orWhereIn('barcode', $bundleSkus->merge($barcodes)))
            ->get(['sku', 'barcode'])
            ->flatMap(fn ($v) => [mb_strtoupper($v->sku), mb_strtoupper((string) $v->barcode)])
            ->filter()->flip();

        // Barang isi dicari lewat SKU varian, lalu barcode.
        $componentCodes = $records->pluck('sku_isi')->filter()->unique();
        $variants = ProductVariant::with('product:id,is_active')
            ->where(fn ($q) => $q->whereIn('sku', $componentCodes)->orWhereIn('barcode', $componentCodes))
            ->get();
        $variantByCode = $variants->keyBy(fn ($v) => mb_strtoupper($v->sku))
            ->union($variants->filter(fn ($v) => $v->barcode)->keyBy(fn ($v) => mb_strtoupper($v->barcode)));

        $errors = [];
        $plans = [];
        $seenBarcode = [];
        $code = '/\A[\pL\pM\pN_-]+\z/u';

        foreach ($records->groupBy('sku_paket') as $sku => $rows) {
            $line = $rows->first()['line'];

            if ($sku === '') {
                foreach ($rows as $row) {
                    $errors[] = "Baris {$row['line']}: SKU paket wajib diisi.";
                }

                continue;
            }
            if (! preg_match($code, $sku) || mb_strlen($sku) > 50) {
                $errors[] = "Baris {$line}: SKU paket {$sku} hanya boleh huruf, angka, - dan _ (maks. 50 karakter).";

                continue;
            }
            if (isset($variantCodes[$sku])) {
                $errors[] = "Baris {$line}: SKU {$sku} sudah dipakai varian produk.";

                continue;
            }

            $bundle = $bundles->get($sku);
            if ($bundle && ! $canUpdate) {
                $errors[] = "Baris {$line}: SKU paket {$sku} sudah ada. Anda tidak punya hak mengubah produk.";

                continue;
            }
            $isNew = ! $bundle;
            $bundle ??= new Bundle;

            $pick = fn (string $key) => $rows->pluck($key)->first(fn ($v) => $v !== null && $v !== '');
            $attributes = ['sku' => $sku];

            $name = SpreadsheetImport::text($pick('nama_paket'));
            if ($name !== null) {
                $attributes['name'] = mb_substr($name, 0, 255);
            } elseif ($isNew) {
                $errors[] = "Baris {$line}: nama paket wajib diisi untuk paket baru {$sku}.";
            }

            $description = SpreadsheetImport::text($pick('deskripsi'));
            if ($description !== null) {
                $attributes['description'] = mb_substr($description, 0, 2000);
            }

            $price = SpreadsheetImport::number($pick('harga'));
            if ($price !== null && $price !== '') {
                if (! is_numeric($price) || $price < 0 || $price > 999999999) {
                    $errors[] = "Baris {$line}: harga paket {$sku} harus angka 0 sampai 999.999.999.";
                } else {
                    $attributes['price'] = round((float) $price, 2);
                }
            } elseif ($isNew) {
                $attributes['price'] = 0;
            }

            $barcode = SpreadsheetImport::text($pick('barcode'));
            if ($barcode !== null) {
                $key = mb_strtoupper($barcode);
                $owner = $bundleByBarcode->get($key);
                if (! preg_match($code, $barcode) || mb_strlen($barcode) > 50) {
                    $errors[] = "Baris {$line}: barcode {$barcode} hanya boleh huruf, angka, - dan _ (maks. 50 karakter).";
                } elseif (isset($seenBarcode[$key])) {
                    $errors[] = "Baris {$line}: barcode {$barcode} dobel dengan paket di baris {$seenBarcode[$key]}.";
                } elseif (($owner && $owner->id !== $bundle->id) || isset($variantCodes[$key])) {
                    $errors[] = "Baris {$line}: barcode {$barcode} sudah dipakai produk atau paket lain.";
                } else {
                    $attributes['barcode'] = $barcode;
                }
                $seenBarcode[$key] = $line;
            }

            $activeValue = $pick('aktif');
            $active = SpreadsheetImport::boolean($activeValue);
            if ($activeValue !== null && $active === null) {
                $errors[] = "Baris {$line}: kolom aktif harus Ya atau Tidak.";
            } elseif ($active !== null || $isNew) {
                $attributes['is_active'] = $active ?? true;
            }

            // Isi paket. Baris tanpa SKU isi hanya mengubah data paket.
            $items = [];
            $itemErrors = count($errors);
            foreach ($rows as $row) {
                $rowLine = $row['line'];
                $component = $row['sku_isi'];
                $qtyValue = SpreadsheetImport::number($row['jumlah_isi']);

                if ($component === '') {
                    if ($qtyValue !== null && $qtyValue !== '') {
                        $errors[] = "Baris {$rowLine}: jumlah isi diisi tapi SKU isi kosong.";
                    }

                    continue;
                }

                $variant = $variantByCode->get($component);
                if (! $variant) {
                    $errors[] = "Baris {$rowLine}: SKU isi {$component} tidak ada di master produk.";

                    continue;
                }
                if (isset($items[$variant->id])) {
                    $errors[] = "Baris {$rowLine}: barang {$variant->sku} dobel di paket {$sku}; gabungkan jumlahnya di satu baris.";

                    continue;
                }

                $qty = ($qtyValue === null || $qtyValue === '') ? 1 : $qtyValue;
                if (! is_numeric($qty) || (int) $qty != $qty || $qty < 1 || $qty > 10000) {
                    $errors[] = "Baris {$rowLine}: jumlah isi harus bilangan bulat 1 sampai 10.000.";

                    continue;
                }

                $items[$variant->id] = ['product_variant_id' => $variant->id, 'qty' => (int) $qty];
            }

            if ($items === [] && $isNew && count($errors) === $itemErrors) {
                $errors[] = "Baris {$line}: paket baru {$sku} wajib punya minimal satu barang isi (kolom sku_isi).";
            }
            if (count($items) > self::MAX_ITEMS) {
                $errors[] = "Baris {$line}: paket {$sku} melebihi ".self::MAX_ITEMS.' barang isi.';
            }

            if (count($errors) >= 50) {
                break;
            }

            $plans[] = ['bundle' => $bundle, 'attributes' => $attributes, 'items' => $items === [] ? null : array_values($items)];
        }

        if ($errors) {
            throw ValidationException::withMessages(['rows' => array_slice($errors, 0, 50)]);
        }

        return $plans;
    }
}
