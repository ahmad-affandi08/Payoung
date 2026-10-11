<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permintaan stok antar lokasi (F-05b, D-105): outlet yang butuh barang mengajukan permintaan ke lokasi pengirim
 * (mis. gudang utama). Permintaan sendiri **bukan peristiwa stok maupun akuntansi**: stok dan jurnal baru bergerak saat
 * transfer yang menjawabnya dikirim (`TransferStok.IdPermintaanStok`). Penamaan lokasi mengikuti transfer: lokasi
 * *tujuan* = yang meminta dan akan menerima, lokasi *asal* = yang diminta mengirim. Status Diajukan → DiprosesSebagian
 * → Selesai, atau Ditutup / Ditolak / Dibatalkan. `JumlahDikirim` per baris bertambah saat transfer berkait dikirim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('PermintaanStok', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->UuidPublik();
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkPermintaanStokIdTenant')->restrictOnDelete();
            $tabel->string('Nomor', 60);
            $tabel->foreignId('IdGudangAsal')->constrained('Gudang', 'Id', 'FkPermintaanStokIdGudangAsal')->restrictOnDelete();
            $tabel->foreignId('IdOutletAsal')->nullable()->constrained('Outlet', 'Id', 'FkPermintaanStokIdOutletAsal')->restrictOnDelete();
            $tabel->foreignId('IdGudangTujuan')->constrained('Gudang', 'Id', 'FkPermintaanStokIdGudangTujuan')->restrictOnDelete();
            $tabel->foreignId('IdOutletTujuan')->nullable()->constrained('Outlet', 'Id', 'FkPermintaanStokIdOutletTujuan')->restrictOnDelete();
            $tabel->date('Tanggal');
            $tabel->string('Status', 20);
            $tabel->string('Catatan', 500)->nullable();
            $tabel->unsignedInteger('JumlahBaris')->default(0);
            $tabel->string('Alasan', 255)->nullable();
            $tabel->foreignId('DibuatOleh')->nullable()->constrained('Pengguna', 'Id', 'FkPermintaanStokDibuatOleh')->restrictOnDelete();
            $tabel->foreignId('DitutupOleh')->nullable()->constrained('Pengguna', 'Id', 'FkPermintaanStokDitutupOleh')->restrictOnDelete();
            $tabel->timestamp('DitutupPada')->nullable();
            $tabel->WaktuStandar();
            $tabel->unique(['IdTenant', 'Nomor'], 'UniqPermintaanStokIdTenantNomor');
            $tabel->index(['IdTenant', 'Status', 'Tanggal'], 'IdxPermintaanStokIdTenantStatusTanggal');
            $tabel->index(['IdTenant', 'IdOutletAsal', 'Status'], 'IdxPermintaanStokIdTenantIdOutletAsalStatus');
            $tabel->index(['IdTenant', 'IdOutletTujuan', 'Status'], 'IdxPermintaanStokIdTenantIdOutletTujuanStatus');
        });

        Schema::create('PermintaanStokDetail', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkPermintaanStokDetailIdTenant')->restrictOnDelete();
            $tabel->foreignId('IdPermintaanStok')->constrained('PermintaanStok', 'Id', 'FkPermintaanStokDetailIdPermintaanStok')->restrictOnDelete();
            $tabel->unsignedInteger('Urutan');
            $tabel->foreignId('IdProduk')->constrained('Produk', 'Id', 'FkPermintaanStokDetailIdProduk')->restrictOnDelete();
            $tabel->string('NamaProduk', 150);
            $tabel->string('Sku', 64)->nullable();
            $tabel->decimal('JumlahDiminta', 18, 4);
            $tabel->decimal('JumlahDikirim', 18, 4)->default(0);
            $tabel->WaktuStandar();
            $tabel->unique(['IdPermintaanStok', 'IdProduk'], 'UniqPermintaanStokDetailIdPermintaanStokIdProduk');
            $tabel->index(['IdTenant', 'IdPermintaanStok', 'Urutan'], 'IdxPermintaanStokDetailIdTenantIdPermintaanStokUrutan');
            $tabel->index(['IdTenant', 'IdProduk'], 'IdxPermintaanStokDetailIdTenantIdProduk');
        });

        Schema::table('TransferStok', function (Blueprint $tabel): void {
            $tabel->foreignId('IdPermintaanStok')->nullable()->after('IdTenant')
                ->constrained('PermintaanStok', 'Id', 'FkTransferStokIdPermintaanStok')->restrictOnDelete();
            $tabel->index(['IdTenant', 'IdPermintaanStok'], 'IdxTransferStokIdTenantIdPermintaanStok');
        });
    }

    public function down(): void
    {
        Schema::table('TransferStok', function (Blueprint $tabel): void {
            $tabel->dropForeign('FkTransferStokIdPermintaanStok');
            $tabel->dropIndex('IdxTransferStokIdTenantIdPermintaanStok');
            $tabel->dropColumn('IdPermintaanStok');
        });
        Schema::dropIfExists('PermintaanStokDetail');
        Schema::dropIfExists('PermintaanStok');
    }
};
