<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-04 (PR): permintaan pembelian `PP/{OUTLET}/{YYMM}/{SEQ4}` dari outlet sebelum menjadi pesanan pembelian (PO).
 * Status Draf → Diajukan → Disetujui → DijadikanPo (+ Ditolak → Draf, Dibatalkan). Tidak berdampak stok maupun jurnal;
 * harga di baris hanya perkiraan. Pemasok usulan opsional (dipilih saat dijadikan PO). Dokumen: tanpa soft delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('PermintaanPembelian', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->UuidPublik();
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkPermintaanPembelianIdTenant')->restrictOnDelete();
            $tabel->string('Nomor', 30);
            $tabel->foreignId('IdGudang')->constrained('Gudang', 'Id', 'FkPermintaanPembelianIdGudang')->restrictOnDelete();
            $tabel->foreignId('IdOutlet')->nullable()->constrained('Outlet', 'Id', 'FkPermintaanPembelianIdOutlet')->restrictOnDelete();
            $tabel->foreignId('IdPemasok')->nullable()->constrained('Pemasok', 'Id', 'FkPermintaanPembelianIdPemasok')->restrictOnDelete();
            $tabel->date('Tanggal');
            $tabel->date('DibutuhkanPada')->nullable();
            $tabel->string('Status', 20);
            $tabel->string('Catatan', 500)->nullable();
            $tabel->string('AlasanDitolak', 255)->nullable();
            $tabel->string('AlasanBatal', 255)->nullable();
            $tabel->foreignId('DibuatOleh')->nullable()->constrained('Pengguna', 'Id', 'FkPermintaanPembelianDibuatOleh')->restrictOnDelete();
            $tabel->foreignId('DiubahOleh')->nullable()->constrained('Pengguna', 'Id', 'FkPermintaanPembelianDiubahOleh')->restrictOnDelete();
            $tabel->foreignId('DiajukanOleh')->nullable()->constrained('Pengguna', 'Id', 'FkPermintaanPembelianDiajukanOleh')->restrictOnDelete();
            $tabel->timestamp('DiajukanPada')->nullable();
            $tabel->foreignId('DiputuskanOleh')->nullable()->constrained('Pengguna', 'Id', 'FkPermintaanPembelianDiputuskanOleh')->restrictOnDelete();
            $tabel->timestamp('DiputuskanPada')->nullable();
            $tabel->foreignId('DibatalkanOleh')->nullable()->constrained('Pengguna', 'Id', 'FkPermintaanPembelianDibatalkanOleh')->restrictOnDelete();
            $tabel->timestamp('DibatalkanPada')->nullable();
            $tabel->foreignId('IdPesananPembelian')->nullable()->constrained('PesananPembelian', 'Id', 'FkPermintaanPembelianIdPesananPembelian')->restrictOnDelete();
            $tabel->foreignId('DijadikanPoOleh')->nullable()->constrained('Pengguna', 'Id', 'FkPermintaanPembelianDijadikanPoOleh')->restrictOnDelete();
            $tabel->timestamp('DijadikanPoPada')->nullable();
            $tabel->WaktuStandar();
            $tabel->unique(['IdTenant', 'Nomor'], 'UniqPermintaanPembelianIdTenantNomor');
            $tabel->index(['IdTenant', 'Status', 'Tanggal'], 'IdxPermintaanPembelianIdTenantStatusTanggal');
            $tabel->index(['IdTenant', 'IdOutlet', 'Tanggal'], 'IdxPermintaanPembelianIdTenantIdOutletTanggal');
        });

        Schema::create('PermintaanPembelianDetail', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkPermintaanPembelianDetailIdTenant')->restrictOnDelete();
            $tabel->foreignId('IdPermintaanPembelian')->constrained('PermintaanPembelian', 'Id', 'FkPermintaanPembelianDetailIdPermintaan')->restrictOnDelete();
            $tabel->unsignedInteger('Urutan');
            $tabel->foreignId('IdProduk')->constrained('Produk', 'Id', 'FkPermintaanPembelianDetailIdProduk')->restrictOnDelete();
            $tabel->string('NamaProduk', 150);
            $tabel->string('Sku', 64)->nullable();
            $tabel->foreignId('IdProdukSatuan')->nullable()->constrained('ProdukSatuan', 'Id', 'FkPermintaanPembelianDetailIdProdukSatuan')->nullOnDelete();
            $tabel->string('SimbolSatuan', 20);
            $tabel->decimal('Konversi', 18, 4);
            $tabel->decimal('Jumlah', 18, 4);
            $tabel->decimal('HargaPerkiraan', 18, 2)->default(0);
            $tabel->WaktuStandar();
            $tabel->index(['IdTenant', 'IdPermintaanPembelian', 'Urutan'], 'IdxPermintaanPembelianDetailIdTenantIdPermintaanUrutan');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('PermintaanPembelianDetail');
        Schema::dropIfExists('PermintaanPembelian');
    }
};
