<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-18 (D-251), tahap expand: shift bernama dan jadwal lebih dari satu shift per hari.
 *
 * - `ShiftKerja`: master shift per usaha (Pagi, Siang, Malam, dst.) berisi jam mulai/selesai. Menerapkannya ke jadwal
 *   menyalin jamnya, jadi mengubah shift tidak mengubah jadwal yang sudah terbit.
 * - `JadwalKerja.IdShiftKerja`: shift asal baris jadwal (kosong = jam diketik sendiri).
 * - Kunci unik jadwal berganti dari (karyawan, tanggal) ke (karyawan, tanggal, jam mulai) sehingga satu karyawan boleh
 *   punya dua shift terpisah di hari yang sama. Aksi menjaga agar shift tidak saling tumpang tindih.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ShiftKerja', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->UuidPublik();
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkShiftKerjaIdTenant')->restrictOnDelete();
            $tabel->string('Nama', 40);
            $tabel->char('JamMulai', 5);
            $tabel->char('JamSelesai', 5);
            $tabel->boolean('Aktif')->default(true);
            $tabel->unsignedSmallInteger('Urutan')->default(0);
            $tabel->WaktuStandar();
            $tabel->unique(['IdTenant', 'Nama'], 'UniqShiftKerjaIdTenantNama');
        });

        Schema::table('JadwalKerja', function (Blueprint $tabel): void {
            $tabel->foreignId('IdShiftKerja')->nullable()->after('IdOutlet')->constrained('ShiftKerja', 'Id', 'FkJadwalKerjaIdShiftKerja')->nullOnDelete();
            $tabel->unique(['IdKaryawan', 'Tanggal', 'JamMulai'], 'UniqJadwalKerjaIdKaryawanTanggalJamMulai');
        });

        Schema::table('JadwalKerja', function (Blueprint $tabel): void {
            $tabel->dropUnique('UniqJadwalKerjaIdKaryawanTanggal');
        });
    }

    public function down(): void
    {
        Schema::table('JadwalKerja', function (Blueprint $tabel): void {
            $tabel->unique(['IdKaryawan', 'Tanggal'], 'UniqJadwalKerjaIdKaryawanTanggal');
        });

        Schema::table('JadwalKerja', function (Blueprint $tabel): void {
            $tabel->dropUnique('UniqJadwalKerjaIdKaryawanTanggalJamMulai');
            $tabel->dropForeign('FkJadwalKerjaIdShiftKerja');
            $tabel->dropColumn('IdShiftKerja');
        });

        Schema::dropIfExists('ShiftKerja');
    }
};
