<?php

namespace App\Services;

use App\Models\Bundle;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Support\Activity;
use App\Support\SpreadsheetImport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Update massal produk lewat Excel/CSV untuk kolom yang dipilih saja.
 *
 * Alurnya: pengguna memilih kolom, mengunduh template (berisi data saat ini),
 * mengubah nilainya, lalu mengunggah. Kolom yang diperbarui dibaca dari judul
 * kolom file, jadi kolom yang tidak ada di file tidak pernah tersentuh.
 * Berbeda dengan impor produk biasa, sel kosong di kolom terpilih berarti
 * "kosongkan nilainya" (kecuali kolom wajib). SKU hanya kunci pencarian dan
 * tidak pernah diubah; produk atau varian baru tidak dibuat di sini.
 *
 * Semua baris dicek dulu ({@see analyze()}) dan hasilnya ditampilkan sebagai
 * pratinjau; perubahan baru disimpan lewat {@see apply()}.
 */
class ProductBulkUpdater
{
    public const LEVEL_PRODUCT = 'product';

    public const LEVEL_VARIANT = 'variant';

    /** Kunci baris per level: SKU induk untuk data produk, SKU varian untuk data varian. */
    public const KEYS = [
        self::LEVEL_PRODUCT => 'sku_induk',
        self::LEVEL_VARIANT => 'sku',
    ];

    /** Awalan kolom keterangan di template; diabaikan saat diunggah. */
    public const INFO_PREFIX = 'info_';

    private const MAX_ERRORS = 50;

    /**
     * Kolom yang bisa diperbarui, urut sesuai tampilan & template.
     * level: product = berlaku untuk seluruh produk, variant = per SKU varian.
     *
     * @var array<string, array{label: string, level: string, attribute: string, hint: string}>
     */
    public const FIELDS = [
        'nama_produk' => ['label' => 'Nama Produk', 'level' => self::LEVEL_PRODUCT, 'attribute' => 'name', 'hint' => 'Wajib diisi, maks. 255 karakter.'],
        'kategori' => ['label' => 'Kategori', 'level' => self::LEVEL_PRODUCT, 'attribute' => 'category', 'hint' => 'Maks. 100 karakter. Kosongkan untuk menghapus kategori.'],
        'merek' => ['label' => 'Merek', 'level' => self::LEVEL_PRODUCT, 'attribute' => 'brand', 'hint' => 'Maks. 100 karakter. Kosongkan untuk menghapus merek.'],
        'satuan' => ['label' => 'Satuan', 'level' => self::LEVEL_PRODUCT, 'attribute' => 'unit_id', 'hint' => 'Kode atau nama satuan aktif, cth. PCS. Wajib diisi.'],
        'deskripsi' => ['label' => 'Deskripsi', 'level' => self::LEVEL_PRODUCT, 'attribute' => 'description', 'hint' => 'Maks. 5.000 karakter. Kosongkan untuk menghapus.'],
        'status_produk' => ['label' => 'Status Produk', 'level' => self::LEVEL_PRODUCT, 'attribute' => 'is_active', 'hint' => 'Aktif / Nonaktif (atau Ya / Tidak). Wajib diisi.'],
        'catatan' => ['label' => 'Catatan', 'level' => self::LEVEL_PRODUCT, 'attribute' => 'notes', 'hint' => 'Maks. 1.000 karakter. Kosongkan untuk menghapus.'],

        'nama_varian' => ['label' => 'Nama Varian', 'level' => self::LEVEL_VARIANT, 'attribute' => 'name', 'hint' => 'Khusus produk bervarian: wajib dan tidak boleh sama dalam satu produk. Produk tanpa varian: biarkan kosong.'],
        'barcode' => ['label' => 'Barcode', 'level' => self::LEVEL_VARIANT, 'attribute' => 'barcode', 'hint' => 'Huruf, angka, - dan _ (maks. 50), unik. Kosongkan untuk menghapus.'],
        'berat_gram' => ['label' => 'Berat (gram)', 'level' => self::LEVEL_VARIANT, 'attribute' => 'weight_gram', 'hint' => 'Bilangan bulat 0 sampai 1.000.000. Kosongkan untuk menghapus.'],
        'panjang_cm' => ['label' => 'Panjang (cm)', 'level' => self::LEVEL_VARIANT, 'attribute' => 'length_cm', 'hint' => 'Angka 0 sampai 10.000, maks. 2 desimal. Kosongkan untuk menghapus.'],
        'lebar_cm' => ['label' => 'Lebar (cm)', 'level' => self::LEVEL_VARIANT, 'attribute' => 'width_cm', 'hint' => 'Angka 0 sampai 10.000, maks. 2 desimal. Kosongkan untuk menghapus.'],
        'tinggi_cm' => ['label' => 'Tinggi (cm)', 'level' => self::LEVEL_VARIANT, 'attribute' => 'height_cm', 'hint' => 'Angka 0 sampai 10.000, maks. 2 desimal. Kosongkan untuk menghapus.'],
        'stok_minimum' => ['label' => 'Stok Minimum', 'level' => self::LEVEL_VARIANT, 'attribute' => 'min_stock', 'hint' => 'Bilangan bulat 0 sampai 1.000.000. Kosongkan untuk menghapus.'],
        'isi_per_koli' => ['label' => 'Isi per Koli', 'level' => self::LEVEL_VARIANT, 'attribute' => 'pcs_per_carton', 'hint' => 'Bilangan bulat 1 sampai 100.000. Kosongkan bila tidak dijual per koli.'],
        'status_varian' => ['label' => 'Status Varian', 'level' => self::LEVEL_VARIANT, 'attribute' => 'is_active', 'hint' => 'Khusus produk bervarian: Aktif / Nonaktif, wajib. Produk tanpa varian: biarkan kosong, pakai Status Produk.'],
    ];

