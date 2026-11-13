<?php

namespace App\Services;

use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Models\WarehouseVariantSetting;
use App\Support\SpreadsheetImport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Update massal halaman SKU per Gudang lewat Excel/CSV: stok min, stok maks
 * dan lokasi tetap (bin) per SKU per gudang.
 *
 * Satu baris = satu SKU di satu gudang (kunci kolom gudang + sku). Kolom yang
 * diperbarui dibaca dari judul kolom file, jadi kolom yang tidak ada di file
 * tidak tersentuh. Sel kosong berarti nilainya dikosongkan. Semua baris dicek
 * dulu ({@see analyze()}) lalu ditampilkan sebagai pratinjau; perubahan baru
 * disimpan lewat {@see apply()}.
 */
class SkuSettingImporter
{
    public const KEYS = ['gudang', 'sku'];

    /** Awalan kolom keterangan di template; diabaikan saat diunggah. */
    public const INFO_PREFIX = 'info_';

    public const MAX_BINS = 10;

    private const MAX_QTY = 1000000;

    private const MAX_ERRORS = 50;

    /** @var array<string, array{label: string, hint: string}> */
    public const FIELDS = [
        'stok_min' => ['label' => 'Stok Minimum', 'hint' => 'Bilangan bulat 0 sampai 1.000.000 (pcs). Kosong = memakai stok minimum Master Produk.'],
        'stok_maks' => ['label' => 'Stok Maksimum', 'hint' => 'Bilangan bulat 0 sampai 1.000.000 (pcs), tidak boleh di bawah stok minimum. Kosong = isi ulang sampai Min.'],
        'lokasi_tetap' => ['label' => 'Lokasi Tetap', 'hint' => 'Kode bin dipisah koma, urut dari bin utama lalu cadangan. Kapasitas (pcs) opsional di belakang titik dua, cth. A01-01-01:50, A01-01-02. Maks. 10 bin. Kosong = hapus lokasi tetap.'],
    ];

    /** Kolom keterangan template: judul => penjelasan. */
    public const INFO_COLUMNS = [
        'info_nama_produk' => 'Nama produk & varian.',
        'info_stok' => 'Stok SKU di gudang itu saat template diunduh.',
        'info_min_master' => 'Stok minimum di Master Produk (dipakai bila stok_min kosong).',
    ];

    /**
     * @param  array<int, mixed>  $fields
     * @return list<string>
     */
    public static function normalize(array $fields): array
    {
        $fields = array_map(fn ($field) => (string) $field, $fields);

        return array_values(array_filter(array_keys(self::FIELDS), fn ($key) => in_array($key, $fields, true)));
    }

    /**
     * @param  list<string>  $fields
     * @return list<string>
     */
    public static function header(array $fields): array
    {
        return [...self::KEYS, ...array_keys(self::INFO_COLUMNS), ...$fields];
    }

    /**
     * Baris template berisi pengaturan saat ini.
     *
     * @param  Collection<int, Warehouse>  $warehouses
     * @param  list<string>  $fields
     * @param  string  $scope  all | stocked | configured
     * @return iterable<array<int, mixed>>
     */
    public static function rows(Collection $warehouses, array $fields, string $scope, FixedBins $fixed): iterable
    {
        foreach ($warehouses as $warehouse) {
            $query = ProductVariant::query()
                ->with('product:id,name,has_variants')
                ->where('is_active', true)
                ->whereHas('product', fn ($q) => $q->where('is_active', true))
                ->when($scope === 'stocked', fn ($q) => $q->whereHas('stocks', fn ($s) => $s->where('warehouse_id', $warehouse->id)->where('qty', '>', 0)))
                ->when($scope === 'configured', fn ($q) => $q->where(fn ($q) => $q
                    ->whereHas('warehouseSettings', fn ($s) => $s->where('warehouse_id', $warehouse->id))
                    ->orWhereIn('id', DB::table('warehouse_variant_locations')->where('warehouse_id', $warehouse->id)->select('product_variant_id'))))
                ->orderBy('sku');

            $locations = WarehouseLocation::where('warehouse_id', $warehouse->id)->pluck('code', 'id');

            foreach ($query->lazy(500)->chunk(500) as $chunk) {
                $ids = $chunk->pluck('id')->all();
                $settings = WarehouseVariantSetting::where('warehouse_id', $warehouse->id)->whereIn('product_variant_id', $ids)->get()->keyBy('product_variant_id');
                $stock = Stock::where('warehouse_id', $warehouse->id)->whereIn('product_variant_id', $ids)->groupBy('product_variant_id')
                    ->selectRaw('product_variant_id, SUM(qty) as qty')->pluck('qty', 'product_variant_id');
                $bins = in_array('lokasi_tetap', $fields, true) ? $fixed->for([$warehouse->id], $ids) : collect();

                foreach ($chunk as $variant) {
                    $setting = $settings[$variant->id] ?? null;
                    $row = [$warehouse->code, $variant->sku, $variant->fullName(), (int) ($stock[$variant->id] ?? 0), $variant->min_stock];
                    foreach ($fields as $field) {
                        $row[] = match ($field) {
                            'stok_min' => $setting?->min_qty,
                            'stok_maks' => $setting?->max_qty,
                            'lokasi_tetap' => self::formatBins(($bins[$warehouse->id.':'.$variant->id] ?? collect())
                                ->map(fn ($b) => ['code' => $locations[$b['location_id']] ?? '?', 'capacity' => $b['capacity']])->all()),
                        };
                    }

                    yield $row;
                }
            }
        }
    }

