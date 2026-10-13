<?php

namespace App\Support;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Picqer\Barcode\Types\TypeCode128;
use Throwable;

/**
 * Membuat barcode Code128 dan QR code sebagai SVG inline untuk label cetak.
 *
 * SVG digambar tanpa ukuran tetap (hanya viewBox), jadi mengikuti ukuran
 * kotak di label dan tetap tajam di printer thermal maupun laser.
 */
class Barcode
{
    /** @var array<string, string|null> */
    private static array $cache = [];

    /** Code128: batang meregang mengisi kotak; null bila teks tidak bisa dikodekan. */
    public static function code128(string $value): ?string
    {
        return self::$cache["c:{$value}"] ??= self::renderCode128($value);
    }

    /** QR code persegi tanpa quiet zone (jarak tepi diatur lewat CSS label). */
    public static function qr(string $value): ?string
    {
        return self::$cache["q:{$value}"] ??= self::renderQr($value);
    }

    private static function renderCode128(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        try {
            $barcode = (new TypeCode128)->getBarcode($value);
        } catch (Throwable) {
            return null;
        }

        $rects = '';
        $x = 0;
        foreach ($barcode->getBars() as $bar) {
            if ($bar->isBar() && $bar->getWidth() > 0) {
                $rects .= sprintf('<rect x="%d" y="0" width="%d" height="1"/>', $x, $bar->getWidth());
            }
            $x += $bar->getWidth();
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d 1" preserveAspectRatio="none" shape-rendering="crispEdges" fill="#000" aria-hidden="true">%s</svg>',
            $barcode->getWidth(),
            $rects,
        );
    }

    private static function renderQr(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        try {
            $matrix = (new QRCode(new QROptions(['eccLevel' => EccLevel::M, 'addQuietzone' => false])))
                ->addByteSegment($value)
                ->getQRMatrix();
        } catch (Throwable) {
            return null;
        }

        $size = $matrix->getSize();
        $path = '';
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($matrix->check($x, $y)) {
                    $path .= "M{$x} {$y}h1v1h-1z";
                }
            }
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %1$d" shape-rendering="crispEdges" aria-hidden="true"><path fill="#000" d="%2$s"/></svg>',
            $size,
            $path,
        );
    }
}
