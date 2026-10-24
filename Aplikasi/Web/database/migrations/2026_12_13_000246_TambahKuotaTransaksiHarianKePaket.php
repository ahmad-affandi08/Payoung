<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P-04 (BR-P04.8, D-185), tahap expand: batas jumlah transaksi penjualan per hari bisnis di paket, berdiri sendiri di
 * samping `KuotaTransaksiBulanan` (yang tercapai lebih dulu menahan transaksi baru). Null = tak terbatas, jadi paket yang
 * sudah ada tidak berubah perilakunya sampai pengelola mengisi angkanya di konsol.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Paket', function (Blueprint $tabel): void {
            $tabel->unsignedInteger('KuotaTransaksiHarian')->nullable()->after('KuotaTransaksiBulanan');
        });
    }

    public function down(): void
    {
        Schema::table('Paket', function (Blueprint $tabel): void {
            $tabel->dropColumn('KuotaTransaksiHarian');
        });
    }
};