    /**
     * Baca & cek file. Tidak ada yang disimpan.
     *
     * @return array{fields: list<string>, ignored: list<string>, rows: int, changed: int, skus: int,
     *     changes: list<array{warehouse: string, sku: string, name: string, field: string, old: ?string, new: ?string}>,
     *     counts: array<string, int>,
     *     plans: list<array{warehouse_id: int, variant_id: int, values: array<string, mixed>, bins: ?list<array{location_id: int, capacity: ?int}>}>}
     *
     * @throws ValidationException
     */
    public function analyze(string $path, string $extension): array
    {
        @set_time_limit(SpreadsheetImport::TIME_LIMIT);

        $raw = SpreadsheetImport::readRows($path, $extension);
        $header = collect(array_shift($raw) ?? [])->map(fn ($name) => SpreadsheetImport::normalizeHeader($name));

        $missing = collect(self::KEYS)->reject(fn ($key) => $header->contains($key));
        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages(['file' => 'Kolom kunci tidak ditemukan: '.$missing->implode(', ').'. File harus punya kolom gudang (kode gudang) dan sku. Gunakan template yang diunduh dari halaman ini.']);
        }

        $fields = self::normalize($header->all());
        if ($fields === []) {
            throw ValidationException::withMessages(['file' => 'Tidak ada kolom yang bisa diperbarui di file ini (stok_min, stok_maks atau lokasi_tetap). Pilih kolom lalu unduh template dari halaman ini.']);
        }

