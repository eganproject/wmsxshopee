<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Akses gudang per user. User lama otomatis tetap bisa mengakses semua
     * gudang (all_warehouses = true) sehingga tidak ada yang terkunci.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('all_warehouses')->default(true)->after('is_active');
        });

        Schema::create('user_warehouse', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->primary(['user_id', 'warehouse_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_warehouse');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('all_warehouses');
        });
    }
};
