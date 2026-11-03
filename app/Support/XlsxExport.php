<?php

namespace App\Support;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Unduhan Excel sederhana: satu sheet, baris judul tebal, lalu baris data. */
class XlsxExport
{
    /**
     * @param  array<int, string>  $header
     * @param  iterable<array<int, mixed>>  $rows
     */
    public static function download(string $filename, array $header, iterable $rows): BinaryFileResponse
    {
        $path = sys_get_temp_dir().'/xlsx-'.bin2hex(random_bytes(8)).'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValuesWithStyle($header, (new Style)->withFontBold(true)));

        foreach ($rows as $row) {
            $writer->addRow(self::row($row));
        }

        $writer->close();

        return response()->download($path, $filename)->deleteFileAfterSend();
    }

    /**
     * Teks yang diawali "=" (mis. nama pembeli dari marketplace) tetap ditulis sebagai
     * teks. Tanpa ini OpenSpout menjadikannya rumus yang ikut dijalankan Excel.
     *
     * @param  array<int, mixed>  $values
     */
    private static function row(array $values): Row
    {
        return new Row(array_map(
            fn ($value) => is_string($value) && str_starts_with($value, '=') ? new StringCell($value) : Cell::fromValue($value),
            array_values($values),
        ));
    }
}
