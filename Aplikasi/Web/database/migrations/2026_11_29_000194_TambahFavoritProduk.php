<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * K-16 produk favorit pilihan pemilik (F-03): `Produk.Favorit`. Pemilik menandai produk yang sering dijual; aplikasi
 * Kasir menampilkannya di kategori "Favorit". Atribut tingkat tenant (bukan per outlet). Expand-only: kolom
 * berbawaan false, produk lama tidak berubah makna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Produk', function (Blueprint $tabel): void {
            $tabel->boolean('Favorit')->default(false)->after('TampilOnline');
        });
    }

    public function down(): void
    {
        Schema::table('Produk', function (Blueprint $tabel): void {
            $tabel->dropColumn('Favorit');
        });
    }
};
