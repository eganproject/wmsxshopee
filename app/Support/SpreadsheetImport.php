<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Bagian bersama impor Excel/CSV: membaca file, mencocokkan judul kolom
 * (termasuk nama alternatif) dan mengunduh template berisi contoh.
 */
class SpreadsheetImport
{
    /**
     * Baca file lalu ubah tiap baris jadi array [kolom template => nilai] plus
     * 'line' (nomor baris di file). Baris kosong dibuang.
     *
     * @param  array<string, array<int, string>>  $aliases  kolom template => nama yang dikenali
     * @param  array<int, string>  $required  kolom yang wajib ada di judul
     * @return Collection<int, array<string, mixed>>
     *
     * @throws ValidationException
     */
    public static function records(string $path, string $extension, array $aliases, array $required, int $maxRows = 2000, string $emptyMessage = 'File tidak berisi data.'): Collection
    {
        $rows = self::readRows($path, $extension);
        $map = self::mapColumns(array_shift($rows) ?? [], $aliases);

        $missing = collect($required)->reject(fn ($key) => isset($map[$key]));
        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages(['file' => 'Kolom wajib tidak ditemukan: '.$missing->implode(', ').'. Gunakan template yang disediakan.']);
        }

        $records = collect($rows)
            ->map(fn ($row, $index) => ['line' => $index + 2, ...collect($aliases)->map(fn ($_, $key) => isset($map[$key]) ? self::clean($row[$map[$key]] ?? null) : null)->all()])
            ->reject(fn ($record) => collect($record)->except('line')->filter(fn ($v) => $v !== null && $v !== '')->isEmpty())
            ->values();

        if ($records->isEmpty()) {
            throw ValidationException::withMessages(['file' => $emptyMessage]);
        }
        if ($records->count() > $maxRows) {
            throw ValidationException::withMessages(['file' => 'Maksimal '.number_format($maxRows, 0, ',', '.').' baris per impor. Pecah file menjadi beberapa bagian.']);
        }

        return $records;
    }

    /**
     * @return array<int, array<int, mixed>> baris mentah termasuk judul
     *
     * @throws ValidationException
     */
    public static function readRows(string $path, string $extension): array
    {
        try {
            if ($extension === 'xlsx') {
                $reader = new XlsxReader;
                $reader->open($path);
                $rows = [];
                foreach ($reader->getSheetIterator() as $sheet) {
                    foreach ($sheet->getRowIterator() as $row) {
                        $rows[] = $row->toArray();
                    }
                    break;
                }
                $reader->close();

                return $rows;
            }

            $content = file_get_contents($path);
            $content = preg_replace('/^\xEF\xBB\xBF/', '', (string) $content);
            $firstLine = strtok($content, "\r\n") ?: '';
            $delimiter = collect([';', ',', "\t"])->sortByDesc(fn ($d) => substr_count($firstLine, $d))->first();

            $handle = fopen('php://temp', 'r+');
            fwrite($handle, $content);
            rewind($handle);
            $rows = [];
            while (($row = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
                $rows[] = $row;
            }
            fclose($handle);

            return $rows;
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => 'File tidak bisa dibaca. Pastikan formatnya .xlsx atau .csv sesuai template.']);
        }
    }

    /**
     * @param  array<int, mixed>  $headerRow
     * @param  array<string, array<int, string>>  $aliases
     * @return array<string, int> kolom template => indeks kolom file
     */
    public static function mapColumns(array $headerRow, array $aliases): array
    {
        $normalized = collect($headerRow)->map(fn ($v) => trim((string) preg_replace('/[^a-z0-9]+/', '_', mb_strtolower(trim((string) $v))), '_'));
        $map = [];

        foreach ($aliases as $key => $names) {
            $index = $normalized->search(fn ($name) => in_array($name, [$key, ...$names], true));
            if ($index !== false) {
                $map[$key] = $index;
            }
        }

        return $map;
    }

    public static function clean(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value;
        }
        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }

        return $value;
    }

    /** Teks sel sebagai string (angka dari Excel tanpa ".0"), atau null bila kosong. */
    public static function text(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_float($value) && floor($value) === $value && abs($value) < 1e15) {
            return (string) (int) $value;
        }

        return trim((string) $value);
    }

    /** Angka desimal dengan koma (format Indonesia, cth. "30,5") diubah jadi titik. */
    public static function number(mixed $value): mixed
    {
        if (is_string($value) && preg_match('/\A-?\d+,\d+\z/', trim($value))) {
            return str_replace(',', '.', trim($value));
        }

        return $value;
    }

    /** Nilai ya/tidak: null bila kosong atau tidak dikenali. */
    public static function boolean(mixed $value): ?bool
    {
        $value = mb_strtolower((string) self::text($value));

        return match (true) {
            in_array($value, ['1', 'ya', 'y', 'yes', 'aktif', 'true'], true) => true,
            in_array($value, ['0', 'tidak', 't', 'no', 'n', 'nonaktif', 'false'], true) => false,
            default => null,
        };
    }

    /**
     * Unduh template .xlsx atau .csv (pemisah titik koma) berisi judul dan contoh.
     *
     * @param  array<int, string>  $header
     * @param  array<int, array<int, mixed>>  $samples
     */
    public static function template(string $format, string $basename, array $header, array $samples): BinaryFileResponse|StreamedResponse
    {
        abort_unless(in_array($format, ['xlsx', 'csv'], true), 404);

        if ($format === 'csv') {
            return response()->streamDownload(function () use ($header, $samples) {
                $out = fopen('php://output', 'w');
                fputcsv($out, $header, ';', '"', '');
                foreach ($samples as $row) {
                    fputcsv($out, $row, ';', '"', '');
                }
                fclose($out);
            }, "{$basename}.csv", ['Content-Type' => 'text/csv']);
        }

        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValuesWithStyle($header, (new Style)->withFontBold(true)));
        foreach ($samples as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return response()->download($path, "{$basename}.xlsx")->deleteFileAfterSend();
    }

    /** Ekstensi yang dipakai pembaca untuk file unggahan. */
    public static function extension(UploadedFile $file): string
    {
        return strtolower($file->getClientOriginalExtension()) === 'xlsx' ? 'xlsx' : 'csv';
    }
}
