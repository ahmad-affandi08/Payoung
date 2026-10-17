<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Telepon outlet (F-02, F-07 cetak struk, D-177): sumber baris "Telp." di kepala struk saat saklar Telepon outlet
 * menyala. Sebelumnya kolom ini tidak ada sehingga saklar itu tidak pernah mencetak apa pun. Aditif dan opsional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Outlet', function (Blueprint $tabel): void {
            $tabel->string('Telepon', 30)->nullable()->after('Alamat');
        });
    }

    public function down(): void
    {
        Schema::table('Outlet', function (Blueprint $tabel): void {
            $tabel->dropColumn('Telepon');
        });
    }
};
