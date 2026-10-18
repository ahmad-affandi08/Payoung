<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-18 (D-180): "Minta koreksi ke manajer". Karyawan yang terus gagal absen (wajah tidak dikenali di tempat gelap, kamera
 * rusak) mengirim alasan; pengelola mencatat jam absennya lewat absensi manual lalu menutup permintaan. Bukan dokumen
 * transaksi: tidak berdampak ke stok atau jurnal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('PermintaanKoreksiAbsensi', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->UuidPublik();
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkPermintaanKoreksiAbsensiIdTenant')->restrictOnDelete();
            $tabel->foreignId('IdKaryawan')->constrained('Karyawan', 'Id', 'FkPermintaanKoreksiAbsensiIdKaryawan')->restrictOnDelete();
            $tabel->foreignId('IdOutlet')->nullable()->constrained('Outlet', 'Id', 'FkPermintaanKoreksiAbsensiIdOutlet')->nullOnDelete();
            $tabel->string('Jenis', 10);
            $tabel->string('Alasan', 200);
            $tabel->string('Status', 20)->default('Menunggu');
            $tabel->foreignId('DiprosesOleh')->nullable()->constrained('Pengguna', 'Id', 'FkPermintaanKoreksiAbsensiDiprosesOleh')->nullOnDelete();
            $tabel->timestamp('DiprosesPada')->nullable();
            $tabel->WaktuStandar();
            $tabel->index(['IdTenant', 'Status', 'DibuatPada'], 'IdxPermintaanKoreksiAbsensiIdTenantStatus');
            $tabel->index(['IdKaryawan', 'Status'], 'IdxPermintaanKoreksiAbsensiIdKaryawanStatus');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('PermintaanKoreksiAbsensi');
    }
};
