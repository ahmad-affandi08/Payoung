<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-03 / X8 (v7.86), tahap expand: harga pilihan tambahan (modifier) per kanal.
 *
 * `Pilihan.HargaKanal` = peta kanal ke harga, misal `{"GoFood":"6500.00","GrabFood":"6500.00"}`. Kosong (null) atau tanpa
 * kunci kanal itu = pakai `Pilihan.Harga` seperti biasa. Diisi lewat "Atur harga ojol" dan dibaca aplikasi Kasir saat
 * kanal keranjang adalah platform ojol. Kolom aditif: aplikasi lama mengabaikannya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Pilihan', function (Blueprint $tabel): void {
            $tabel->json('HargaKanal')->nullable()->after('Harga');
        });
    }

    public function down(): void
    {
        Schema::table('Pilihan', function (Blueprint $tabel): void {
            $tabel->dropColumn('HargaKanal');
        });
    }
};
