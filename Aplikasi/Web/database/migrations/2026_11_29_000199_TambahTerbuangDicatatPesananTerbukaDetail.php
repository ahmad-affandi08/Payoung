<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BR-07.5 opsi "waste": penanda bahwa void item (baris yang sudah dikirim ke dapur lalu dibatalkan) dicatat sebagai bahan
 * terbuang `BahanTerbuang` (`Uuid` bahan terbuang = `Uuid` baris). Kosong = stok bahan tidak dikurangi (item belum dibuat).
 * Kolom baru nullable (expand), jadi aman untuk aplikasi kasir lama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('PesananTerbukaDetail', function (Blueprint $tabel): void {
            $tabel->timestamp('TerbuangDicatatPada')->nullable()->after('IdPenyetujuBatal');
        });
    }

    public function down(): void
    {
        Schema::table('PesananTerbukaDetail', function (Blueprint $tabel): void {
            $tabel->dropColumn('TerbuangDicatatPada');
        });
    }
};
