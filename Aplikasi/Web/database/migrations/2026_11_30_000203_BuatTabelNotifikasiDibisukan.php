<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F31 (PLT-08): jenis notifikasi yang dibisukan satu pengguna di satu tenant. Baris ada = jenis itu tidak dibuat lagi
 * untuk pengguna tersebut (di pusat notifikasi web maupun push Aplikasi Owner); tanpa baris = semua jenis aktif. Jenis
 * Persetujuan tidak bisa dibisukan (diperiksa `UbahPengaturanNotifikasi`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('NotifikasiDibisukan', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->UuidPublik();
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkNotifikasiDibisukanIdTenant')->restrictOnDelete();
            $tabel->foreignId('IdPengguna')->constrained('Pengguna', 'Id', 'FkNotifikasiDibisukanIdPengguna')->restrictOnDelete();
            $tabel->string('Jenis', 40);
            $tabel->WaktuStandar();
            $tabel->unique(['IdTenant', 'IdPengguna', 'Jenis'], 'UniqNotifikasiDibisukanPenggunaJenis');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('NotifikasiDibisukan');
    }
};
