<?php

namespace App\Services;

use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Models\WarehouseRack;
use App\Models\WarehouseZone;
use App\Support\SpreadsheetImport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Impor massal zona, rak dan lokasi bin dari Excel/CSV. Satu baris = gudang +
 * zona + rak + lokasi; zona dan rak yang belum ada dibuat otomatis, yang sudah
 * ada dipakai apa adanya (tidak diubah). Lokasi yang kodenya sudah ada di
 * gudang itu dilewati. Semua baris dicek dulu: bila ada yang salah, tidak ada
 * yang disimpan.
 */
class WarehouseLocationImporter
{
    public const MAX_ROWS = 2000;

    /** Kolom template, urut sesuai file contoh. */
    public const COLUMNS = [
        'gudang' => 'Wajib. Kode gudang, cth. JKT01.',
        'kode_zona' => 'Wajib. Zona yang belum ada akan dibuat.',
        'nama_zona' => 'Untuk zona baru. Kosong = sama dengan kode zona.',
        'jenis_zona' => 'Untuk zona baru: Penerimaan, Penyimpanan, Picking, Packing, Pengiriman, Karantina, Retur. Kosong = Penyimpanan.',
        'kode_rak' => 'Wajib bila ada lokasi. Rak yang belum ada akan dibuat. Kode rak unik per gudang.',
        'nama_rak' => 'Untuk rak baru. Opsional.',
        'jumlah_level' => 'Untuk rak baru, 1 sampai 50. Kosong = level tertinggi di file.',
        'kode_lokasi' => 'Kode lokasi bin. Kosong = dibuat dari rak, level & posisi (cth. A01-02-03). Kosongkan juga level & posisi bila hanya menambah zona/rak.',
        'level' => 'Opsional, tidak boleh melebihi jumlah level rak.',
        'posisi' => 'Opsional, 1 sampai 999.',
        'jenis_lokasi' => 'Bin, Shelf, Pallet atau Lantai. Kosong = Bin.',
        'maks_qty' => 'Kapasitas maksimum. Opsional, bilangan bulat.',
        'maks_berat_kg' => 'Berat maksimum. Opsional.',
        'aktif' => 'Ya / Tidak. Kosong = Ya.',
    ];

    private const ALIASES = [
        'gudang' => ['kode_gudang', 'warehouse'],
        'kode_zona' => ['zona', 'zone'],
        'nama_zona' => ['zone_name'],
        'jenis_zona' => ['tipe_zona', 'zone_type'],
        'kode_rak' => ['rak', 'rack'],
        'nama_rak' => ['rack_name'],
        'jumlah_level' => ['levels', 'level_rak'],
        'kode_lokasi' => ['lokasi', 'lokasi_bin', 'bin', 'location'],
        'level' => [],
        'posisi' => ['position'],
        'jenis_lokasi' => ['tipe', 'tipe_lokasi', 'jenis', 'type'],
        'maks_qty' => ['max_qty', 'kapasitas'],
        'maks_berat_kg' => ['max_berat_kg', 'max_weight_kg', 'berat_maks'],
        'aktif' => ['status', 'is_active'],
    ];

    /** Nama pendek jenis zona yang juga dikenali selain label lengkapnya. */
    private const ZONE_TYPE_ALIASES = [
        'penerimaan' => 'receiving', 'inbound' => 'receiving',
        'penyimpanan' => 'storage',
        'pengiriman' => 'shipping', 'outbound' => 'shipping',
        'karantina' => 'quarantine', 'rusak' => 'quarantine',
        'retur' => 'return',
    ];