    /** Kolom angka varian: [bulat?, min, maks]. */
    private const NUMBERS = [
        'berat_gram' => [true, 0, 1000000],
        'panjang_cm' => [false, 0, 10000],
        'lebar_cm' => [false, 0, 10000],
        'tinggi_cm' => [false, 0, 10000],
        'stok_minimum' => [true, 0, 1000000],
        'isi_per_koli' => [true, 1, 100000],
    ];

    private const CODE = '/\A[\pL\pM\pN_-]+\z/u';

    /**
     * Kolom dikelompokkan per level untuk halaman pilih kolom.
     *
     * @return array<string, array<string, array{label: string, level: string, attribute: string, hint: string}>>
     */
    public static function groups(): array
    {
        return collect(self::FIELDS)->groupBy('level', true)->map->all()->all();
    }

    /**
     * Buang kolom tak dikenal & duplikat, lalu urutkan sesuai urutan baku.
     *
     * @param  array<int, mixed>  $fields
     * @return list<string>
     */
    public static function normalize(array $fields): array
    {
        $fields = array_map(fn ($field) => (string) $field, $fields);

        return array_values(array_filter(array_keys(self::FIELDS), fn ($key) => in_array($key, $fields, true)));
    }

    /** Level baris: per varian bila ada satu saja kolom varian, selain itu per produk. */
    public static function level(array $fields): string
    {
        return collect($fields)->contains(fn ($field) => self::FIELDS[$field]['level'] === self::LEVEL_VARIANT)
            ? self::LEVEL_VARIANT
            : self::LEVEL_PRODUCT;
    }

    /**
     * Judul kolom template: kunci, kolom keterangan, lalu kolom terpilih.
     *
     * @param  list<string>  $fields
     * @return list<string>
     */
    public static function header(array $fields): array
    {
        return [self::KEYS[self::level($fields)], ...array_keys(self::infoColumns($fields)), ...$fields];
    }

    /**
     * Kolom keterangan (tidak diimpor) supaya baris mudah dikenali di Excel.
     *
     * @param  list<string>  $fields
     * @return array<string, string> judul kolom => penjelasan
     */
    public static function infoColumns(array $fields): array
    {
        $columns = [];
        if (self::level($fields) === self::LEVEL_VARIANT) {
            $columns[self::INFO_PREFIX.'sku_induk'] = 'SKU induk produk.';
        }
        if (! in_array('nama_produk', $fields, true)) {
            $columns[self::INFO_PREFIX.'nama_produk'] = 'Nama produk saat ini.';
        }
        if (self::level($fields) === self::LEVEL_VARIANT && ! in_array('nama_varian', $fields, true)) {
            $columns[self::INFO_PREFIX.'nama_varian'] = 'Nama varian saat ini (kosong untuk produk tanpa varian).';
        }

        return $columns;
    }

