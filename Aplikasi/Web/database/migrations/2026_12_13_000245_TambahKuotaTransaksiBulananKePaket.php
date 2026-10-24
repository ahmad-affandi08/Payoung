<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P-04 (BR-P04.3), tahap expand: batas jumlah transaksi penjualan per bulan kalender di paket, diatur dari konsol seperti
 * batas lainnya (`Paket::KOLOM_BATAS`; add-on dan override per tenant ikut). Null = tak terbatas, jadi paket yang sudah
 * ada tidak berubah perilakunya sampai pengelola mengisi angkanya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Paket', function (Blueprint $tabel): void {
            $tabel->unsignedInteger('KuotaTransaksiBulanan')->nullable()->after('BatasPenyimpananMb');
        });
    }

    public function down(): void
    {
        Schema::table('Paket', function (Blueprint $tabel): void {
            $tabel->dropColumn('KuotaTransaksiBulanan');
        });
    }
};
