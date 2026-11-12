<?php

namespace App\Support;

use DateTimeInterface;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderName;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\BorderWidth;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\CellVerticalAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\Common\Entity\Sheet;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Options\PageOrientation;
use OpenSpout\Writer\XLSX\Options\PageSetup;
use OpenSpout\Writer\XLSX\Options\PaperSize;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Unduhan Excel yang rapi: baris judul berwarna dan dibekukan, filter di tiap kolom,
 * lebar kolom menyesuaikan isi, tanggal & angka disimpan sebagai nilai asli (bisa dijumlah / diurutkan).
 *
 * Jenis kolom ditebak dari nilainya: DateTimeInterface jadi tanggal (atau tanggal + jam bila jamnya bukan 00:00),
 * int / float jadi angka dengan pemisah ribuan. Teks (termasuk nomor HP / resi) tetap teks.
 * Judul kolom boleh berupa ['Judul', 'format'] untuk memaksa format: date, datetime, number, money, text.
 */
class XlsxExport
{
    private const MIN_WIDTH = 8;

    private const MAX_WIDTH = 60;

    private const FORMATS = [
        'date' => 'dd/mm/yyyy',
        'datetime' => 'dd/mm/yyyy hh:mm',
        'number' => '#,##0',
        'decimal' => '#,##0.00',
        'money' => '#,##0',
    ];

    /**
     * Satu sheet.
     *
     * @param  array<int, string|array{0: string, 1: string}>  $header
     * @param  iterable<array<int, mixed>>  $rows
     */
    public static function download(string $filename, array $header, iterable $rows): BinaryFileResponse
    {
        return self::workbook($filename, [['name' => 'Data', 'header' => $header, 'rows' => $rows]]);
    }

    /**
     * Beberapa sheet sekaligus. Sheet bertipe "info" berisi pasangan label → nilai (tanpa baris judul berwarna).
     * Pilihan sheet tabel: "totals" = judul kolom yang dijumlah di baris Total paling bawah (ikut filter Excel),
     * "freeze" = huruf kolom pertama yang ikut digulir, misalnya "B" supaya kolom A (nomor) tetap terlihat.
     *
     * @param  list<array{name: string, header?: array<int, string|array{0: string, 1: string}>, rows: iterable<array<int, mixed>>, type?: string, totals?: list<string>, freeze?: string}>  $sheets
     */
    public static function workbook(string $filename, array $sheets): BinaryFileResponse
    {
        // Nama acak tanpa tempnam(): tempnam() meninggalkan file kosong setiap ekspor.
        $path = sys_get_temp_dir().'/xlsx-'.bin2hex(random_bytes(8)).'.xlsx';
        // Saat dicetak: A4 mendatar, semua kolom muat selebar satu halaman.
        $writer = new Writer(new Options(pageSetup: new PageSetup(PageOrientation::LANDSCAPE, PaperSize::A4, fitToHeight: 0, fitToWidth: 1)));
        $writer->openToFile($path);

        foreach (array_values($sheets) as $index => $sheet) {
            $current = $index === 0 ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            $current->setName(self::sheetName($sheet['name']));

            ($sheet['type'] ?? 'table') === 'info'
                ? self::writeInfo($writer, $current, $sheet['rows'])
                : self::writeTable($writer, $current, $sheet['header'] ?? [], $sheet['rows'], $sheet['totals'] ?? [], $sheet['freeze'] ?? null);
        }

        // Buka file di sheet pertama.
        $writer->setCurrentSheet($writer->getSheets()[0]);
        $writer->close();

        return response()->download($path, $filename)->deleteFileAfterSend();
    }

