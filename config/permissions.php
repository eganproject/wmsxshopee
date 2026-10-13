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
