<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-18 (D-180), tahap contract: absen sekarang hanya lewat login. Tautan absen rahasia per karyawan, layar QR berganti
 * di outlet, dan penanda QR pada absensi dihapus. Belum ada data yang perlu dipertahankan: tautan hanyalah kunci akses
 * yang dibuat ulang bila perlu, dan kode QR tidak pernah disimpan (hanya hasil verifikasinya).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Karyawan', function (Blueprint $tabel): void {
            $tabel->dropUnique('UniqKaryawanHashTokenAbsen');
            $tabel->dropColumn(['TokenAbsen', 'HashTokenAbsen', 'TokenAbsenDibuatPada']);
        });

        Schema::table('Outlet', function (Blueprint $tabel): void {
            $tabel->dropUnique('UniqOutletHashTokenLayarAbsen');
            $tabel->dropColumn(['TokenLayarAbsen', 'HashTokenLayarAbsen', 'WajibQrAbsensi']);
        });

        Schema::table('Absensi', function (Blueprint $tabel): void {
            $tabel->dropColumn(['QrMasukTerverifikasi', 'QrKeluarTerverifikasi']);
        });
    }

    public function down(): void
    {
        Schema::table('Absensi', function (Blueprint $tabel): void {
            $tabel->boolean('QrMasukTerverifikasi')->nullable();
            $tabel->boolean('QrKeluarTerverifikasi')->nullable();
        });

        Schema::table('Outlet', function (Blueprint $tabel): void {
            $tabel->text('TokenLayarAbsen')->nullable();
            $tabel->char('HashTokenLayarAbsen', 64)->nullable()->unique('UniqOutletHashTokenLayarAbsen');
            $tabel->boolean('WajibQrAbsensi')->default(false);
        });

        Schema::table('Karyawan', function (Blueprint $tabel): void {
            $tabel->text('TokenAbsen')->nullable();
            $tabel->char('HashTokenAbsen', 64)->nullable();
            $tabel->timestamp('TokenAbsenDibuatPada')->nullable();
            $tabel->unique('HashTokenAbsen', 'UniqKaryawanHashTokenAbsen');
        });
    }
};
