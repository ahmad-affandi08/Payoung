<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-266 harga jual otomatis mengikuti harga beli terakhir (F-03, F-04): `PengaturanHargaOtomatis` (satu baris per
 * tenant: saklar utama dan batas aman), `AturanHargaOtomatis` (untung persen per cakupan Semua/Kategori/Produk), dan
 * `UsulanHargaOtomatis` (perubahan yang melewati batas aman, menunggu keputusan pemilik).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('PengaturanHargaOtomatis', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkPengaturanHargaOtomatisIdTenant')->restrictOnDelete();
            $tabel->boolean('Aktif')->default(false);
            $tabel->decimal('BatasPerubahanPersen', 5, 2)->default(20);
            $tabel->foreignId('DiubahOleh')->nullable()->constrained('Pengguna', 'Id', 'FkPengaturanHargaOtomatisDiubahOleh')->restrictOnDelete();
            $tabel->WaktuStandar();
            $tabel->unique(['IdTenant'], 'UniqPengaturanHargaOtomatisIdTenant');
        });

        Schema::create('AturanHargaOtomatis', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->char('Uuid', 26)->unique('UniqAturanHargaOtomatisUuid');
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkAturanHargaOtomatisIdTenant')->restrictOnDelete();
            $tabel->string('Cakupan', 10);
            $tabel->foreignId('IdKategori')->nullable()->constrained('Kategori', 'Id', 'FkAturanHargaOtomatisIdKategori')->restrictOnDelete();
            $tabel->foreignId('IdProduk')->nullable()->constrained('Produk', 'Id', 'FkAturanHargaOtomatisIdProduk')->restrictOnDelete();
            $tabel->decimal('PersenUntung', 9, 4);
            $tabel->unsignedInteger('Pembulatan')->default(0);
            $tabel->string('Arah', 10)->default('NaikTurun');
            $tabel->boolean('Aktif')->default(true);
            $tabel->foreignId('DiubahOleh')->nullable()->constrained('Pengguna', 'Id', 'FkAturanHargaOtomatisDiubahOleh')->restrictOnDelete();
            $tabel->WaktuStandar();
            $tabel->index(['IdTenant', 'Cakupan'], 'IdxAturanHargaOtomatisIdTenantCakupan');
            $tabel->index(['IdTenant', 'IdKategori'], 'IdxAturanHargaOtomatisIdTenantIdKategori');
            $tabel->index(['IdTenant', 'IdProduk'], 'IdxAturanHargaOtomatisIdTenantIdProduk');
        });

        Schema::create('UsulanHargaOtomatis', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->char('Uuid', 26)->unique('UniqUsulanHargaOtomatisUuid');
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkUsulanHargaOtomatisIdTenant')->restrictOnDelete();
            $tabel->foreignId('IdProduk')->constrained('Produk', 'Id', 'FkUsulanHargaOtomatisIdProduk')->restrictOnDelete();
            $tabel->foreignId('IdProdukSatuan')->constrained('ProdukSatuan', 'Id', 'FkUsulanHargaOtomatisIdProdukSatuan')->restrictOnDelete();
            $tabel->foreignId('IdPenerimaanBarang')->nullable()->constrained('PenerimaanBarang', 'Id', 'FkUsulanHargaOtomatisIdPenerimaanBarang')->nullOnDelete();
            $tabel->decimal('Modal', 19, 6);
            $tabel->decimal('PersenUntung', 9, 4);
            $tabel->decimal('HargaLama', 18, 2);
            $tabel->decimal('HargaBaru', 18, 2);
            $tabel->decimal('PerubahanPersen', 9, 2);
            $tabel->string('Status', 12)->default('Menunggu');
            $tabel->foreignId('DiputuskanOleh')->nullable()->constrained('Pengguna', 'Id', 'FkUsulanHargaOtomatisDiputuskanOleh')->restrictOnDelete();
            $tabel->timestamp('DiputuskanPada')->nullable();
            $tabel->WaktuStandar();
            $tabel->index(['IdTenant', 'Status'], 'IdxUsulanHargaOtomatisIdTenantStatus');
            $tabel->index(['IdTenant', 'IdProduk', 'Status'], 'IdxUsulanHargaOtomatisIdTenantIdProdukStatus');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('UsulanHargaOtomatis');
        Schema::dropIfExists('AturanHargaOtomatis');
        Schema::dropIfExists('PengaturanHargaOtomatis');
    }
};
