<?php

/*
|--------------------------------------------------------------------------
| Daftar Permission Aplikasi
|--------------------------------------------------------------------------
|
| Permission dikelompokkan per modul. Format nama: "{modul}.{aksi}".
| Setelah menambah modul / aksi baru, jalankan:
|
|   php artisan db:seed --class=PermissionSeeder
|
*/

return [

    'super_admin_role' => 'Super Admin',

    'modules' => [
        'dashboard' => [
            'label' => 'Dashboard',
            'icon' => 'heroicon-o-home',
            'actions' => [
                'view' => 'Lihat statistik dashboard',
            ],
        ],

        'warehouses' => [
            'label' => 'Master Gudang',
            'icon' => 'heroicon-o-building-office-2',
            'actions' => [
                'view' => 'Lihat gudang, zona, rak & lokasi',
                'create' => 'Tambah gudang, zona, rak & lokasi',
                'edit' => 'Ubah gudang, zona, rak & lokasi',
                'delete' => 'Hapus gudang, zona, rak & lokasi',
            ],
        ],

        'products' => [
            'label' => 'Master Produk',
            'icon' => 'heroicon-o-cube',
            'actions' => [
                'view' => 'Lihat produk, varian & satuan',
                'create' => 'Tambah produk & satuan',
                'edit' => 'Ubah produk, varian, gambar & satuan',
                'delete' => 'Hapus produk & satuan',
            ],
        ],

        'inventory' => [
            'label' => 'Stok',
            'icon' => 'heroicon-o-archive-box',
            'actions' => [
                'view' => 'Lihat stok serta tab Kartu Stok & Mutasi Stok',
                'create' => 'Catat transfer lokasi & impor stok awal',
                'adjust' => 'Catat mutasi penyesuaian stok',
            ],
        ],

        'receipts' => [
            'label' => 'Penerimaan Barang',
            'icon' => 'heroicon-o-inbox-arrow-down',
            'actions' => [
                'view' => 'Lihat penerimaan barang',
                'create' => 'Buat & ubah draft penerimaan',
                'receive' => 'Konfirmasi diterima (stok masuk)',
                'delete' => 'Hapus draft penerimaan',
            ],
        ],

        'opname' => [
            'label' => 'Stock Opname',
            'icon' => 'heroicon-o-clipboard-document-check',
            'actions' => [
                'view' => 'Lihat & cetak lembar opname',
                'create' => 'Buat lembar & isi hasil hitung',
                'post' => 'Posting selisih opname ke stok',
                'delete' => 'Hapus lembar opname draft',
            ],
        ],

        'reports' => [
            'label' => 'Laporan',
            'icon' => 'heroicon-o-chart-bar',
            'actions' => [
                'view' => 'Lihat & ekspor laporan stok',
            ],
        ],

        'sales' => [
            'label' => 'Pesanan Penjualan',
            'icon' => 'heroicon-o-shopping-cart',
            'actions' => [
                'view' => 'Lihat pesanan & cetak picking list',
                'create' => 'Buat, ubah & impor draft pesanan',
                'confirm' => 'Konfirmasi pesanan (cadangkan stok)',
                'fulfill' => 'Picking, QC scan, atur pickup / drop off, cetak resi & serah terima (stok keluar)',
                'skip_scan' => 'Kemas & kirim tanpa scan (lewati QC / serah terima)',
                'cancel' => 'Batalkan pesanan',
                'delete' => 'Hapus draft pesanan',
                'income' => 'Lihat rincian pendapatan Shopee (harga, potongan & dana diterima)',
            ],
        ],

        'returns' => [
            'label' => 'Retur Penjualan',
            'icon' => 'heroicon-o-arrow-uturn-left',
            'actions' => [
                'view' => 'Lihat retur penjualan',
                'create' => 'Buat & ubah draft retur',
                'receive' => 'Terima retur (barang layak jual masuk stok)',
                'delete' => 'Hapus draft retur',
            ],
        ],

        'suppliers' => [
            'label' => 'Master Supplier',
            'icon' => 'heroicon-o-building-storefront',
            'actions' => [
                'view' => 'Lihat daftar supplier',
                'create' => 'Tambah supplier',
                'edit' => 'Ubah data supplier',
                'delete' => 'Hapus supplier',
            ],
        ],

        'couriers' => [
            'label' => 'Master Kurir',
            'icon' => 'heroicon-o-truck',
            'actions' => [
                'view' => 'Lihat daftar kurir',
                'create' => 'Tambah kurir',
                'edit' => 'Ubah data kurir',
                'delete' => 'Hapus kurir',
            ],
        ],

        'users' => [
            'label' => 'Manajemen User',
            'icon' => 'heroicon-o-users',
            'actions' => [
                'view' => 'Lihat daftar user',
                'create' => 'Buat akun user',
                'edit' => 'Ubah data & hak akses user',
                'delete' => 'Hapus user',
            ],
        ],

        'roles' => [
            'label' => 'Role & Permission',
            'icon' => 'heroicon-o-shield-check',
            'actions' => [
                'view' => 'Lihat daftar role',
                'create' => 'Buat role',
                'edit' => 'Ubah role & permission',
                'delete' => 'Hapus role',
            ],
        ],

        'activity' => [
            'label' => 'Log Aktivitas',
            'icon' => 'heroicon-o-clock',
            'actions' => [
                'view' => 'Lihat log aktivitas semua user',
            ],
        ],

        'backups' => [
            'label' => 'Backup Database',
            'icon' => 'heroicon-o-circle-stack',
            'actions' => [
                'view' => 'Lihat & unduh backup database',
                'create' => 'Buat backup sekarang',
                'delete' => 'Hapus file backup',
            ],
        ],

        'shopee' => [
            'label' => 'Integrasi Shopee',
            'icon' => 'heroicon-o-building-storefront',
            'actions' => [
                'view' => 'Lihat toko Shopee & pemetaan SKU',
                'settings' => 'Ubah Partner ID & Partner Key Shopee',
                'connect' => 'Hubungkan, perbarui token & hapus koneksi toko',
                'mapping' => 'Tarik produk & atur pemetaan SKU',
                'sync' => 'Sinkron pesanan, resi, stok & retur sekarang',
            ],
        ],
    ],

];