    /**
     * @return array{zones: array<int, string>, racks: array<int, string>, locations: array<int, string>, skipped: array<int, string>}
     *
     * @throws ValidationException
     */
    public function import(string $path, string $extension): array
    {
        $records = SpreadsheetImport::records($path, $extension, self::ALIASES, ['gudang', 'kode_zona'], self::MAX_ROWS, 'File tidak berisi baris lokasi.');
        $plan = $this->plan($records);

        $result = ['zones' => [], 'racks' => [], 'locations' => [], 'skipped' => $plan['skipped']];

        DB::transaction(function () use ($plan, &$result) {
            foreach ($plan['zones'] as $zone) {
                if (! $zone->exists) {
                    $zone->save();
                    $result['zones'][] = $zone->code;
                }
            }
            foreach ($plan['racks'] as $rack) {
                if (! $rack['model']->exists) {
                    $rack['model']->warehouse_zone_id = $rack['zone']->id;
                    $rack['model']->save();
                    $result['racks'][] = $rack['model']->code;
                }
            }
            foreach ($plan['locations'] as $location) {
                WarehouseLocation::create([...$location['attributes'], 'warehouse_rack_id' => $location['rack']->id]);
                $result['locations'][] = $location['attributes']['code'];
            }
        });

        return $result;
    }

