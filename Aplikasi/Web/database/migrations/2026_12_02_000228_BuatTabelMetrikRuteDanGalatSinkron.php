<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PGL-20 lanjutan (PRD v6.28, D-148): metrik per rute API (endpoint terlambat) dan galat sinkron terbanyak. Keduanya
 * tabel platform per jam: tidak memuat data usaha tenant, pengguna, jalur lengkap, maupun isi permintaan. Hanya menambah
 * tabel (expand).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('MetrikRuteApi')) {
            Schema::create('MetrikRuteApi', function (Blueprint $tabel): void {
                $tabel->id('Id');
                $tabel->string('Kelompok', 20);
                // Nama rute Laravel (kebab-case titik), bukan jalur: tidak memuat ULID atau parameter tenant.
                $tabel->string('NamaRute', 120);
                $tabel->timestamp('Jam');
                $tabel->unsignedInteger('JumlahPermintaan')->default(0);
                $tabel->unsignedInteger('JumlahGalatServer')->default(0);
                $tabel->unsignedBigInteger('TotalDurasiMs')->default(0);
                $tabel->unsignedInteger('Hingga100Ms')->default(0);
                $tabel->unsignedInteger('Hingga250Ms')->default(0);
                $tabel->unsignedInteger('Hingga400Ms')->default(0);
                $tabel->unsignedInteger('Hingga800Ms')->default(0);
                $tabel->unsignedInteger('Hingga1600Ms')->default(0);
                $tabel->unsignedInteger('Lebih1600Ms')->default(0);
                $tabel->WaktuStandar();
                $tabel->unique(['Kelompok', 'NamaRute', 'Jam'], 'UniqMetrikRuteApiKelompokRuteJam');
                $tabel->index('Jam', 'IdxMetrikRuteApiJam');
            });
        }

        if (! Schema::hasTable('MetrikGalatSinkron')) {
            Schema::create('MetrikGalatSinkron', function (Blueprint $tabel): void {
                $tabel->id('Id');
                $tabel->timestamp('Jam');
                // Jenis item outbox (`Penjualan.Buat`) dan kode galat bisnis (`StokTidakCukup`); tanpa pesan atau isi.
                $tabel->string('JenisItem', 60);
                $tabel->string('KodeGalat', 80);
                $tabel->unsignedInteger('Jumlah')->default(0);
                $tabel->WaktuStandar();
                $tabel->unique(['Jam', 'JenisItem', 'KodeGalat'], 'UniqMetrikGalatSinkronJamJenisKode');
                $tabel->index('Jam', 'IdxMetrikGalatSinkronJam');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('MetrikGalatSinkron');
        Schema::dropIfExists('MetrikRuteApi');
    }
};
