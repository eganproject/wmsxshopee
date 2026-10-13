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
