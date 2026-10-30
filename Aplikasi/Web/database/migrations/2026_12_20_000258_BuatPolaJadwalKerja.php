<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-18 (D-251), tahap expand: pola jadwal mingguan berulang. Satu baris = satu shift karyawan pada hari tertentu dalam
 * seminggu (`HariKe` 1 = Senin … 7 = Minggu) di satu outlet. Pola dipakai `TerapkanPolaJadwal` untuk mengisi jadwal minggu
 * demi minggu (tombol di halaman Jadwal dan perintah terjadwal), hanya pada hari yang masih kosong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('PolaJadwalKerja', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkPolaJadwalKerjaIdTenant')->restrictOnDelete();
            $tabel->foreignId('IdKaryawan')->constrained('Karyawan', 'Id', 'FkPolaJadwalKerjaIdKaryawan')->restrictOnDelete();
            $tabel->foreignId('IdOutlet')->constrained('Outlet', 'Id', 'FkPolaJadwalKerjaIdOutlet')->restrictOnDelete();
            $tabel->foreignId('IdShiftKerja')->nullable()->constrained('ShiftKerja', 'Id', 'FkPolaJadwalKerjaIdShiftKerja')->nullOnDelete();
            $tabel->unsignedTinyInteger('HariKe');
            $tabel->char('JamMulai', 5);
            $tabel->char('JamSelesai', 5);
            $tabel->WaktuStandar();
            $tabel->unique(['IdKaryawan', 'HariKe', 'JamMulai'], 'UniqPolaJadwalKerjaIdKaryawanHariKeJamMulai');
            $tabel->index(['IdTenant', 'IdOutlet'], 'IdxPolaJadwalKerjaIdTenantIdOutlet');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('PolaJadwalKerja');
    }
};
