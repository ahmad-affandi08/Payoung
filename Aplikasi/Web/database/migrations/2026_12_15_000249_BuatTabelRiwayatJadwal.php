<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-209 (konsol tahap 3): riwayat jalannya perintah terjadwal, supaya petugas teknis melihat kapan tiap jadwal terakhir
 * berjalan, berhasil atau tidak, dan berapa lama. Tabel platform: hanya nama perintah, waktu, dan hasil singkat (tanpa
 * data tenant). Hanya menambah tabel (expand).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('RiwayatJadwal', function (Blueprint $tabel): void {
            $tabel->id('Id');
            // Nama perintah artisan beserta argumennya, misal `persediaan:bangun-ulang-saldo --periksa`.
            $tabel->string('Perintah', 160);
            $tabel->string('Sumber', 10)->default('Jadwal');
            $tabel->dateTime('MulaiPada');
            $tabel->dateTime('SelesaiPada')->nullable();
            $tabel->boolean('Berhasil')->nullable();
            $tabel->unsignedInteger('DurasiMs')->nullable();
            $tabel->string('Keterangan', 300)->nullable();
            $tabel->unsignedBigInteger('IdPenggunaPengelola')->nullable();
            $tabel->WaktuStandar();
            $tabel->index(['Perintah', 'MulaiPada'], 'IdxRiwayatJadwalPerintahMulai');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('RiwayatJadwal');
    }
};