        $known = [...self::KEYS, ...$fields];
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
            'fields' => $fields,
            'ignored' => $ignored,
            'rows' => $records->count(),
            ...$this->plan($records, $fields),
        ];
    }

    /**
     * Simpan hasil {@see analyze()}.
     *
     * @return int jumlah SKU per gudang yang diperbarui
     */
    public function apply(array $analysis, FixedBins $fixed): int
    {
        return DB::transaction(function () use ($analysis, $fixed) {
            foreach ($analysis['plans'] as $plan) {
                $match = ['warehouse_id' => $plan['warehouse_id'], 'product_variant_id' => $plan['variant_id']];
                $setting = WarehouseVariantSetting::withoutGlobalScope('warehouse_access')->firstOrNew($match);
                $setting->fill($plan['values']);

                if ($setting->default_location_id === null && $setting->min_qty === null && $setting->max_qty === null) {
                    if ($setting->exists) {
                        $setting->delete();
                    }
                } else {
                    $setting->save();
                }

                if ($plan['bins'] !== null) {
                    $fixed->sync($plan['warehouse_id'], $plan['variant_id'], $plan['bins']);
                }
            }

            return count($analysis['plans']);
        });
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $records
     * @param  list<string>  $fields
     *
     * @throws ValidationException
     */
    private function plan(Collection $records, array $fields): array
    {
        $records = $records->map(fn ($r) => [
            ...$r,
            'gudang' => mb_strtoupper((string) SpreadsheetImport::text($r['gudang'])),
            'sku' => mb_strtoupper((string) SpreadsheetImport::text($r['sku'])),
        ]);

        // Warehouse memakai scope akses gudang, jadi gudang lain tidak ditemukan.
        $warehouses = Warehouse::whereIn('code', $records->pluck('gudang')->filter()->unique()->all())->get()
            ->keyBy(fn ($w) => mb_strtoupper($w->code));
        $variants = $records->pluck('sku')->filter()->unique()->chunk(1000)
            ->flatMap(fn ($chunk) => ProductVariant::with('product:id,name,has_variants')->whereIn('sku', $chunk->all())->get())
            ->keyBy(fn ($v) => mb_strtoupper($v->sku));

        $warehouseIds = $warehouses->pluck('id')->all();
        $variantIds = $variants->pluck('id')->all();
        $settings = WarehouseVariantSetting::whereIn('warehouse_id', $warehouseIds)->whereIn('product_variant_id', $variantIds)->get()
            ->keyBy(fn ($s) => $s->warehouse_id.':'.$s->product_variant_id);

        $withBins = in_array('lokasi_tetap', $fields, true);
        // Kode lokasi per gudang: [gudang id => [KODE => lokasi]].
        $locations = $withBins
            ? WarehouseLocation::whereIn('warehouse_id', $warehouseIds)->get(['id', 'warehouse_id', 'code'])
                ->groupBy('warehouse_id')->map(fn ($rows) => $rows->keyBy(fn ($l) => mb_strtoupper($l->code)))
            : collect();
        $currentBins = $withBins && $warehouseIds && $variantIds ? app(FixedBins::class)->for($warehouseIds, $variantIds) : collect();
        $codes = $withBins ? WarehouseLocation::whereIn('warehouse_id', $warehouseIds)->pluck('code', 'id') : collect();

        $errors = [];
        $seen = [];
        $changes = [];
        $plans = [];
        $counts = array_fill_keys($fields, 0);

        foreach ($records as $record) {
            if (count($errors) >= self::MAX_ERRORS) {
                break;
            }

            $line = $record['line'];
            $code = $record['gudang'];
            $sku = $record['sku'];

            if ($code === '' || $sku === '') {
                $errors[] = "Baris {$line}: ".($code === '' ? 'kode gudang' : 'SKU').' wajib diisi.';

                continue;
            }
            if (isset($seen["{$code}|{$sku}"])) {
                $errors[] = "Baris {$line}: SKU {$sku} di gudang {$code} dobel dengan baris {$seen["{$code}|{$sku}"]}.";

                continue;
            }
            $seen["{$code}|{$sku}"] = $line;

            $warehouse = $warehouses[$code] ?? null;
            if (! $warehouse) {
                $errors[] = "Baris {$line}: gudang {$code} tidak ditemukan atau tidak bisa Anda akses.";

                continue;
            }
            if (! $warehouse->is_active) {
                $errors[] = "Baris {$line}: gudang {$code} nonaktif.";

                continue;
            }
            $variant = $variants[$sku] ?? null;
            if (! $variant) {
                $errors[] = "Baris {$line}: SKU {$sku} tidak ditemukan di master produk.";

                continue;
            }

            $prefix = "Baris {$line} ({$code} · {$sku}): ";
            $key = $warehouse->id.':'.$variant->id;
            $setting = $settings[$key] ?? null;
            $before = count($errors);

            $values = [];
            foreach (['stok_min' => 'min_qty', 'stok_maks' => 'max_qty'] as $field => $attribute) {
                if (! in_array($field, $fields, true)) {
                    continue;
                }
                $value = $this->quantity($record[$field]);
                if ($value === false) {
                    $errors[] = $prefix.self::FIELDS[$field]['label'].' harus bilangan bulat 0 sampai 1.000.000 atau dikosongkan.';
                } else {
                    $values[$attribute] = $value;
                }
            }

            $min = array_key_exists('min_qty', $values) ? $values['min_qty'] : $setting?->min_qty;
            $max = array_key_exists('max_qty', $values) ? $values['max_qty'] : $setting?->max_qty;
            if (count($errors) === $before && $min !== null && $max !== null && $max < $min) {
                $errors[] = $prefix."stok maksimum ({$max}) tidak boleh lebih kecil dari stok minimum ({$min}).";
            }

            $bins = null;
            if ($withBins) {
                $bins = $this->bins($record['lokasi_tetap'], $locations[$warehouse->id] ?? collect(), $code, function (string $message) use (&$errors, $prefix) {
                    $errors[] = $prefix.$message;
                });
                if ($bins !== null) {
                    $values['default_location_id'] = $bins[0]['location_id'] ?? null;
                }
            }

            if (count($errors) > $before) {
                continue;
            }

            // Bandingkan dengan data saat ini; hanya yang berbeda yang disimpan.
            $rowChanges = [];
            foreach (['stok_min' => 'min_qty', 'stok_maks' => 'max_qty'] as $field => $attribute) {
                if (array_key_exists($attribute, $values) && $values[$attribute] !== $setting?->{$attribute}) {
                    $rowChanges[] = [$field, $setting?->{$attribute}, $values[$attribute]];
                }
            }
            if ($bins !== null) {
                $old = ($currentBins[$key] ?? collect())->map(fn ($b) => ['code' => $codes[$b['location_id']] ?? '?', 'location_id' => $b['location_id'], 'capacity' => $b['capacity']])->values();
                $same = $old->map(fn ($b) => [$b['location_id'], $b['capacity']])->all() === array_map(fn ($b) => [$b['location_id'], $b['capacity']], $bins);
                if (! $same) {
                    $rowChanges[] = ['lokasi_tetap', self::formatBins($old->all()), self::formatBins($bins)];
                } else {
                    $bins = null; // tidak perlu ditulis ulang
                }
            }

            if (! $rowChanges) {
                continue;
            }

            foreach ($rowChanges as [$field, $old, $new]) {
                $counts[$field]++;
                $changes[] = [
                    'warehouse' => $warehouse->code, 'sku' => $variant->sku, 'name' => $variant->fullName(), 'field' => $field,
                    'old' => $old === null || $old === '' ? null : (string) $old,
                    'new' => $new === null || $new === '' ? null : (string) $new,
                ];
            }
            $plans[] = [
                'warehouse_id' => $warehouse->id,
                'variant_id' => $variant->id,
                'values' => $bins === null ? array_diff_key($values, ['default_location_id' => true]) : $values,
                'bins' => $bins === null ? null : array_map(fn ($b) => ['location_id' => $b['location_id'], 'capacity' => $b['capacity']], $bins),
            ];
        }

        if ($errors) {
            throw ValidationException::withMessages(['rows' => array_slice($errors, 0, self::MAX_ERRORS)]);
        }

        return [
            'changed' => count($changes),
            'skus' => count($plans),
            'changes' => $changes,
            'counts' => $counts,
            'plans' => $plans,
        ];
    }

    /** @return int|null|false angka, null bila kosong, false bila tidak valid */
    private function quantity(mixed $cell): int|null|false
    {
        $text = SpreadsheetImport::text($cell);
        if ($text === null) {
            return null;
        }
        // Pemisah ribuan format Indonesia ("1.000") diterima.
        if (preg_match('/\A\d{1,3}(\.\d{3})+\z/', $text)) {
            $text = str_replace('.', '', $text);
        }
        if (! preg_match('/\A\d+\z/', $text) || (int) $text > self::MAX_QTY) {
            return false;
        }

        return (int) $text;
    }

    /**
     * Ubah isi sel lokasi tetap ("A01-01-01:50, A01-01-02") jadi daftar bin.
     *
     * @param  Collection<string, WarehouseLocation>  $locations
     * @return list<array{location_id: int, code: string, capacity: ?int}>|null null bila tidak valid
     */
    private function bins(mixed $cell, Collection $locations, string $warehouse, \Closure $fail): ?array
    {
        $text = SpreadsheetImport::text($cell);
        if ($text === null) {
            return [];
        }

        $parts = array_values(array_filter(array_map('trim', preg_split('/[,;\n|]+/', $text)), fn ($p) => $p !== ''));
        if (count($parts) > self::MAX_BINS) {
            $fail('lokasi tetap maksimal '.self::MAX_BINS.' bin per SKU.');

            return null;
        }

        $bins = [];
        $valid = true;
        foreach ($parts as $part) {
            [$code, $capacity] = array_pad(array_map('trim', explode(':', $part, 2)), 2, null);
            $code = mb_strtoupper($code);
            $location = $locations[$code] ?? null;

            if (! $location) {
                $fail("lokasi {$code} tidak ada di gudang {$warehouse}.");
                $valid = false;

                continue;
            }
            if ($capacity !== null && $capacity !== '' && (! preg_match('/\A\d+\z/', $capacity) || (int) $capacity < 1 || (int) $capacity > self::MAX_QTY)) {
                $fail("kapasitas bin {$code} harus bilangan bulat 1 sampai 1.000.000 (cth. {$code}:50).");
                $valid = false;

                continue;
            }
            if (collect($bins)->contains('location_id', $location->id)) {
                $fail("bin {$code} ditulis lebih dari sekali.");
                $valid = false;

                continue;
            }

            $bins[] = ['location_id' => $location->id, 'code' => $location->code, 'capacity' => $capacity === null || $capacity === '' ? null : (int) $capacity];
        }

        return $valid ? $bins : null;
    }

    /**
     * @param  iterable<array{code: string, capacity: ?int}>  $bins
     */
    public static function formatBins(iterable $bins): string
    {
        return collect($bins)->map(fn ($b) => $b['code'].($b['capacity'] ? ':'.$b['capacity'] : ''))->implode(', ');
    }
}
