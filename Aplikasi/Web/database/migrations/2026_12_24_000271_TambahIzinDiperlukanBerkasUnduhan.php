<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-328 (Pusat Unduhan): izin rute ekspor asal berkas disimpan di `IzinDiperlukan` (daftar kunci izin tenant, tiap
 * butir boleh berisi alternatif dipisah `|`). Saat mengunduh, izin itu diperiksa ulang, sehingga pengguna yang izinnya
 * dicabut sesudah berkas disiapkan tidak masih bisa mengambilnya. Kosong (berkas lama) = tidak diperiksa ulang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('BerkasUnduhan', function (Blueprint $tabel): void {
            $tabel->json('IzinDiperlukan')->nullable()->after('Format');
        });
    }

    public function down(): void
    {
        Schema::table('BerkasUnduhan', function (Blueprint $tabel): void {
            $tabel->dropColumn('IzinDiperlukan');
        });
    }
};
