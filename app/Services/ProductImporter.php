<?php

namespace App\Services;

use App\Models\Bundle;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Support\Activity;
use App\Support\SpreadsheetImport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Impor massal master produk dari Excel/CSV. Satu baris = satu varian; baris
 * dengan SKU induk sama digabung jadi satu produk. SKU induk yang sudah ada
 * diperbarui (kolom kosong tidak mengubah nilai lama) dan varian baru
 * ditambahkan; varian yang tidak ada di file dibiarkan. Semua baris dicek
 * dulu: bila ada yang salah, tidak ada yang disimpan.
 */
class ProductImporter
{
    public const MAX_ROWS = 2000;

    /** Kolom template, urut sesuai file contoh. */
    public const COLUMNS = [
        'sku_induk' => 'Wajib. SKU produk. Baris dengan SKU induk sama jadi varian dari satu produk.',
        'nama_produk' => 'Wajib untuk produk baru.',
        'kategori' => 'Opsional.',
        'merek' => 'Opsional.',
        'satuan' => 'Kode atau nama satuan, cth. PCS. Wajib untuk produk baru.',
        'nama_varian' => 'Isi untuk produk bervarian, cth. M atau Merah. Kosong = produk tanpa varian (cukup satu baris).',
        'sku_varian' => 'Wajib bila nama varian diisi. Produk tanpa varian memakai SKU induk.',
        'barcode' => 'Opsional, unik.',
        'berat_gram' => 'Opsional, bilangan bulat.',
        'panjang_cm' => 'Opsional.',
        'lebar_cm' => 'Opsional.',
        'tinggi_cm' => 'Opsional.',
        'stok_minimum' => 'Opsional, bilangan bulat.',
        'aktif' => 'Ya / Tidak. Kosong = Ya untuk data baru, tidak berubah untuk data lama.',
        'deskripsi' => 'Opsional.',
    ];

    private const ALIASES = [
        'sku_induk' => ['sku_produk', 'parent_sku', 'kode_produk'],
        'nama_produk' => ['nama', 'produk', 'product_name'],
        'kategori' => ['category'],
        'merek' => ['brand'],
        'satuan' => ['unit', 'uom'],
        'nama_varian' => ['varian', 'variant', 'variasi'],
        'sku_varian' => ['sku', 'variant_sku'],
        'barcode' => ['ean', 'kode_barcode'],
        'berat_gram' => ['berat', 'weight', 'berat_g'],
        'panjang_cm' => ['panjang', 'length'],
        'lebar_cm' => ['lebar', 'width'],
        'tinggi_cm' => ['tinggi', 'height'],
        'stok_minimum' => ['min_stok', 'stok_min', 'min_stock'],
        'aktif' => ['status', 'is_active'],
        'deskripsi' => ['description', 'keterangan'],
    ];

    /** Kolom angka: [kolom, atribut varian, bulat?, maks]. */
    private const NUMBERS = [
        ['berat_gram', 'weight_gram', true, 1000000],
        ['panjang_cm', 'length_cm', false, 10000],
        ['lebar_cm', 'width_cm', false, 10000],
        ['tinggi_cm', 'height_cm', false, 10000],
        ['stok_minimum', 'min_stock', true, 1000000],
    ];

    /**
     * @return array{created: array<int, string>, updated: array<int, string>, variants: int}
     *
     * @throws ValidationException
     */
    public function import(string $path, string $extension, bool $canUpdate): array
    {
        $records = SpreadsheetImport::records($path, $extension, self::ALIASES, ['sku_induk'], self::MAX_ROWS, 'File tidak berisi baris produk.');
        $plans = $this->plan($records, $canUpdate);

        $result = ['created' => [], 'updated' => [], 'variants' => 0];

        DB::transaction(function () use ($plans, &$result) {
            foreach ($plans as $plan) {
                $product = $plan['product'];
                $isNew = ! $product->exists;

                $save = function () use ($product, $plan, &$result) {
                    $product->fill($plan['attributes'])->save();
                    $nextOrder = (int) $product->variants()->max('sort_order') + 1;

                    foreach ($plan['variants'] as $row) {
                        if ($row['model']) {
                            $row['model']->fill($row['attributes'])->save();
                        } else {
                            $product->variants()->create([...$row['attributes'], 'sort_order' => $nextOrder++]);
                            $result['variants']++;
                        }
                    }
                };

                $isNew ? $save() : Activity::track($product, $save);
                $result[$isNew ? 'created' : 'updated'][] = $product->sku;
            }
        });

        return $result;
    }

