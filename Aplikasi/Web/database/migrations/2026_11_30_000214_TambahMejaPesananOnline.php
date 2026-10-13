<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * F-17 (SLS-04): pesanan QR meja yang dibayar QRIS dari HP tamu memakai jalur `PesananOnline` dengan `Sumber` = Meja.
 * `IdMeja` menunjuk meja asal (batas pesanan menunggu per meja dan pencocokan kode akses). Kolom null untuk pesanan
 * toko online dan kios; tanpa FK karena meja bisa dinonaktifkan tanpa mengubah riwayat pesanan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('PesananOnline', function (Blueprint $tabel): void {
            $tabel->unsignedBigInteger('IdMeja')->nullable()->after('NomorAntrian');
            $tabel->index(['IdTenant', 'IdMeja', 'Status'], 'IdxPesananOnlineIdTenantMejaStatus');
        });
    }

    public function down(): void
    {
        Schema::table('PesananOnline', function (Blueprint $tabel): void {
            $tabel->dropIndex('IdxPesananOnlineIdTenantMejaStatus');
            $tabel->dropColumn('IdMeja');
        });
    }
};
