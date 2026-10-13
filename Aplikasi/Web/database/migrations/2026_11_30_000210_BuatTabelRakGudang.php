<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-05c INV-07 stok opname per rak: `RakGudang` = rak/zona dalam satu lokasi stok (`Gudang`), `ProdukRak` = produk mana
 * yang disimpan di rak mana (satu produk satu rak per lokasi stok). `StokOpname.IdRak` membatasi opname ke produk rak itu.
 * `KunciAktif` dibangun ulang memuat rak (`gudang:kategori:rak`, 0 = tidak dibatasi) supaya dua rak berbeda di lokasi yang
 * sama bisa dihitung berbarengan oleh penghitung berbeda; benturan lintas cakupan (seluruh produk, kategori, rak) diperiksa
 * di `MulaiStokOpname`. Rak tidak menyimpan saldo: stok tetap per lokasi, rak hanya cakupan hitung.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('RakGudang', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->UuidPublik();
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkRakGudangIdTenant')->restrictOnDelete();
            $tabel->foreignId('IdGudang')->constrained('Gudang', 'Id', 'FkRakGudangIdGudang')->restrictOnDelete();
            $tabel->string('Kode', 30);
            $tabel->string('Nama', 100);
            $tabel->unsignedSmallInteger('Urutan')->default(0);
            $tabel->boolean('Aktif')->default(true);
            $tabel->WaktuStandar();
            $tabel->unique(['IdTenant', 'IdGudang', 'Kode'], 'UqRakGudangIdTenantIdGudangKode');
            $tabel->index(['IdTenant', 'IdGudang', 'Aktif'], 'IdxRakGudangIdTenantIdGudangAktif');
        });

        Schema::create('ProdukRak', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkProdukRakIdTenant')->restrictOnDelete();
            $tabel->foreignId('IdRak')->constrained('RakGudang', 'Id', 'FkProdukRakIdRak')->restrictOnDelete();
            $tabel->foreignId('IdGudang')->constrained('Gudang', 'Id', 'FkProdukRakIdGudang')->restrictOnDelete();
            $tabel->foreignId('IdProduk')->constrained('Produk', 'Id', 'FkProdukRakIdProduk')->restrictOnDelete();
            $tabel->WaktuStandar();
            $tabel->unique(['IdTenant', 'IdGudang', 'IdProduk'], 'UqProdukRakIdTenantIdGudangIdProduk');
            $tabel->index(['IdTenant', 'IdRak'], 'IdxProdukRakIdTenantIdRak');
        });

        Schema::table('StokOpname', function (Blueprint $tabel): void {
            $tabel->dropUnique('UniqStokOpnameIdTenantKunciAktif');
        });
        Schema::table('StokOpname', function (Blueprint $tabel): void {
            $tabel->dropColumn('KunciAktif');
        });
        Schema::table('StokOpname', function (Blueprint $tabel): void {
            $tabel->foreignId('IdRak')->nullable()->after('NamaKategori')->constrained('RakGudang', 'Id', 'FkStokOpnameIdRak')->restrictOnDelete();
            $tabel->string('NamaRak', 130)->nullable()->after('IdRak');
            $tabel->string('KunciAktif', 60)->nullable()->storedAs("CASE WHEN `Status` IN ('Berlangsung', 'Ditinjau') THEN CONCAT(`IdGudang`, ':', IFNULL(`IdKategori`, 0), ':', IFNULL(`IdRak`, 0)) ELSE NULL END");
        });
        Schema::table('StokOpname', function (Blueprint $tabel): void {
            $tabel->unique(['IdTenant', 'KunciAktif'], 'UniqStokOpnameIdTenantKunciAktif');
        });
    }

    public function down(): void
    {
        Schema::table('StokOpname', function (Blueprint $tabel): void {
            $tabel->dropUnique('UniqStokOpnameIdTenantKunciAktif');
        });
        Schema::table('StokOpname', function (Blueprint $tabel): void {
            $tabel->dropColumn('KunciAktif');
            $tabel->dropForeign('FkStokOpnameIdRak');
        });
        Schema::table('StokOpname', function (Blueprint $tabel): void {
            $tabel->dropColumn(['IdRak', 'NamaRak']);
            $tabel->string('KunciAktif', 50)->nullable()->storedAs("CASE WHEN `Status` IN ('Berlangsung', 'Ditinjau') THEN CONCAT(`IdGudang`, ':', IFNULL(`IdKategori`, 0)) ELSE NULL END");
        });
        Schema::table('StokOpname', function (Blueprint $tabel): void {
            $tabel->unique(['IdTenant', 'KunciAktif'], 'UniqStokOpnameIdTenantKunciAktif');
        });
        Schema::dropIfExists('ProdukRak');
        Schema::dropIfExists('RakGudang');
    }
};
