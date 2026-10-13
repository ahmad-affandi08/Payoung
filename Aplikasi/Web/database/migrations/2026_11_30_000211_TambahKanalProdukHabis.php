<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PRD-11 ketersediaan per outlet × kanal: `ProdukHabis.Kanal` (`Semua` | `Kasir` | `TokoOnline` | `SelfOrder`). Baris yang
 * sudah ada berarti habis di semua kanal, jadi bawaannya `Semua` dan perilaku lama tidak berubah. Keunikan menjadi per
 * tenant + outlet + produk + kanal (indeks baru dibuat sebelum yang lama dilepas). Habis efektif di satu kanal = baris
 * `Semua` atau baris kanal itu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ProdukHabis', function (Blueprint $tabel): void {
            $tabel->string('Kanal', 20)->default('Semua')->after('IdOutlet');
            $tabel->unique(['IdTenant', 'IdOutlet', 'IdProduk', 'Kanal'], 'UniqProdukHabisIdTenantIdOutletIdProdukKanal');
        });

        Schema::table('ProdukHabis', function (Blueprint $tabel): void {
            $tabel->dropUnique('UniqProdukHabisIdTenantIdOutletIdProduk');
        });
    }

    public function down(): void
    {
        // Baris per kanal tidak punya padanan di skema lama: hanya baris `Semua` yang dipertahankan.
        DB::table('ProdukHabis')->where('Kanal', '<>', 'Semua')->delete();

        Schema::table('ProdukHabis', function (Blueprint $tabel): void {
            $tabel->unique(['IdTenant', 'IdOutlet', 'IdProduk'], 'UniqProdukHabisIdTenantIdOutletIdProduk');
        });
        Schema::table('ProdukHabis', function (Blueprint $tabel): void {
            $tabel->dropUnique('UniqProdukHabisIdTenantIdOutletIdProdukKanal');
        });
        Schema::table('ProdukHabis', function (Blueprint $tabel): void {
            $tabel->dropColumn('Kanal');
        });
    }
};
