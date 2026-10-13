<?php

namespace App\Support;

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
        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValuesWithStyle($header, (new Style)->withFontBold(true)));

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }

        $writer->close();

        return response()->download($path, $filename)->deleteFileAfterSend();
    }
}
