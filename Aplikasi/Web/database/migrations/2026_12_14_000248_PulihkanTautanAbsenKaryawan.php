<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-18 (D-198), tahap expand: tautan absen pribadi per karyawan kembali sebagai jalan masuk kedua ke halaman Absen
 * (di samping login, D-180). Yang bisa dibagikan lewat WhatsApp; cocok untuk staf tanpa akun dashboard. Kolomnya sama
 * dengan yang dihapus migrasi 000243: token terenkripsi (ditampilkan ulang ke pengelola) dan hash-nya untuk pencarian.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Karyawan', function (Blueprint $tabel): void {
            $tabel->text('TokenAbsen')->nullable();
            $tabel->char('HashTokenAbsen', 64)->nullable()->unique('UniqKaryawanHashTokenAbsen');
            $tabel->timestamp('TokenAbsenDibuatPada')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('Karyawan', function (Blueprint $tabel): void {
            $tabel->dropUnique('UniqKaryawanHashTokenAbsen');
            $tabel->dropColumn(['TokenAbsen', 'HashTokenAbsen', 'TokenAbsenDibuatPada']);
        });
    }
};
