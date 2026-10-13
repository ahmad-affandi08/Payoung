<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PLT-12 / P-06 Pusat Bantuan: artikel bantuan `payoung.id/pusat-bantuan` (data platform, bukan tenant) yang juga muncul
 * sebagai bantuan kontekstual di back-office. `Konteks` = daftar awalan jalur back-office (misal `/kelola/produk`) tempat
 * artikel relevan; `UrlVideo` = tautan video penjelasan (hanya tautan, tanpa sematan). Isi memakai format teks ringan
 * situs (tanpa HTML), seperti blog.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ArtikelBantuan', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->UuidPublik();
            $tabel->string('Slug', 120)->unique('UqArtikelBantuanSlug');
            $tabel->string('Judul', 150);
            $tabel->string('Ringkasan', 300)->nullable();
            $tabel->mediumText('Isi');
            $tabel->string('Kategori', 60)->nullable();
            $tabel->string('UrlVideo', 300)->nullable();
            $tabel->json('Konteks');
            $tabel->unsignedSmallInteger('Urutan')->default(0);
            $tabel->string('Status', 20);
            $tabel->timestamp('DiterbitkanPada')->nullable();
            $tabel->foreignId('IdPenggunaPengelolaPengubah')->nullable()->constrained('PenggunaPengelola', 'Id', 'FkArtikelBantuanIdPengubah')->nullOnDelete();
            $tabel->WaktuStandar();
            $tabel->index(['Status', 'Kategori', 'Urutan'], 'IdxArtikelBantuanStatusKategoriUrutan');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ArtikelBantuan');
    }
};
