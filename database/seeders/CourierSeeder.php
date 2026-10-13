<?php

namespace Database\Seeders;

use App\Models\Courier;
use Illuminate\Database\Seeder;

/**
 * Kurir umum di Indonesia sebagai data awal. Aman dijalankan berulang kali:
 * kurir yang sudah ada tidak diubah.
 */
class CourierSeeder extends Seeder
{
    public function run(): void
    {
        $couriers = [
            ['code' => 'SPX', 'name' => 'SPX Express (Shopee Express)', 'services' => [['code' => 'STD', 'name' => 'Standard'], ['code' => 'HEMAT', 'name' => 'Hemat']]],
            ['code' => 'JNE', 'name' => 'JNE Express', 'services' => [['code' => 'REG', 'name' => 'Reguler'], ['code' => 'YES', 'name' => 'Yakin Esok Sampai'], ['code' => 'OKE', 'name' => 'Ongkos Kirim Ekonomis']]],
            ['code' => 'JNT', 'name' => 'J&T Express', 'services' => [['code' => 'EZ', 'name' => 'Reguler']]],
            ['code' => 'SICEPAT', 'name' => 'SiCepat Ekspres', 'services' => [['code' => 'REG', 'name' => 'Reguler'], ['code' => 'BEST', 'name' => 'Besok Sampai Tujuan']]],
            ['code' => 'ANTERAJA', 'name' => 'AnterAja', 'services' => [['code' => 'REG', 'name' => 'Reguler']]],
            ['code' => 'NINJA', 'name' => 'Ninja Xpress', 'services' => [['code' => 'STD', 'name' => 'Standard']]],
            ['code' => 'IDE', 'name' => 'ID Express', 'services' => [['code' => 'STD', 'name' => 'Standard']]],
            ['code' => 'POS', 'name' => 'Pos Indonesia', 'services' => [['code' => 'REG', 'name' => 'Pos Reguler']]],
        ];

        foreach ($couriers as $courier) {
            Courier::firstOrCreate(['code' => $courier['code']], [...$courier, 'is_active' => true]);
        }
    }
}
