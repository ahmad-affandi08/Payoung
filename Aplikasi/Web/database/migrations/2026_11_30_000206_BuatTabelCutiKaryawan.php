<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-18 cuti/izin karyawan (EMP-03): `CutiKaryawan` = rentang tanggal karyawan tidak bekerja (cuti, sakit, izin) yang
 * dicatat pengelola. Cuti berbayar tidak dihitung "tidak masuk" di rekap gaji dan tidak memicu peringatan belum masuk;
 * yang tidak berbayar tetap dipotong. Dokumen tidak diubah: koreksi lewat pembatalan (status + alasan), lalu catat ulang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('CutiKaryawan', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->UuidPublik();
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkCutiKaryawanIdTenant')->restrictOnDelete();
            $tabel->foreignId('IdKaryawan')->constrained('Karyawan', 'Id', 'FkCutiKaryawanIdKaryawan')->restrictOnDelete();
            $tabel->string('Jenis', 10);
            $tabel->date('TanggalMulai');
            $tabel->date('TanggalSelesai');
            $tabel->boolean('Berbayar')->default(true);
            $tabel->string('Alasan', 255)->nullable();
            $tabel->string('Status', 12);
            $tabel->timestamp('DibatalkanPada')->nullable();
            $tabel->string('AlasanBatal', 500)->nullable();
            $tabel->foreignId('DibuatOleh')->nullable()->constrained('Pengguna', 'Id', 'FkCutiKaryawanDibuatOleh')->restrictOnDelete();
            $tabel->WaktuStandar();
            $tabel->index(['IdTenant', 'IdKaryawan', 'Status', 'TanggalMulai'], 'IdxCutiKaryawanIdTenantIdKaryawanStatusMulai');
            $tabel->index(['IdTenant', 'Status', 'TanggalSelesai'], 'IdxCutiKaryawanIdTenantStatusSelesai');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('CutiKaryawan');
    }
};
