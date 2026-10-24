<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-08 (D-189), tahap expand: teks payload QRIS statis milik toko (hasil baca gambar QR di peramban). Dengan teks ini
 * aplikasi Kasir menyusun QRIS bernominal di perangkat tanpa penyedia pembayaran. Null = metode lama yang hanya punya
 * gambar (tetap berjalan seperti sebelumnya).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('MetodePembayaran', function (Blueprint $tabel): void {
            $tabel->string('TeksQris', 600)->nullable()->after('PathGambarQris');
        });
    }

    public function down(): void
    {
        Schema::table('MetodePembayaran', function (Blueprint $tabel): void {
            $tabel->dropColumn('TeksQris');
        });
    }
};
