<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-18 (D-250), tahap expand: absensi yang lupa absen keluar ditutup otomatis sesuai jam selesai jadwal supaya jam
 * kerjanya tidak menyambung ke hari berikutnya. `Absensi.KeluarOtomatis` menandai keluar yang diisi sistem (bukan
 * karyawan). `AturanKehadiran` mendapat saklar dan batas tunggu setelah jam selesai (menit). Bawaan: aktif, 120 menit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Absensi', function (Blueprint $tabel): void {
            $tabel->boolean('KeluarOtomatis')->default(false)->after('KeluarPada');
        });

        Schema::table('AturanKehadiran', function (Blueprint $tabel): void {
            $tabel->boolean('TutupOtomatisAktif')->default(true);
            $tabel->unsignedSmallInteger('TutupOtomatisSetelahMenit')->default(120);
        });
    }

    public function down(): void
    {
        Schema::table('AturanKehadiran', function (Blueprint $tabel): void {
            $tabel->dropColumn(['TutupOtomatisAktif', 'TutupOtomatisSetelahMenit']);
        });

        Schema::table('Absensi', function (Blueprint $tabel): void {
            $tabel->dropColumn('KeluarOtomatis');
        });
    }
};