    /**
     * Baris template berisi data saat ini dari produk yang dipilih.
     *
     * @param  Builder<Product>  $query
     * @param  list<string>  $fields
     * @return iterable<array<int, mixed>>
     */
    public static function rows(Builder $query, array $fields): iterable
    {
        $variantLevel = self::level($fields) === self::LEVEL_VARIANT;
        $info = array_keys(self::infoColumns($fields));

        foreach ($query->with(['unit', 'variants'])->orderBy('name')->orderBy('id')->lazy(200) as $product) {
            foreach ($variantLevel ? $product->variants : [null] as $variant) {
                $row = [$variant ? $variant->sku : $product->sku];
                foreach ($info as $column) {
                    $row[] = match ($column) {
                        self::INFO_PREFIX.'sku_induk' => $product->sku,
                        self::INFO_PREFIX.'nama_produk' => $product->name,
                        self::INFO_PREFIX.'nama_varian' => $product->has_variants ? $variant?->name : null,
                    };
                }
                foreach ($fields as $field) {
                    $row[] = self::cellValue($field, $product, $variant);
                }

                yield $row;
            }
        }
    }

    /** Nilai sel template / pratinjau untuk satu kolom. */
    private static function cellValue(string $field, Product $product, ?ProductVariant $variant): mixed
    {
        $attribute = self::FIELDS[$field]['attribute'];

        return match ($field) {
            'satuan' => $product->unit?->code,
            'status_produk' => $product->is_active ? 'Aktif' : 'Nonaktif',
            // Produk tanpa varian tidak punya nama & status varian sendiri.
            'nama_varian' => $product->has_variants ? $variant->name : null,
            'status_varian' => $product->has_variants ? ($variant->is_active ? 'Aktif' : 'Nonaktif') : null,
            'panjang_cm', 'lebar_cm', 'tinggi_cm' => $variant->{$attribute} === null ? null : (float) $variant->{$attribute},
            default => self::FIELDS[$field]['level'] === self::LEVEL_PRODUCT ? $product->{$attribute} : $variant->{$attribute},
        };
    }