    /**
     * Cek semua baris dan susun zona, rak dan lokasi yang akan dibuat.
     *
     * @param  Collection<int, array<string, mixed>>  $records
     * @return array{zones: array<string, WarehouseZone>, racks: array<string, array{model: WarehouseRack, zone: WarehouseZone}>, locations: array<int, array{rack: WarehouseRack, attributes: array<string, mixed>}>, skipped: array<int, string>}
     *
     * @throws ValidationException
     */
    private function plan(Collection $records): array
    {
        $upper = fn ($v) => mb_strtoupper((string) SpreadsheetImport::text($v));
        $records = $records->map(fn ($r) => [
            ...$r,
            'gudang' => $upper($r['gudang']),
            'kode_zona' => $upper($r['kode_zona']),
            'kode_rak' => $upper($r['kode_rak']),
            'kode_lokasi' => $upper($r['kode_lokasi']),
        ]);

        // Global scope membatasi ke gudang yang boleh diakses user.
        $warehouses = Warehouse::whereIn('code', $records->pluck('gudang')->filter()->unique())->get()
            ->keyBy(fn ($w) => mb_strtoupper($w->code));
        $warehouseIds = $warehouses->pluck('id');
        $zonesByKey = WarehouseZone::whereIn('warehouse_id', $warehouseIds)->get()
            ->keyBy(fn ($z) => $z->warehouse_id.'|'.mb_strtoupper($z->code));
        $racksByKey = WarehouseRack::with('zone')->whereIn('warehouse_zone_id', $zonesByKey->pluck('id'))->get()
            ->keyBy(fn ($r) => $r->zone->warehouse_id.'|'.mb_strtoupper($r->code));
        $existingLocations = WarehouseLocation::whereIn('warehouse_id', $warehouseIds)->get(['warehouse_id', 'code'])
            ->keyBy(fn ($l) => $l->warehouse_id.'|'.mb_strtoupper($l->code));

        $zoneTypes = collect(WarehouseZone::TYPES)->flatMap(fn ($label, $key) => [mb_strtolower($key) => $key, mb_strtolower($label) => $key])
            ->merge(self::ZONE_TYPE_ALIASES);
        $locationTypes = collect(WarehouseLocation::TYPES)->flatMap(fn ($label, $key) => [mb_strtolower($key) => $key, mb_strtolower($label) => $key]);

        $code = '/\A[\pL\pM\pN_-]+\z/u';
        $errors = [];
        $zones = [];
        $racks = [];
        $rackLevels = [];
        $locations = [];
        $seenLocation = [];
        $skipped = [];

        foreach ($records as $row) {
            if (count($errors) >= 50) {
                break;
            }
            $line = $row['line'];
            $rowErrors = count($errors);

            $warehouse = $warehouses->get($row['gudang']);
            if ($row['gudang'] === '') {
                $errors[] = "Baris {$line}: kode gudang wajib diisi.";

                continue;
            }
            if (! $warehouse) {
                $errors[] = "Baris {$line}: gudang {$row['gudang']} tidak ditemukan atau Anda tidak punya akses.";

                continue;
            }

            // Zona: pakai yang ada, atau siapkan zona baru (sekali per gudang + kode).
            $zoneCode = $row['kode_zona'];
            if ($zoneCode === '') {
                $errors[] = "Baris {$line}: kode zona wajib diisi.";

                continue;
            }
            if (! preg_match($code, $zoneCode) || mb_strlen($zoneCode) > 20) {
                $errors[] = "Baris {$line}: kode zona {$zoneCode} hanya boleh huruf, angka, - dan _ (maks. 20 karakter).";

                continue;
            }
            $zoneKey = $warehouse->id.'|'.$zoneCode;
            if (! isset($zones[$zoneKey])) {
                $zone = $zonesByKey->get($zoneKey);
                if (! $zone) {
                    $typeText = SpreadsheetImport::text($row['jenis_zona']);
                    $type = $typeText === null ? 'storage' : $zoneTypes->get(mb_strtolower($typeText));
                    if (! $type) {
                        $errors[] = "Baris {$line}: jenis zona {$typeText} tidak dikenal.";

                        continue;
                    }
                    $zone = new WarehouseZone([
                        'warehouse_id' => $warehouse->id,
                        'code' => $zoneCode,
                        'name' => mb_substr(SpreadsheetImport::text($row['nama_zona']) ?? $zoneCode, 0, 255),
                        'type' => $type,
                        'is_active' => true,
                    ]);
                }
                $zones[$zoneKey] = $zone;
            }
            $zone = $zones[$zoneKey];

            $rackCode = $row['kode_rak'];
            $level = $this->integer($row['level']);
            $position = $this->integer($row['posisi']);
            $wantsLocation = $row['kode_lokasi'] !== '' || $level !== null || $position !== null;

            if ($rackCode === '') {
                if ($wantsLocation) {
                    $errors[] = "Baris {$line}: kode rak wajib diisi untuk lokasi.";
                }

                continue;
            }
            if (! preg_match($code, $rackCode) || mb_strlen($rackCode) > 20) {
                $errors[] = "Baris {$line}: kode rak {$rackCode} hanya boleh huruf, angka, - dan _ (maks. 20 karakter).";

                continue;
            }

            // Rak: kode unik per gudang, jadi rak yang sama harus di zona yang sama.
            $rackKey = $warehouse->id.'|'.$rackCode;
            if (! isset($racks[$rackKey])) {
                $rack = $racksByKey->get($rackKey);
                if (! $rack) {
                    $levels = $this->integer($row['jumlah_level']);
                    if ($levels !== false && $levels !== null && ($levels < 1 || $levels > 50)) {
                        $errors[] = "Baris {$line}: jumlah level harus bilangan bulat 1 sampai 50.";

                        continue;
                    }
                    $rack = new WarehouseRack([
                        'code' => $rackCode,
                        'name' => SpreadsheetImport::text($row['nama_rak']) !== null ? mb_substr(SpreadsheetImport::text($row['nama_rak']), 0, 255) : null,
                        'levels' => $levels ?: 1,
                        'is_active' => true,
                    ]);
                    // Jumlah level kosong: ikuti level tertinggi lokasinya di file.
                    $rackLevels[$rackKey] = $levels ?: null;
                }
                $racks[$rackKey] = ['model' => $rack, 'zone' => $zone];
            }
            $rack = $racks[$rackKey]['model'];
            if ($rack->exists ? $rack->warehouse_zone_id !== $zone->id : $racks[$rackKey]['zone'] !== $zone) {
                $otherZone = $rack->exists ? $rack->zone->code : $racks[$rackKey]['zone']->code;
                $errors[] = "Baris {$line}: rak {$rackCode} sudah ada di zona {$otherZone}. Kode rak unik per gudang.";

                continue;
            }

            if (! $wantsLocation) {
                continue;
            }

            if ($level === false || ($level !== null && ($level < 1 || $level > 50))) {
                $errors[] = "Baris {$line}: level harus bilangan bulat 1 sampai 50.";
            } elseif ($level !== null && $rack->exists && $level > $rack->levels) {
                $errors[] = "Baris {$line}: level {$level} melebihi jumlah level rak {$rackCode} ({$rack->levels}). Ubah rak lewat form rak dulu.";
            } elseif ($level !== null && ! $rack->exists && $rackLevels[$rackKey] !== null && $level > $rackLevels[$rackKey]) {
                $errors[] = "Baris {$line}: level {$level} melebihi jumlah level rak {$rackCode} ({$rackLevels[$rackKey]}).";
            }
            if ($position === false || ($position !== null && ($position < 1 || $position > 999))) {
                $errors[] = "Baris {$line}: posisi harus bilangan bulat 1 sampai 999.";
            }

            $locationCode = $row['kode_lokasi'];
            if ($locationCode === '') {
                if (! is_int($level) || ! is_int($position)) {
                    $errors[] = "Baris {$line}: isi kode lokasi, atau level dan posisi agar kode dibuat otomatis.";

                    continue;
                }
                $locationCode = WarehouseLocation::makeCode($rackCode, $level, $position);
            }
            if (! preg_match($code, $locationCode) || mb_strlen($locationCode) > 50) {
                $errors[] = "Baris {$line}: kode lokasi {$locationCode} hanya boleh huruf, angka, - dan _ (maks. 50 karakter).";

                continue;
            }

            $locationKey = $warehouse->id.'|'.$locationCode;
            if (isset($seenLocation[$locationKey])) {
                $errors[] = "Baris {$line}: kode lokasi {$locationCode} dobel dengan baris {$seenLocation[$locationKey]}.";

                continue;
            }
            $seenLocation[$locationKey] = $line;

            $typeText = SpreadsheetImport::text($row['jenis_lokasi']);
            $type = $typeText === null ? 'bin' : $locationTypes->get(mb_strtolower($typeText));
            if (! $type) {
                $errors[] = "Baris {$line}: jenis lokasi {$typeText} tidak dikenal. Pilih Bin, Shelf, Pallet atau Lantai.";
            }

            $maxQty = $this->integer($row['maks_qty']);
            if ($maxQty === false || ($maxQty !== null && ($maxQty < 0 || $maxQty > 100000000))) {
                $errors[] = "Baris {$line}: maks qty harus bilangan bulat 0 atau lebih.";
            }
            $maxWeight = SpreadsheetImport::number($row['maks_berat_kg']);
            if ($maxWeight !== null && $maxWeight !== '' && (! is_numeric($maxWeight) || $maxWeight < 0 || $maxWeight > 99999999)) {
                $errors[] = "Baris {$line}: maks berat kg harus angka 0 atau lebih.";
            }
            $active = SpreadsheetImport::boolean($row['aktif']);
            if (filled($row['aktif']) && $active === null) {
                $errors[] = "Baris {$line}: kolom aktif harus Ya atau Tidak.";
            }

            if (count($errors) > $rowErrors) {
                continue;
            }

            if ($existingLocations->has($locationKey)) {
                $skipped[] = $locationCode;

                continue;
            }

            if (! $rack->exists && $rackLevels[$rackKey] === null && $level !== null) {
                $rack->levels = max($rack->levels, $level);
            }

            $locations[] = ['rack' => $rack, 'attributes' => [
                'warehouse_id' => $warehouse->id,
                'code' => $locationCode,
                'level' => $level,
                'position' => $position,
                'type' => $type,
                'max_qty' => $maxQty,
                'max_weight_kg' => $maxWeight !== null && $maxWeight !== '' ? round((float) $maxWeight, 2) : null,
                'is_active' => $active ?? true,
            ]];
        }

        if ($errors) {
            throw ValidationException::withMessages(['rows' => array_slice($errors, 0, 50)]);
        }

        return ['zones' => $zones, 'racks' => $racks, 'locations' => $locations, 'skipped' => $skipped];
    }

    /** Bilangan bulat, null bila kosong, false bila bukan bilangan bulat. */
    private function integer(mixed $value): int|false|null
    {
        $value = SpreadsheetImport::number($value);
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) && (int) $value == $value ? (int) $value : false;
    }
}
