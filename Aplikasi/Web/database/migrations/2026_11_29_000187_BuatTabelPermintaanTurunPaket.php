<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit E5 / BR-P04.4: turun paket mandiri. Owner meminta paket yang lebih murah; berlaku pada perpanjangan berikutnya
 * (tagihan perpanjangan memakai paket tujuan) atau, untuk paket Gratis, saat periode berbayar berakhir. Bila paket tujuan
 * lebih kecil dari pemakaian (outlet, pengguna), Owner memilih outlet dan anggota yang tetap aktif; sisanya diarsipkan
 * atau dinonaktifkan saat perubahan berlaku. Data tidak pernah dihapus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('PermintaanTurunPaket', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->UuidPublik();
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkPermintaanTurunPaketIdTenant')->restrictOnDelete();
            $tabel->foreignId('IdLangganan')->constrained('Langganan', 'Id', 'FkPermintaanTurunPaketIdLangganan')->restrictOnDelete();
            $tabel->foreignId('IdPaketAsal')->constrained('Paket', 'Id', 'FkPermintaanTurunPaketIdPaketAsal')->restrictOnDelete();
            $tabel->foreignId('IdPaketTujuan')->constrained('Paket', 'Id', 'FkPermintaanTurunPaketIdPaketTujuan')->restrictOnDelete();
            $tabel->string('Status', 20);
            // Akhir periode berbayar saat diajukan: sebelum itu permintaan masih bisa dibatalkan tanpa akibat.
            $tabel->dateTime('BerlakuPada');
            // Pilihan Owner: Uuid outlet dan anggota yang tetap aktif bila paket tujuan lebih kecil dari pemakaian.
            $tabel->json('UuidOutletTetap')->nullable();
            $tabel->json('UuidAnggotaTetap')->nullable();
            // Salinan kelebihan kapasitas saat diajukan (untuk audit dan tampilan), bukan sumber keputusan.
            $tabel->json('Kelebihan')->nullable();
            $tabel->foreignId('IdPenggunaPengaju')->nullable()->constrained('Pengguna', 'Id', 'FkPermintaanTurunPaketIdPengaju')->restrictOnDelete();
            $tabel->dateTime('DiterapkanPada')->nullable();
            $tabel->dateTime('DibatalkanPada')->nullable();
            $tabel->string('AlasanBatal', 300)->nullable();
            $tabel->WaktuStandar();
            $tabel->index(['IdLangganan', 'Status'], 'IdxPermintaanTurunPaketLanggananStatus');
            $tabel->index(['Status', 'BerlakuPada'], 'IdxPermintaanTurunPaketStatusBerlaku');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('PermintaanTurunPaket');
    }
};
