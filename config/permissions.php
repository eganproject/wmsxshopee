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
                'create' => 'Catat mutasi barang masuk, keluar & transfer lokasi',
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
                'fulfill' => 'Picking, packing & kirim (stok keluar)',
                'cancel' => 'Batalkan pesanan',
                'delete' => 'Hapus draft pesanan',
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
    ],

];
