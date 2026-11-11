<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pengaturan cetak resi (hanya satu baris): QR nomor resi (AWB) di label.
 *
 * Label Shopee & TikTok tetap PDF resmi dari marketplace; QR hanya ditempel di
 * atasnya (dengan latar putih) pada pojok yang dipilih. Label pesanan manual
 * digambar Optima sendiri, QR-nya diletakkan di samping barcode resi.
 */
class LabelSetting extends Model
{
    public const POSITIONS = [
        'top-left' => 'Kiri atas',
        'top-right' => 'Kanan atas',
        'bottom-left' => 'Kiri bawah',
        'bottom-right' => 'Kanan bawah',
    ];

    public const PLATFORMS = ['shopee' => 'Shopee', 'tiktok' => 'TikTok'];

    protected $fillable = [
        'qr_enabled', 'qr_size_mm', 'manual_qr',
        'shopee_qr', 'shopee_qr_position', 'shopee_qr_margin_x_mm', 'shopee_qr_margin_y_mm',
        'tiktok_qr', 'tiktok_qr_position', 'tiktok_qr_margin_x_mm', 'tiktok_qr_margin_y_mm',
    ];

    protected $attributes = [
        'qr_enabled' => true,
        'qr_size_mm' => 18,
        'manual_qr' => true,
        'shopee_qr' => true,
        'shopee_qr_position' => 'bottom-right',
        'shopee_qr_margin_x_mm' => 4,
        'shopee_qr_margin_y_mm' => 4,
        'tiktok_qr' => true,
        'tiktok_qr_position' => 'bottom-right',
        'tiktok_qr_margin_x_mm' => 4,
        'tiktok_qr_margin_y_mm' => 4,
    ];

    protected function casts(): array
    {
        return [
            'qr_enabled' => 'boolean',
            'qr_size_mm' => 'integer',
            'manual_qr' => 'boolean',
            'shopee_qr' => 'boolean',
            'shopee_qr_margin_x_mm' => 'float',
            'shopee_qr_margin_y_mm' => 'float',
            'tiktok_qr' => 'boolean',
            'tiktok_qr_margin_x_mm' => 'float',
            'tiktok_qr_margin_y_mm' => 'float',
        ];
    }

    public static function current(): self
    {
        return static::query()->first() ?? new static;
    }

    /** QR dipasang di label platform ini (shopee, tiktok atau manual)? */
    public function qrFor(string $platform): bool
    {
        return $this->qr_enabled && (bool) $this->{"{$platform}_qr"};
    }

    /**
     * Posisi QR di label marketplace untuk dikirim ke browser, null bila mati.
     *
     * @return array{size_mm: int, position: string, margin_x_mm: float, margin_y_mm: float}|null
     */
    public function stamp(string $platform): ?array
    {
        if (! $this->qrFor($platform)) {
            return null;
        }

        return [
            'size_mm' => $this->qr_size_mm,
            'position' => $this->{"{$platform}_qr_position"},
            'margin_x_mm' => (float) $this->{"{$platform}_qr_margin_x_mm"},
            'margin_y_mm' => (float) $this->{"{$platform}_qr_margin_y_mm"},
        ];
    }
}
