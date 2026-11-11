<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-18 (D-267), tahap contract: pola jadwal berulang (migrasi 000258) dicabut, jadwal cukup lewat Isi massal. Data pola
 * hanya mesin pengisi jadwal; jadwal yang sudah terisi (`JadwalKerja`) tidak tersentuh.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('PolaJadwalKerja');
    }

    public function down(): void
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
};
