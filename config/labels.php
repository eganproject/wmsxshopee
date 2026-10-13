<?php

/*
 * Ukuran kertas label yang bisa dipilih saat mencetak.
 *
 * - Roll thermal: satu label per halaman, ukuran halaman = ukuran label
 *   (atur ukuran kertas yang sama di driver printer thermal).
 * - Lembar A4: label disusun dalam grid kolom × baris, sesuai kertas label
 *   stiker A4 yang umum dijual (atur skala cetak 100% / "Actual size").
 *
 * Semua ukuran dalam milimeter.
 */
return [
    'sizes' => [
        'thermal-50x30' => [
            'label' => 'Thermal 50 × 30 mm',
            'group' => 'Roll thermal',
            'width' => 50, 'height' => 30,
        ],
        'thermal-40x30' => [
            'label' => 'Thermal 40 × 30 mm',
            'group' => 'Roll thermal',
            'width' => 40, 'height' => 30,
        ],
        'thermal-60x40' => [
            'label' => 'Thermal 60 × 40 mm',
            'group' => 'Roll thermal',
            'width' => 60, 'height' => 40,
        ],
        'thermal-100x50' => [
            'label' => 'Thermal 100 × 50 mm',
            'group' => 'Roll thermal',
            'width' => 100, 'height' => 50,
        ],
        'a4-24' => [
            'label' => 'A4 · 24 label (70 × 37 mm)',
            'group' => 'Lembar A4',
            'width' => 70, 'height' => 37,
            'sheet' => ['width' => 210, 'height' => 297, 'columns' => 3, 'rows' => 8, 'margin_top' => 0.5, 'margin_left' => 0, 'gap_x' => 0, 'gap_y' => 0],
        ],
        'a4-40' => [
            'label' => 'A4 · 40 label (52,5 × 29,7 mm)',
            'group' => 'Lembar A4',
            'width' => 52.5, 'height' => 29.7,
            'sheet' => ['width' => 210, 'height' => 297, 'columns' => 4, 'rows' => 10, 'margin_top' => 0, 'margin_left' => 0, 'gap_x' => 0, 'gap_y' => 0],
        ],
        'a4-65' => [
            'label' => 'A4 · 65 label (38,1 × 21,2 mm)',
            'group' => 'Lembar A4',
            'width' => 38.1, 'height' => 21.2,
            'sheet' => ['width' => 210, 'height' => 297, 'columns' => 5, 'rows' => 13, 'margin_top' => 10.7, 'margin_left' => 4.7, 'gap_x' => 2.5, 'gap_y' => 0],
        ],
    ],

    'default_size' => [
        'products' => 'thermal-50x30',
        'locations' => 'thermal-100x50',
    ],

    // Batas jumlah label sekali cetak, supaya browser tidak berat.
    'max_labels' => 2000,
];
