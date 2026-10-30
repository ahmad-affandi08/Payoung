<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-18 (D-251), tahap expand: tukar shift antar karyawan. Karyawan meminta rekannya menukar atau mengambil alih satu
 * shiftnya, rekan menyetujui, lalu pengelola memutuskan. Jadwal baru berubah hanya setelah pengelola menyetujui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('PermintaanTukarShift', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->UuidPublik();
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkPermintaanTukarShiftIdTenant')->restrictOnDelete();
            $tabel->foreignId('IdJadwalPemohon')->constrained('JadwalKerja', 'Id', 'FkPermintaanTukarShiftIdJadwalPemohon')->cascadeOnDelete();
            $tabel->foreignId('IdKaryawanPemohon')->constrained('Karyawan', 'Id', 'FkPermintaanTukarShiftIdKaryawanPemohon')->restrictOnDelete();
            $tabel->foreignId('IdKaryawanRekan')->constrained('Karyawan', 'Id', 'FkPermintaanTukarShiftIdKaryawanRekan')->restrictOnDelete();
            $tabel->foreignId('IdJadwalRekan')->nullable()->constrained('JadwalKerja', 'Id', 'FkPermintaanTukarShiftIdJadwalRekan')->cascadeOnDelete();
            $tabel->string('Status', 20);
            $tabel->string('Alasan', 200);
            $tabel->string('AlasanKeputusan', 200)->nullable();
            $tabel->foreignId('DiputuskanOleh')->nullable()->constrained('Pengguna', 'Id', 'FkPermintaanTukarShiftDiputuskanOleh')->restrictOnDelete();
            $tabel->timestamp('RekanMenanggapiPada')->nullable();
            $tabel->timestamp('DiputuskanPada')->nullable();
            $tabel->WaktuStandar();
            $tabel->index(['IdTenant', 'Status'], 'IdxPermintaanTukarShiftIdTenantStatus');
            $tabel->index(['IdKaryawanRekan', 'Status'], 'IdxPermintaanTukarShiftIdKaryawanRekanStatus');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('PermintaanTukarShift');
    }
};
