<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-124 Pusat Unduhan (lanjutan D-43): ekspor laporan besar disiapkan di latar belakang dan diunduh dari halaman
 * "Pusat Unduhan". Baris ini jejak permintaan dan status; isinya berkas di disk privat (`tenant.DiskUnduhan`) yang
 * kedaluwarsa otomatis. Hanya peminta yang bisa mengunduh berkasnya (isi laporan mengikuti izin & cakupan outlet peminta).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('BerkasUnduhan', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->UuidPublik();
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkBerkasUnduhanIdTenant')->restrictOnDelete();
            $tabel->foreignId('IdPengguna')->constrained('Pengguna', 'Id', 'FkBerkasUnduhanIdPengguna')->restrictOnDelete();
            $tabel->string('Judul', 150);
            $tabel->string('NamaBerkas', 190)->nullable();
            $tabel->string('Format', 10);
            $tabel->string('Status', 20);
            $tabel->string('PathBerkas', 300)->nullable();
            $tabel->unsignedBigInteger('UkuranByte')->default(0);
            $tabel->string('Galat', 300)->nullable();
            $tabel->dateTime('MulaiPada')->nullable();
            $tabel->dateTime('SelesaiPada')->nullable();
            $tabel->dateTime('KedaluwarsaPada')->nullable();
            $tabel->dateTime('DiunduhPada')->nullable();
            $tabel->WaktuStandar();
            $tabel->index(['IdTenant', 'IdPengguna', 'Status'], 'IdxBerkasUnduhanPenggunaStatus');
            $tabel->index(['Status', 'KedaluwarsaPada'], 'IdxBerkasUnduhanKedaluwarsa');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('BerkasUnduhan');
    }
};
