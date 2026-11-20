<?php

namespace App\Support;

use App\Models\User;

/**
 * Daftar laporan yang dikumpulkan di halaman Laporan (satu menu di sidebar).
 * Dipakai oleh halaman Laporan, tab antar laporan (x-reports.tabs) dan pencarian global.
 * Semua laporan memakai permission reports.view (middleware grup route).
 */
class ReportsMenu
{
    /**
     * @return array<int, array{heading: string, items: array<int, array<string, mixed>>}>
     */
    public static function groups(): array
    {
        return [
            [
                'heading' => 'Posisi Stok',
                'items' => [
                    ['label' => 'Stok per Lokasi', 'description' => 'Saldo stok per gudang, zona, rak dan bin, termasuk stok yang belum punya bin.', 'route' => 'reports.stock-locations', 'icon' => 'heroicon-o-map-pin', 'keywords' => 'bin rak zona'],
                    ['label' => 'Saldo per Tanggal', 'description' => 'Saldo stok tiap SKU pada tanggal tertentu, dihitung mundur dari kartu stok.', 'route' => 'reports.balance', 'icon' => 'heroicon-o-calendar-days', 'keywords' => 'histori mundur'],
                ],
            ],
            [
                'heading' => 'Pergerakan',
                'items' => [
                    ['label' => 'Pergerakan Barang', 'description' => 'Barang masuk, keluar dan transfer per periode.', 'route' => 'reports.movements', 'icon' => 'heroicon-o-presentation-chart-line', 'keywords' => 'masuk keluar periode'],
                    ['label' => 'Fast / Slow Moving', 'description' => 'Peringkat SKU berdasarkan kecepatan perputaran penjualan.', 'route' => 'reports.moving', 'icon' => 'heroicon-o-bolt', 'keywords' => 'laris lambat perputaran'],
                ],
            ],
            [
                'heading' => 'Perencanaan',
                'items' => [
                    ['label' => 'Stok Minimum', 'description' => 'SKU yang stok tersedianya sudah di bawah stok minimum gudang.', 'route' => 'reports.min-stock', 'icon' => 'heroicon-o-bell-alert', 'keywords' => 'menipis reorder'],
                    ['label' => 'Saran Pembelian', 'description' => 'Usulan jumlah pembelian ke supplier berdasarkan laju penjualan dan stok minimum.', 'route' => 'reports.reorder', 'icon' => 'heroicon-o-shopping-bag', 'keywords' => 'po supplier restock'],
                    ['label' => 'Prakiraan Stok Habis', 'description' => 'Perkiraan tanggal stok habis dari rata-rata penjualan harian.', 'route' => 'reports.runout', 'icon' => 'heroicon-o-clock', 'keywords' => 'forecast habis'],
                    ['label' => 'Barang Hilang', 'description' => 'Selisih minus dari opname dan penyesuaian stok yang dicatat sebagai barang hilang.', 'route' => 'reports.lost-items', 'icon' => 'heroicon-o-question-mark-circle', 'keywords' => 'selisih opname'],
                ],
            ],
        ];
    }

    /**
     * Semua laporan rata, urut sesuai grup (untuk tab antar laporan).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function items(): array
    {
        return collect(self::groups())->flatMap(fn ($group) => $group['items'])->values()->all();
    }

    /**
     * Grup yang boleh dilihat user (semua laporan memakai reports.view).
     */
    public static function visibleGroups(?User $user): array
    {
        return $user?->can('reports.view') ? self::groups() : [];
    }
}
