<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit E5 / P-07 (UU PDP): permintaan penghapusan data dan penutupan akun. Alurnya: Owner mengajukan setelah mengunduh
 * ekspor data, ada masa tunggu 14 hari yang bisa dibatalkan, lalu Super Admin melaksanakan: data pribadi dihapus,
 * data transaksi dipertahankan dengan identitas dianonimkan (retensi pembukuan), dan bukti dikirim ke Owner.
 * Baris ini tetap ada sesudah pelaksanaan sebagai bukti (tanpa data pribadi).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('PermintaanPenghapusanData', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->UuidPublik();
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkPermintaanPenghapusanDataIdTenant')->restrictOnDelete();
            $tabel->foreignId('IdPenggunaPeminta')->nullable()->constrained('Pengguna', 'Id', 'FkPermintaanPenghapusanDataIdPeminta')->restrictOnDelete();
            $tabel->string('Status', 20);
            // Alasan dari Owner (opsional); bukan data pribadi pihak ketiga.
            $tabel->string('Alasan', 500)->nullable();
            // Paling cepat dilaksanakan: saat diajukan + masa tunggu (`tenant.HariTungguPenghapusan`).
            $tabel->dateTime('BerlakuPada');
            $tabel->dateTime('DibatalkanPada')->nullable();
            $tabel->foreignId('IdPenggunaPengelolaPemutus')->nullable()->constrained('PenggunaPengelola', 'Id', 'FkPermintaanPenghapusanDataIdPemutus')->restrictOnDelete();
            $tabel->dateTime('DiputuskanPada')->nullable();
            $tabel->string('CatatanPemutus', 500)->nullable();
            $tabel->dateTime('DilaksanakanPada')->nullable();
            // Jumlah baris dihapus/dianonimkan per tabel dan berkas yang dihapus; tanpa isi data.
            $tabel->json('Ringkasan')->nullable();
            $tabel->dateTime('BuktiDikirimPada')->nullable();
            $tabel->dateTime('BerkasPembukuanDihapusPada')->nullable();
            $tabel->WaktuStandar();
            $tabel->index(['IdTenant', 'Status'], 'IdxPermintaanPenghapusanDataTenantStatus');
            $tabel->index(['Status', 'BerlakuPada'], 'IdxPermintaanPenghapusanDataStatusBerlaku');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('PermintaanPenghapusanData');
    }
};