    /**
     * @param  array<int, string|array{0: string, 1: string}>  $header
     * @param  iterable<array<int, mixed>>  $rows
     * @param  list<string>  $totals
     */
    private static function writeTable(Writer $writer, Sheet $sheet, array $header, iterable $rows, array $totals = [], ?string $freeze = null): void
    {
        $labels = array_map(fn ($column) => is_array($column) ? $column[0] : $column, array_values($header));
        $forced = array_map(fn ($column) => is_array($column) ? ($column[1] ?? null) : null, array_values($header));
        $widths = array_map(fn ($label) => mb_strlen($label) + 4, $labels);

        $writer->addRow(new Row(array_map(fn ($label) => Cell::fromValue($label, self::headerStyle()), $labels), 24));

        $count = 0;
        foreach ($rows as $row) {
            $cells = [];
            foreach (array_values($row) as $column => $value) {
                [$cell, $length] = self::cell($value, $forced[$column] ?? null);
                $cells[] = $cell;
                $widths[$column] = max($widths[$column] ?? 0, (int) ceil($length * 1.15) + 3);
            }
            $writer->addRow(new Row($cells));
            $count++;
        }

        // Baris Total di luar area filter: SUBTOTAL(109) hanya menjumlah baris yang terlihat setelah difilter.
        if ($totals !== [] && $count > 0) {
            $style = (new Style)->withFontBold(true)->withBackgroundColor('F1F5F9')
                ->withBorder(new Border(new BorderPart(BorderName::TOP, '334155', BorderWidth::MEDIUM), new BorderPart(BorderName::BOTTOM, 'CBD5E1', BorderWidth::THIN)));
            $cells = [];
            foreach ($labels as $column => $label) {
                $letter = self::columnLetter($column);
                $cells[] = match (true) {
                    in_array($label, $totals, true) => new Cell\FormulaCell("=SUBTOTAL(109,{$letter}2:{$letter}".($count + 1).')', null,
                        $style->withFormat(self::FORMATS[($forced[$column] ?? null) === 'decimal' ? 'decimal' : 'number'])->withCellAlignment(CellAlignment::RIGHT)),
                    $column === 0 => Cell::fromValue('Total', $style),
                    default => Cell::fromValue(null, $style),
                };
            }
            $writer->addRow(new Row($cells, 20));
        }

        foreach ($widths as $column => $width) {
            $sheet->setColumnWidth(max(self::MIN_WIDTH, min(self::MAX_WIDTH, $width)), $column + 1);
        }

        // Baris judul (dan kolom pertama bila diminta) tetap terlihat saat digulir; tiap kolom bisa difilter / diurutkan di Excel.
        $view = (new SheetView)->withFreezeRow(2);
        $sheet->setSheetView($freeze ? $view->withFreezeColumn($freeze) : $view);
        if ($labels !== []) {
            $sheet->setAutoFilter(new AutoFilter(0, 1, count($labels) - 1, $count + 1));
        }
    }

    /** @param  iterable<array<int, mixed>>  $rows */
    private static function writeInfo(Writer $writer, Sheet $sheet, iterable $rows): void
    {
        $widths = [self::MIN_WIDTH, self::MIN_WIDTH];
        foreach ($rows as $row) {
            $row = array_values($row);
            if (count($row) === 1) {
                // Satu nilai = judul bagian.
                $writer->addRow(new Row([Cell::fromValue((string) $row[0], (new Style)->withFontBold(true)->withFontSize(13))], 22));
                $widths[0] = max($widths[0], min(self::MAX_WIDTH, mb_strlen((string) $row[0])));

                continue;
            }

            [$cell, $length] = self::cell($row[1] ?? null, null);
            $writer->addRow(new Row([Cell::fromValue((string) $row[0], (new Style)->withFontBold(true)->withFontColor('475569')), $cell->withStyle(self::cellStyle($row[1] ?? null, null)->withCellAlignment(CellAlignment::LEFT))]));
            $widths[0] = max($widths[0], mb_strlen((string) $row[0]) + 2);
            $widths[1] = max($widths[1], $length + 2);
        }

        $sheet->setColumnWidth(min(self::MAX_WIDTH, $widths[0]), 1);
        $sheet->setColumnWidth(min(80, $widths[1]), 2);
    }

