<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-12 pengiriman grosir (§9.7 "Pengiriman: armada, surat jalan, konfirmasi terima"). Surat jalan sampai sekarang berhenti
 * di "barang sudah keluar gudang"; pemilik tidak tahu apakah barangnya sudah sampai atau pembeli sudah menerimanya.
 *
 * - `Armada` = master kendaraan pengiriman (nomor polisi, pengemudi bawaan) yang dipilih saat barang berangkat.
 * - Kolom pengiriman di `SuratJalan` (status lacak terpisah dari status akuntansi `Status`, yang tidak boleh berubah
 *   setelah diposting): `StatusPengiriman` Disiapkan → DalamPerjalanan → Diterima | Gagal, armada, waktu berangkat dan
 *   diterima, catatan penerimaan. Surat jalan lama dianggap `Disiapkan` (belum ada catatan lapangan).
 *
 * Expand-only: kolom nullable/berbawaan, tidak ada yang berubah makna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Armada', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->UuidPublik();
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkArmadaIdTenant')->restrictOnDelete();
            $tabel->string('Nama', 100);
            $tabel->string('NomorKendaraan', 30);
            $tabel->string('NamaPengemudi', 100)->nullable();
            $tabel->string('Keterangan', 255)->nullable();
            $tabel->boolean('Aktif')->default(true);
            $tabel->WaktuStandar();
            $tabel->unique(['IdTenant', 'NomorKendaraan'], 'UqArmadaIdTenantNomorKendaraan');
            $tabel->index(['IdTenant', 'Aktif'], 'IdxArmadaIdTenantAktif');
        });

        Schema::table('SuratJalan', function (Blueprint $tabel): void {
            $tabel->string('StatusPengiriman', 20)->default('Disiapkan')->after('Catatan');
            $tabel->foreignId('IdArmada')->nullable()->after('StatusPengiriman')->constrained('Armada', 'Id', 'FkSuratJalanIdArmada')->restrictOnDelete();
            $tabel->timestamp('BerangkatPada')->nullable()->after('IdArmada');
            $tabel->timestamp('DiterimaPada')->nullable()->after('BerangkatPada');
            $tabel->string('CatatanPengiriman', 500)->nullable()->after('DiterimaPada');
            $tabel->index(['IdTenant', 'StatusPengiriman'], 'IdxSuratJalanIdTenantStatusPengiriman');
        });
    }

    public function down(): void
    {
        Schema::table('SuratJalan', function (Blueprint $tabel): void {
            $tabel->dropIndex('IdxSuratJalanIdTenantStatusPengiriman');
            $tabel->dropForeign('FkSuratJalanIdArmada');
            $tabel->dropColumn(['StatusPengiriman', 'IdArmada', 'BerangkatPada', 'DiterimaPada', 'CatatanPengiriman']);
        });
        Schema::dropIfExists('Armada');
    }
};
