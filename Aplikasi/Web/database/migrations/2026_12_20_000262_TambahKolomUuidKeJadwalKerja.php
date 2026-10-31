<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * F-18 / D-251: `JadwalKerja.Uuid` (ID publik). Tukar shift, halaman Jadwal saya, dan Jadwal mingguan menunjuk baris
 * jadwal lewat Uuid, tetapi tabel dibuat tanpa kolom itu sehingga fiturnya tidak pernah bisa jalan. Kolom ditambah
 * nullable, baris lama diisi ULID per potongan, lalu dijadikan wajib dan unik.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('JadwalKerja', function (Blueprint $tabel): void {
            $tabel->char('Uuid', 26)->nullable()->after('Id');
        });

        DB::table('JadwalKerja')->whereNull('Uuid')->orderBy('Id')->chunkById(500, function ($daftar): void {
            foreach ($daftar as $baris) {
                DB::table('JadwalKerja')->where('Id', $baris->Id)->update(['Uuid' => (string) Str::ulid()]);
            }
        }, 'Id');

        Schema::table('JadwalKerja', function (Blueprint $tabel): void {
            $tabel->char('Uuid', 26)->nullable(false)->change();
            $tabel->unique('Uuid', 'UniqJadwalKerjaUuid');
        });
    }

    public function down(): void
    {
        Schema::table('JadwalKerja', function (Blueprint $tabel): void {
            $tabel->dropUnique('UniqJadwalKerjaUuid');
            $tabel->dropColumn('Uuid');
        });
    }
};
