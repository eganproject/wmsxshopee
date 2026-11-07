<?php

namespace App\Support;

/** Nilai sel Excel dengan warna khusus, misalnya status hijau / kuning / merah di XlsxExport. */
final readonly class XlsxCell
{
    public function __construct(
        public mixed $value,
        public ?string $color = null,
        public ?string $fill = null,
        public bool $bold = false,
    ) {}

    /** Warna teks & latar lembut sesuai nama warna Tailwind yang dipakai label status di halaman. */
    public static function tone(mixed $value, ?string $tone): self
    {
        [$color, $fill] = match ($tone) {
            'emerald', 'green' => ['047857', 'ECFDF5'],
            'amber', 'yellow' => ['B45309', 'FFFBEB'],
            'rose', 'red' => ['BE123C', 'FFF1F2'],
            'sky', 'blue' => ['0369A1', 'F0F9FF'],
            'violet', 'purple' => ['6D28D9', 'F5F3FF'],
            'slate', 'gray' => ['475569', 'F1F5F9'],
            default => [null, null],
        };

        return new self($value, $color, $fill, $color !== null);
    }
}
