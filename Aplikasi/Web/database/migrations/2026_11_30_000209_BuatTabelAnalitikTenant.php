<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P-07 PGL-24 analitik platform: `AnalitikTenant` = satu baris ringkas per tenant (sektor, tonggak funnel, fitur yang
 * dipakai) dan `AktivitasTenantBulanan` = jumlah transaksi dan hari aktif per tenant per bulan (dasar retensi kohort).
 * Keduanya catatan platform tentang tenant (tanpa scope `MilikTenant`), diisi `PerbaruiAnalitikTenant`, dan hanya dibaca
 * sebagai agregat di konsol: tidak ada nama atau data pribadi tenant yang keluar dari laporan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('AnalitikTenant', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkAnalitikTenantIdTenant')->restrictOnDelete();
            $tabel->string('KodeSektor', 30)->nullable();
            $tabel->timestamp('PanduanSelesaiPada')->nullable();
            $tabel->timestamp('TransaksiPertamaPada')->nullable();
            $tabel->timestamp('BayarPertamaPada')->nullable();
            $tabel->json('FiturDipakai');
            $tabel->WaktuStandar();
            $tabel->unique('IdTenant', 'UqAnalitikTenantIdTenant');
            $tabel->index('KodeSektor', 'IdxAnalitikTenantKodeSektor');
        });

        Schema::create('AktivitasTenantBulanan', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkAktivitasTenantBulananIdTenant')->restrictOnDelete();
            $tabel->date('Bulan');
            $tabel->unsignedInteger('JumlahTransaksi');
            $tabel->unsignedTinyInteger('HariAktif');
            $tabel->WaktuStandar();
            $tabel->unique(['IdTenant', 'Bulan'], 'UqAktivitasTenantBulananIdTenantBulan');
            $tabel->index('Bulan', 'IdxAktivitasTenantBulananBulan');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('AktivitasTenantBulanan');
        Schema::dropIfExists('AnalitikTenant');
    }
};
