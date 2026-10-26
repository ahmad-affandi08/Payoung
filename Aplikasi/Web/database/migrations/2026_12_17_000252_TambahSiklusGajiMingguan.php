<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F-18 gaji mingguan (D-219), tahap expand: karyawan punya siklus gaji (Bulanan atau Mingguan) dan dasar gaji (Tetap =
 * gaji pokok per siklus; PerHariMasuk = upah per hari × hari masuk). Rekap gaji tidak lagi terikat bulan kalender:
 * `Siklus` + rentang `TanggalMulai`..`TanggalSelesai`. `Periode` tetap kunci unik per tenant (bulanan `YYYY-MM`,
 * mingguan tanggal mulai `YYYY-MM-DD`, jadi tidak pernah bentrok) dan melebar dari 7 ke 10 karakter. Rekap lama diisi
 * rentang bulannya; semua karyawan lama tetap Bulanan/Tetap sehingga perilaku mereka tidak berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Karyawan', function (Blueprint $tabel): void {
            $tabel->string('SiklusGaji', 10)->default('Bulanan');
            $tabel->string('DasarGaji', 15)->default('Tetap');
        });

        Schema::table('RekapGaji', function (Blueprint $tabel): void {
            $tabel->string('Periode', 10)->change();
        });

        Schema::table('RekapGaji', function (Blueprint $tabel): void {
            $tabel->string('Siklus', 10)->default('Bulanan');
            $tabel->date('TanggalMulai')->nullable();
            $tabel->date('TanggalSelesai')->nullable();
        });

        DB::statement("UPDATE RekapGaji SET TanggalMulai = STR_TO_DATE(CONCAT(Periode, '-01'), '%Y-%m-%d'), TanggalSelesai = LAST_DAY(STR_TO_DATE(CONCAT(Periode, '-01'), '%Y-%m-%d')) WHERE TanggalMulai IS NULL");

        Schema::table('RekapGajiBaris', function (Blueprint $tabel): void {
            $tabel->decimal('UpahHarian', 18, 2)->nullable();
            $tabel->unsignedSmallInteger('HariMasuk')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('RekapGajiBaris', function (Blueprint $tabel): void {
            $tabel->dropColumn(['UpahHarian', 'HariMasuk']);
        });

        Schema::table('RekapGaji', function (Blueprint $tabel): void {
            $tabel->dropColumn(['Siklus', 'TanggalMulai', 'TanggalSelesai']);
        });

        Schema::table('Karyawan', function (Blueprint $tabel): void {
            $tabel->dropColumn(['SiklusGaji', 'DasarGaji']);
        });
    }
};