    /**
     * Cek semua baris dan susun rencana simpan per produk.
     *
     * @param  Collection<int, array<string, mixed>>  $records
     * @return array<int, array{product: Product, attributes: array<string, mixed>, variants: array<int, array{model: ?ProductVariant, attributes: array<string, mixed>}>}>
     *
     * @throws ValidationException
     */
    private function plan(Collection $records, bool $canUpdate): array
    {
        $records = $records->map(fn ($r) => [
            ...$r,
            'sku_induk' => mb_strtoupper((string) SpreadsheetImport::text($r['sku_induk'])),
            'sku_varian' => mb_strtoupper((string) SpreadsheetImport::text($r['sku_varian'])),
            'barcode' => SpreadsheetImport::text($r['barcode']),
            'nama_varian' => SpreadsheetImport::text($r['nama_varian']),
        ]);

        $parentSkus = $records->pluck('sku_induk')->filter()->unique();
        $products = Product::with('variants')->whereIn('sku', $parentSkus)->get()->keyBy(fn ($p) => mb_strtoupper($p->sku));

        $variantSkus = $records->map(fn ($r) => $r['sku_varian'] ?: $r['sku_induk'])->filter()->unique();
        $variantBySku = ProductVariant::whereIn('sku', $variantSkus)->get()->keyBy(fn ($v) => mb_strtoupper($v->sku));
        $barcodes = $records->pluck('barcode')->filter()->unique();
        $variantByBarcode = ProductVariant::whereIn('barcode', $barcodes)->get()->keyBy(fn ($v) => mb_strtoupper($v->barcode));
        $bundleCodes = Bundle::where(fn ($q) => $q->whereIn('sku', $variantSkus)->orWhereIn('barcode', $variantSkus->merge($barcodes)))
            ->get(['sku', 'barcode'])
            ->flatMap(fn ($b) => [mb_strtoupper($b->sku), mb_strtoupper((string) $b->barcode)])
            ->filter()->flip();

        $units = Unit::where('is_active', true)->get();
        $unitByName = $units->keyBy(fn ($u) => mb_strtoupper($u->code))->union($units->keyBy(fn ($u) => mb_strtoupper($u->name)));

        $errors = [];
        $plans = [];
        $seenVariant = [];
        $seenBarcode = [];
        $code = '/\A[\pL\pM\pN_-]+\z/u';

        foreach ($records->groupBy('sku_induk') as $parentSku => $rows) {
            $first = $rows->first();
            $line = $first['line'];

            if ($parentSku === '') {
                foreach ($rows as $row) {
                    $errors[] = "Baris {$row['line']}: SKU induk wajib diisi.";
                }

                continue;
            }
            if (! preg_match($code, $parentSku) || mb_strlen($parentSku) > 50) {
                $errors[] = "Baris {$line}: SKU induk {$parentSku} hanya boleh huruf, angka, - dan _ (maks. 50 karakter).";

                continue;
            }

            $product = $products->get($parentSku);
            if ($product && ! $canUpdate) {
                $errors[] = "Baris {$line}: SKU induk {$parentSku} sudah ada. Anda tidak punya hak mengubah produk.";

                continue;
            }

            $pick = fn (string $key) => $rows->pluck($key)->first(fn ($v) => $v !== null && $v !== '');
            $hasVariants = $rows->contains(fn ($r) => filled($r['nama_varian']));
            $isNew = ! $product;
            $product ??= new Product(['has_variants' => $hasVariants]);

            if (! $isNew && $product->has_variants !== $hasVariants) {
                $errors[] = "Baris {$line}: produk {$parentSku} sudah terdaftar sebagai produk ".($product->has_variants ? 'bervarian, isi nama varian di setiap barisnya.' : 'tanpa varian, kosongkan nama varian atau ubah lewat form produk.');

                continue;
            }

            // Kolom produk: diambil dari isian pertama yang tidak kosong di grup.
            $attributes = ['sku' => $parentSku];
            foreach (['nama_produk' => 'name', 'kategori' => 'category', 'merek' => 'brand', 'deskripsi' => 'description'] as $column => $attribute) {
                $value = $pick($column);
                if ($value !== null) {
                    $attributes[$attribute] = mb_substr((string) SpreadsheetImport::text($value), 0, [
                        'name' => 255, 'category' => 100, 'brand' => 100, 'description' => 5000,
                    ][$attribute]);
                }
            }
            if ($isNew && blank($attributes['name'] ?? null)) {
                $errors[] = "Baris {$line}: nama produk wajib diisi untuk produk baru {$parentSku}.";
            }

            $unitName = SpreadsheetImport::text($pick('satuan'));
            if ($unitName !== null) {
                $unit = $unitByName->get(mb_strtoupper($unitName));
                if ($unit) {
                    $attributes['unit_id'] = $unit->id;
                } else {
                    $errors[] = "Baris {$line}: satuan {$unitName} tidak ada di master satuan.";
                }
            } elseif ($isNew) {
                $errors[] = "Baris {$line}: satuan wajib diisi untuk produk baru {$parentSku}.";
            }

            if ($isNew) {
                $attributes['has_variants'] = $hasVariants;
            }

            if (! $hasVariants && $rows->count() > 1) {
                $errors[] = "Baris {$line}: produk tanpa varian {$parentSku} hanya boleh satu baris. Isi nama varian bila produk ini punya varian.";

                continue;
            }

            $variants = [];
            $names = [];
            foreach ($rows as $row) {
                $rowLine = $row['line'];
                $sku = $hasVariants ? $row['sku_varian'] : ($row['sku_varian'] ?: $parentSku);
                $active = SpreadsheetImport::boolean($row['aktif']);

                if (filled($row['aktif']) && $active === null) {
                    $errors[] = "Baris {$rowLine}: kolom aktif harus Ya atau Tidak.";
                }

                if ($hasVariants) {
                    if ($sku === '') {
                        $errors[] = "Baris {$rowLine}: SKU varian wajib diisi untuk varian {$row['nama_varian']}.";

                        continue;
                    }
                    $nameKey = mb_strtoupper((string) $row['nama_varian']);
                    if ($nameKey === '') {
                        $errors[] = "Baris {$rowLine}: nama varian wajib diisi (produk {$parentSku} bervarian).";
                    } elseif (isset($names[$nameKey])) {
                        $errors[] = "Baris {$rowLine}: nama varian {$row['nama_varian']} dobel di produk {$parentSku}.";
                    }
                    $names[$nameKey] = true;
                } elseif ($sku !== $parentSku) {
                    $errors[] = "Baris {$rowLine}: produk tanpa varian memakai SKU induk; kosongkan SKU varian atau isi nama varian.";

                    continue;
                }

                if (! preg_match($code, $sku) || mb_strlen($sku) > 50) {
                    $errors[] = "Baris {$rowLine}: SKU varian {$sku} hanya boleh huruf, angka, - dan _ (maks. 50 karakter).";

                    continue;
                }
                if (isset($seenVariant[$sku])) {
                    $errors[] = "Baris {$rowLine}: SKU varian {$sku} dobel dengan baris {$seenVariant[$sku]}.";

                    continue;
                }
                $seenVariant[$sku] = $rowLine;

                $existing = $variantBySku->get($sku);
                if ($existing && $existing->product_id !== $product->id) {
                    $errors[] = "Baris {$rowLine}: SKU varian {$sku} sudah dipakai produk lain.";

                    continue;
                }
                if (isset($bundleCodes[$sku])) {
                    $errors[] = "Baris {$rowLine}: SKU {$sku} sudah dipakai produk paket.";
                }

                $variant = ['sku' => $sku];
                if ($hasVariants && filled($row['nama_varian'])) {
                    $variant['name'] = mb_substr($row['nama_varian'], 0, 100);
                }

                $barcode = $row['barcode'];
                if ($barcode !== null) {
                    $key = mb_strtoupper($barcode);
                    $owner = $variantByBarcode->get($key);
                    if (! preg_match($code, $barcode) || mb_strlen($barcode) > 50) {
                        $errors[] = "Baris {$rowLine}: barcode {$barcode} hanya boleh huruf, angka, - dan _ (maks. 50 karakter).";
                    } elseif (isset($seenBarcode[$key])) {
                        $errors[] = "Baris {$rowLine}: barcode {$barcode} dobel dengan baris {$seenBarcode[$key]}.";
                    } elseif (($owner && mb_strtoupper($owner->sku) !== $sku) || isset($bundleCodes[$key])) {
                        $errors[] = "Baris {$rowLine}: barcode {$barcode} sudah dipakai produk atau paket lain.";
                    } else {
                        $variant['barcode'] = $barcode;
                    }
                    $seenBarcode[$key] = $rowLine;
                }

                foreach (self::NUMBERS as [$column, $attribute, $integer, $max]) {
                    $value = SpreadsheetImport::number($row[$column]);
                    if ($value === null || $value === '') {
                        continue;
                    }
                    if (! is_numeric($value) || $value < 0 || $value > $max || ($integer && (int) $value != $value)) {
                        $errors[] = "Baris {$rowLine}: ".str_replace('_', ' ', $column).' harus angka 0 sampai '.number_format($max, 0, ',', '.').($integer ? ' (bilangan bulat).' : '.');
                    } else {
                        $variant[$attribute] = $integer ? (int) $value : round((float) $value, 2);
                    }
                }

                // Status: varian untuk produk bervarian, produk untuk produk tanpa varian.
                if ($hasVariants) {
                    if ($active !== null || ! $existing) {
                        $variant['is_active'] = $active ?? true;
                    }
                } else {
                    $variant['is_active'] = true;
                    if ($active !== null || $isNew) {
                        $attributes['is_active'] = $active ?? true;
                    }
                }

                $variants[] = ['model' => $existing, 'attributes' => $variant];
            }

            if ($hasVariants && $isNew) {
                $attributes['is_active'] = true;
            }

            $total = ($isNew ? 0 : $product->variants->count()) + collect($variants)->whereNull('model')->count();
            if ($total > 100) {
                $errors[] = "Baris {$line}: produk {$parentSku} melebihi 100 varian.";
            }

            if (count($errors) >= 50) {
                break;
            }

            $plans[] = ['product' => $product, 'attributes' => $attributes, 'variants' => $variants];
        }

        if ($errors) {
            throw ValidationException::withMessages(['rows' => array_slice($errors, 0, 50)]);
        }

        return $plans;
    }
}