    /** @return array{0: Cell, 1: int} Sel beserta perkiraan lebar tampilannya. */
    private static function cell(mixed $value, ?string $format): array
    {
        if ($value instanceof XlsxCell) {
            [$cell, $length] = self::cell($value->value, $format);
            $style = self::cellStyle($cell->getValue(), $format);
            $style = $value->color ? $style->withFontColor($value->color) : $style;
            $style = $value->fill ? $style->withBackgroundColor($value->fill) : $style;

            return [$cell->withStyle($value->bold ? $style->withFontBold(true) : $style), $length];
        }

        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        } elseif ($value instanceof \Stringable && ! $value instanceof DateTimeInterface) {
            $value = (string) $value;
        }

        if ($format === 'text' && $value !== null && ! is_string($value)) {
            $value = (string) $value;
        }
        if (is_string($value) && in_array($format, ['number', 'decimal', 'money'], true) && is_numeric($value)) {
            $value = (float) $value;
        }

        $style = self::cellStyle($value, $format);
        $length = match (true) {
            $value instanceof DateTimeInterface => self::isDateOnly($value, $format) ? 10 : 16,
            is_int($value), is_float($value) => mb_strlen(number_format((float) $value, self::hasFraction($value, $format) ? 2 : 0)),
            is_bool($value) => 5,
            $value === null => 0,
            // Teks panjang (alamat, catatan) dibatasi, sisanya terlihat saat sel diklik.
            default => max(array_map('mb_strlen', explode("\n", (string) $value)) ?: [0]),
        };

        // Teks yang diawali "=" jangan sampai dianggap rumus oleh Excel.
        $cell = is_string($value) && str_starts_with($value, '=')
            ? new Cell\StringCell($value, $style)
            : Cell::fromValue(is_scalar($value) || $value instanceof DateTimeInterface || $value === null ? $value : json_encode($value), $style);

        return [$cell, $length];
    }

    private static function cellStyle(mixed $value, ?string $format): Style
    {
        $style = (new Style)->withCellVerticalAlignment(CellVerticalAlignment::TOP)->withBorder(self::border('E2E8F0'));

        if ($value instanceof DateTimeInterface) {
            return $style->withFormat(self::FORMATS[self::isDateOnly($value, $format) ? 'date' : 'datetime'])->withCellAlignment(CellAlignment::CENTER);
        }
        if (is_int($value) || is_float($value)) {
            return $style->withFormat(self::FORMATS[self::hasFraction($value, $format) ? 'decimal' : 'number'])->withCellAlignment(CellAlignment::RIGHT);
        }

        return $style;
    }

    private static function headerStyle(): Style
    {
        return (new Style)
            ->withFontBold(true)
            ->withFontColor('FFFFFF')
            ->withBackgroundColor('334155')
            ->withCellVerticalAlignment(CellVerticalAlignment::CENTER)
            ->withShouldWrapText(true)
            ->withBorder(self::border('1E293B'));
    }

    private static function border(string $color): Border
    {
        return new Border(...array_map(fn (BorderName $name) => new BorderPart($name, $color, BorderWidth::THIN), BorderName::cases()));
    }

    private static function isDateOnly(DateTimeInterface $value, ?string $format): bool
    {
        return $format === 'date' || ($format !== 'datetime' && $value->format('H:i:s') === '00:00:00');
    }

    private static function hasFraction(int|float $value, ?string $format): bool
    {
        return $format === 'decimal' || ($format !== 'number' && $format !== 'money' && is_float($value) && floor($value) != $value);
    }

    /** 0 → A, 25 → Z, 26 → AA. */
    private static function columnLetter(int $index): string
    {
        $letter = '';
        for ($index++; $index > 0; $index = intdiv($index - 1, 26)) {
            $letter = chr(65 + ($index - 1) % 26).$letter;
        }

        return $letter;
    }

    /** Nama sheet Excel maks. 31 karakter dan tanpa \ / ? * [ ] : */
    private static function sheetName(string $name): string
    {
        return mb_substr(trim(str_replace(['\\', '/', '?', '*', '[', ']', ':'], ' ', $name)) ?: 'Data', 0, 31);
    }
}
