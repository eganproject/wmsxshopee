<?php

namespace Database\Seeders;

use App\Models\Unit;
use Illuminate\Database\Seeder;

/**
 * Satuan barang umum sebagai data awal. Aman dijalankan berulang kali:
 * satuan yang sudah ada tidak diubah.
 */
class UnitSeeder extends Seeder
{
    public function run(): void
    {
        $units = [
            'PCS' => 'Pieces',
            'PACK' => 'Pack',
            'BOX' => 'Box',
            'SET' => 'Set',
            'LUSIN' => 'Lusin',
            'ROLL' => 'Roll',
            'KG' => 'Kilogram',
            'LTR' => 'Liter',
        ];

        foreach ($units as $code => $name) {
            Unit::firstOrCreate(['code' => $code], ['name' => $name, 'is_active' => true]);
        }
    }
}