    /**
     * Baca & cek file. Tidak ada yang disimpan.
     *
     * @return array{
     *     level: string,
     *     fields: list<string>,
     *     ignored: list<string>,
     *     rows: int,
     *     changed: int,
     *     products: int,
     *     changes: list<array{sku: string, name: string, field: string, old: mixed, new: mixed}>,
     *     counts: array<string, int>,
     *     plans: list<array{product: Product, attributes: array<string, mixed>, variants: list<array{model: ProductVariant, attributes: array<string, mixed>}>}>
     * }
     *
     * @throws ValidationException
     */
    public function analyze(string $path, string $extension): array
    {
        @set_time_limit(SpreadsheetImport::TIME_LIMIT);

        $raw = SpreadsheetImport::readRows($path, $extension);
        $header = collect(array_shift($raw) ?? [])->map(fn ($name) => SpreadsheetImport::normalizeHeader($name));

        $level = $header->contains(self::KEYS[self::LEVEL_VARIANT]) ? self::LEVEL_VARIANT
            : ($header->contains(self::KEYS[self::LEVEL_PRODUCT]) ? self::LEVEL_PRODUCT : null);
        if (! $level) {
            throw ValidationException::withMessages(['file' => 'Kolom kunci tidak ditemukan. File harus punya kolom sku (per varian) atau sku_induk (per produk). Gunakan template yang diunduh dari halaman ini.']);
        }

        $fields = self::normalize($header->all());
        if ($fields === []) {
            throw ValidationException::withMessages(['file' => 'Tidak ada kolom yang bisa diperbarui di file ini. Pilih kolom lalu unduh template dari halaman ini.']);
        }
        if ($level === self::LEVEL_PRODUCT && self::level($fields) === self::LEVEL_VARIANT) {
            $variantFields = collect($fields)->filter(fn ($f) => self::FIELDS[$f]['level'] === self::LEVEL_VARIANT)->implode(', ');
            throw ValidationException::withMessages(['file' => "Kolom {$variantFields} diisi per varian, jadi file harus memakai kolom sku (SKU varian), bukan sku_induk. Unduh ulang template dari halaman ini."]);
        }

        $known = [self::KEYS[$level], ...$fields];
        $ignored = $header->filter(fn ($name) => $name !== '' && ! in_array($name, $known, true) && ! str_starts_with($name, self::INFO_PREFIX))
            ->unique()->values()->all();

        $index = $header->flip();
        $records = collect($raw)
            ->map(fn ($row, $i) => ['line' => $i + 2, ...collect($known)->mapWithKeys(fn ($key) => [$key => SpreadsheetImport::clean($row[$index[$key]] ?? null)])->all()])
            ->reject(fn ($record) => collect($record)->except('line')->every(fn ($value) => $value === null || $value === ''))
            ->values();

        if ($records->isEmpty()) {
            throw ValidationException::withMessages(['file' => 'File tidak berisi baris data di bawah judul kolom.']);
        }
        if ($records->count() > SpreadsheetImport::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => 'Maksimal '.number_format(SpreadsheetImport::MAX_ROWS, 0, ',', '.').' baris per unggahan. Pecah file menjadi beberapa bagian.']);
        }

        return [
            'level' => $level,
            'fields' => $fields,
            'ignored' => $ignored,
            'rows' => $records->count(),
            ...$this->plan($records, $level, $fields),
        ];
    }

    /**
     * Simpan hasil {@see analyze()}. Tiap produk yang berubah dicatat di log aktivitasnya.
     *
     * @param  array{plans: list<array{product: Product, attributes: array<string, mixed>, variants: list<array{model: ProductVariant, attributes: array<string, mixed>}>}>}  $analysis
     * @return int jumlah produk yang diperbarui
     */
    public function apply(array $analysis): int
    {
        return DB::transaction(function () use ($analysis) {
            foreach ($analysis['plans'] as $plan) {
                Activity::track($plan['product'], function () use ($plan) {
                    if ($plan['attributes']) {
                        $plan['product']->fill($plan['attributes'])->save();
                    }
                    foreach ($plan['variants'] as $variant) {
                        $variant['model']->fill($variant['attributes'])->save();
                    }
                });
            }

            return count($analysis['plans']);
        });
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $records
     * @param  list<string>  $fields
     * @return array{changed: int, products: int, changes: list<array<string, mixed>>, counts: array<string, int>, plans: list<array<string, mixed>>}
     *
     * @throws ValidationException
     */
    private function plan(Collection $records, string $level, array $fields): array
    {
        $key = self::KEYS[$level];
        $records = $records->map(fn ($r) => [...$r, $key => mb_strtoupper((string) SpreadsheetImport::text($r[$key]))]);
        $skus = $records->pluck($key)->filter()->unique()->values();

        // Varian diambil lewat relasi produk supaya pengecekan nama & barcode melihat semua varian produk.
        if ($level === self::LEVEL_VARIANT) {
            $productIds = $skus->chunk(1000)->flatMap(fn ($chunk) => ProductVariant::whereIn('sku', $chunk->all())->pluck('product_id'))->unique();
            $products = $productIds->chunk(1000)->flatMap(fn ($chunk) => Product::with(['unit', 'variants'])->whereIn('id', $chunk->all())->get())->keyBy('id');
            $targets = $products->flatMap(fn (Product $p) => $p->variants->map(fn ($v) => [$p, $v]))->keyBy(fn ($pair) => mb_strtoupper($pair[1]->sku));
        } else {
            $products = $skus->chunk(1000)->flatMap(fn ($chunk) => Product::with(['unit', 'variants'])->whereIn('sku', $chunk->all())->get())->keyBy('id');
            $targets = $products->mapWithKeys(fn (Product $p) => [mb_strtoupper($p->sku) => [$p, null]]);
        }

        $units = in_array('satuan', $fields, true) ? Unit::query()->get() : collect();

        $errors = [];
        $seen = [];
        $productAttributes = []; // product id => [attribute => [value, baris]]
        $variantAttributes = []; // variant id => [attribute => value]

        foreach ($records as $record) {
            if (count($errors) >= self::MAX_ERRORS) {
                break;
            }

            $line = $record['line'];
            $sku = $record[$key];
            $label = $level === self::LEVEL_VARIANT ? 'SKU' : 'SKU induk';

            if ($sku === '') {
                $errors[] = "Baris {$line}: {$label} wajib diisi.";

                continue;
            }
            if (isset($seen[$sku])) {
                $errors[] = "Baris {$line}: {$label} {$sku} dobel dengan baris {$seen[$sku]}.";

                continue;
            }
            $seen[$sku] = $line;

            if (! isset($targets[$sku])) {
                $errors[] = "Baris {$line}: {$label} {$sku} tidak ditemukan di master produk.";

                continue;
            }

            /** @var Product $product */
            /** @var ?ProductVariant $variant */
            [$product, $variant] = $targets[$sku];
            $fail = function (string $message) use (&$errors, $line, $sku): bool {
                $errors[] = "Baris {$line} ({$sku}): {$message}";

                return false;
            };

            foreach ($fields as $field) {
                $definition = self::FIELDS[$field];
                $value = $this->value($field, $record[$field], $product, $units, $fail);
                if ($value === false) {
                    continue; // tidak valid (sudah dicatat) atau tidak berlaku untuk baris ini
                }
                $value = $value[0];

                if ($definition['level'] === self::LEVEL_PRODUCT) {
                    $attribute = $definition['attribute'];
                    $previous = $productAttributes[$product->id][$attribute] ?? null;
                    if ($previous && $previous[0] !== $value) {
                        $fail("{$definition['label']} berbeda dengan baris {$previous[1]} untuk produk {$product->sku} yang sama. Samakan isinya di semua varian.");

                        continue;
                    }
                    $productAttributes[$product->id][$attribute] = [$value, $line];
                } else {
                    $variantAttributes[$variant->id][$definition['attribute']] = $value;
                }
            }
        }

        if (count($errors) < self::MAX_ERRORS) {
            $this->checkVariantNames($products, $variantAttributes, $errors);
            $this->checkBarcodes($products, $variantAttributes, $errors);
        }

        if ($errors) {
            throw ValidationException::withMessages(['rows' => array_slice($errors, 0, self::MAX_ERRORS)]);
        }

        return $this->summarize($products, $fields, $productAttributes, $variantAttributes, $units);
    }

    /**
     * Ubah isi sel jadi nilai atribut.
     *
     * @return array{0: mixed}|false [nilai], atau false bila tidak valid / tidak berlaku
     */
    private function value(string $field, mixed $cell, Product $product, Collection $units, \Closure $fail): array|false
    {
        $text = SpreadsheetImport::text($cell);

        switch ($field) {
            case 'nama_produk':
                if ($text === null) {
                    return $fail('nama produk wajib diisi.');
                }
                if (mb_strlen($text) > 255) {
                    return $fail('nama produk maks. 255 karakter.');
                }

                return [$text];

            case 'kategori':
            case 'merek':
                if ($text !== null && mb_strlen($text) > 100) {
                    return $fail(self::FIELDS[$field]['label'].' maks. 100 karakter.');
                }

                return [$text];

            case 'deskripsi':
            case 'catatan':
                $max = $field === 'deskripsi' ? 5000 : 1000;
                if ($text !== null && mb_strlen($text) > $max) {
                    return $fail(self::FIELDS[$field]['label'].' maks. '.number_format($max, 0, ',', '.').' karakter.');
                }

                return [$text];

            case 'satuan':
                if ($text === null) {
                    return $fail('satuan wajib diisi.');
                }
                $needle = mb_strtoupper($text);
                $unit = $units->first(fn (Unit $u) => ($u->is_active || $u->id === $product->unit_id)
                    && (mb_strtoupper($u->code) === $needle || mb_strtoupper($u->name) === $needle));

                return $unit ? [$unit->id] : ($fail("satuan {$text} tidak ada atau tidak aktif di master satuan."));

            case 'status_produk':
                $active = SpreadsheetImport::boolean($text);

                return $active === null ? ($fail('status produk harus Aktif atau Nonaktif.')) : [$active];

            case 'nama_varian':
            case 'status_varian':
                if (! $product->has_variants) {
                    return $text === null ? false : ($fail($field === 'nama_varian'
                        ? "produk {$product->sku} tanpa varian, kosongkan nama varian. Ubah jenis produk lewat form produk."
                        : 'produk tanpa varian, kosongkan status varian dan pakai kolom status_produk.'));
                }
                if ($field === 'status_varian') {
                    $active = SpreadsheetImport::boolean($text);

                    return $active === null ? ($fail('status varian harus Aktif atau Nonaktif.')) : [$active];
                }
                if ($text === null) {
                    return $fail('nama varian wajib diisi untuk produk bervarian.');
                }

                return mb_strlen($text) > 100 ? ($fail('nama varian maks. 100 karakter.')) : [$text];

            case 'barcode':
                if ($text !== null && (! preg_match(self::CODE, $text) || mb_strlen($text) > 50)) {
                    return $fail("barcode {$text} hanya boleh huruf, angka, - dan _ (maks. 50 karakter).");
                }

                return [$text];

            default: // kolom angka
                [$integer, $min, $max] = self::NUMBERS[$field];
                $number = SpreadsheetImport::number($cell);
                if ($number === null || $number === '') {
                    return [null];
                }
                if (! is_numeric($number) || $number < $min || $number > $max || ($integer && (int) $number != $number)) {
                    return $fail(self::FIELDS[$field]['label'].' harus angka '.number_format($min, 0, ',', '.').' sampai '.number_format($max, 0, ',', '.').($integer ? ' (bilangan bulat).' : '.'));
                }

                return [$integer ? (int) $number : round((float) $number, 2)];
        }
    }

    /**
     * Nama varian tetap unik dalam satu produk setelah perubahan.
     *
     * @param  Collection<int, Product>  $products
     * @param  array<int, array<string, mixed>>  $variantAttributes
     * @param  list<string>  $errors
     */
    private function checkVariantNames(Collection $products, array $variantAttributes, array &$errors): void
    {
        foreach ($products as $product) {
            $names = $product->variants
                ->map(fn ($v) => mb_strtoupper((string) (array_key_exists('name', $variantAttributes[$v->id] ?? []) ? $variantAttributes[$v->id]['name'] : $v->name)))
                ->filter();
            foreach ($names->duplicates()->unique() as $name) {
                $errors[] = "Produk {$product->sku}: nama varian {$name} dipakai lebih dari satu varian.";
            }
        }
    }

    /**
     * Barcode tetap unik di semua varian & produk paket setelah perubahan.
     *
     * @param  Collection<int, Product>  $products
     * @param  array<int, array<string, mixed>>  $variantAttributes
     * @param  list<string>  $errors
     */
    private function checkBarcodes(Collection $products, array $variantAttributes, array &$errors): void
    {
        $changed = collect($variantAttributes)->filter(fn ($attributes) => array_key_exists('barcode', $attributes));
        if ($changed->isEmpty()) {
            return;
        }

        $variants = $products->flatMap->variants->keyBy('id');
        $final = $changed->map(fn ($attributes) => $attributes['barcode'])->filter();
        $owner = [];
        foreach ($final as $variantId => $barcode) {
            $upper = mb_strtoupper($barcode);
            if (isset($owner[$upper])) {
                $errors[] = "Barcode {$barcode} dipakai dua SKU di file: {$variants[$owner[$upper]]->sku} dan {$variants[$variantId]->sku}.";
            }
            $owner[$upper] ??= $variantId;
        }

        // Barcode di database milik varian lain yang barcode-nya tidak ikut diganti di file ini.
        $taken = $final->values()->unique()->chunk(1000)->flatMap(fn ($chunk) => ProductVariant::whereIn('barcode', $chunk->all())
            ->whereNotIn('id', $changed->keys()->all())
            ->get(['id', 'sku', 'barcode']));
        foreach ($taken as $other) {
            $errors[] = "Barcode {$other->barcode} sudah dipakai SKU {$other->sku}.";
        }

        // Barcode alias (Master › Barcode) tidak boleh dipakai lagi sebagai barcode varian.
        $aliases = $final->values()->unique()->chunk(1000)->flatMap(fn ($chunk) => ProductBarcode::with('variant:id,sku')->whereIn('barcode', $chunk->all())->get());
        foreach ($aliases as $alias) {
            $errors[] = "Barcode {$alias->barcode} sudah dipakai sebagai barcode alias SKU {$alias->variant?->sku}. Hapus dulu aliasnya di Master › Barcode.";
        }

        $bundles = Bundle::where(fn ($q) => $q->whereIn('sku', $final->values()->all())->orWhereIn('barcode', $final->values()->all()))->get(['sku', 'barcode']);
        foreach ($bundles as $bundle) {
            $errors[] = 'Barcode '.($final->first(fn ($b) => in_array(mb_strtoupper($b), [mb_strtoupper($bundle->sku), mb_strtoupper((string) $bundle->barcode)], true)) ?? $bundle->sku)." sudah dipakai produk paket {$bundle->sku}.";
        }
    }

    /**
     * Susun rencana simpan (hanya atribut yang benar-benar berubah) dan daftar perubahan untuk pratinjau.
     *
     * @param  Collection<int, Product>  $products
     * @param  list<string>  $fields
     * @param  array<int, array<string, array{0: mixed, 1: int}>>  $productAttributes
     * @param  array<int, array<string, mixed>>  $variantAttributes
     * @return array{changed: int, products: int, changes: list<array<string, mixed>>, counts: array<string, int>, plans: list<array<string, mixed>>}
     */
    private function summarize(Collection $products, array $fields, array $productAttributes, array $variantAttributes, Collection $units): array
    {
        $byAttribute = fn (string $level) => collect($fields)
            ->filter(fn ($f) => self::FIELDS[$f]['level'] === $level)
            ->mapWithKeys(fn ($f) => [self::FIELDS[$f]['attribute'] => $f]);
        $productFields = $byAttribute(self::LEVEL_PRODUCT);
        $variantFields = $byAttribute(self::LEVEL_VARIANT);

        $plans = [];
        $changes = [];
        $counts = array_fill_keys($fields, 0);

        foreach ($products as $product) {
            $attributes = $this->dirty($product, collect($productAttributes[$product->id] ?? [])->map(fn ($pair) => $pair[0])->all());
            foreach ($attributes as $attribute => $value) {
                $field = $productFields[$attribute];
                $counts[$field]++;
                $changes[] = [
                    'sku' => $product->sku, 'name' => $product->name, 'field' => $field,
                    'old' => $this->display($field, $product->{$attribute}, $units), 'new' => $this->display($field, $value, $units),
                ];
            }

            $variants = [];
            foreach ($product->variants as $variant) {
                $dirty = $this->dirty($variant, $variantAttributes[$variant->id] ?? []);
                if (! $dirty) {
                    continue;
                }
                foreach ($dirty as $attribute => $value) {
                    $field = $variantFields[$attribute];
                    $counts[$field]++;
                    $changes[] = [
                        'sku' => $variant->sku, 'name' => $variant->fullName(), 'field' => $field,
                        'old' => $this->display($field, $variant->{$attribute}, $units), 'new' => $this->display($field, $value, $units),
                    ];
                }
                $variants[] = ['model' => $variant, 'attributes' => $dirty];
            }

            if ($attributes || $variants) {
                $plans[] = ['product' => $product, 'attributes' => $attributes, 'variants' => $variants];
            }
        }

        return [
            'changed' => count($changes),
            'products' => count($plans),
            'changes' => $changes,
            'counts' => $counts,
            'plans' => $plans,
        ];
    }

    /**
     * Atribut yang nilainya benar-benar berbeda (memakai pembanding cast Eloquent, jadi 30 = "30.00").
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function dirty(Model $model, array $attributes): array
    {
        if (! $attributes) {
            return [];
        }

        $copy = clone $model;
        $copy->fill($attributes);

        return array_intersect_key($attributes, $copy->getDirty());
    }

    /** Nilai yang mudah dibaca untuk pratinjau. */
    private function display(string $field, mixed $value, Collection $units): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($field) {
            'satuan' => $units->firstWhere('id', $value)?->code ?? (string) $value,
            'status_produk', 'status_varian' => $value ? 'Aktif' : 'Nonaktif',
            'panjang_cm', 'lebar_cm', 'tinggi_cm' => rtrim(rtrim(number_format((float) $value, 2, ',', ''), '0'), ','),
            'berat_gram', 'stok_minimum', 'isi_per_koli' => number_format((int) $value, 0, ',', '.'),
            default => (string) $value,
        };
    }
}
