<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P-07 PGL-09 skor kesehatan tenant: `SkorKesehatanTenant` = hasil hitung harian per tenant (skor 0–100, kategori, rincian
 * faktor); `TugasTenant` = tugas otomatis untuk tim (hubungi untuk onboarding, tenant berisiko). Data catatan platform
 * tentang tenant (tanpa scope `MilikTenant`), dibaca dan ditulis hanya oleh Platform Pengelola. Satu tugas terbuka per
 * tenant per jenis (`KunciTerbuka` = 1 selama terbuka, NULL setelah selesai, sehingga unik hanya untuk yang terbuka).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('SkorKesehatanTenant', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkSkorKesehatanTenantIdTenant')->restrictOnDelete();
            $tabel->date('Tanggal');
            $tabel->unsignedTinyInteger('Skor');
            $tabel->string('Kategori', 20);
            $tabel->json('Faktor');
            $tabel->WaktuStandar();
            $tabel->unique(['IdTenant', 'Tanggal'], 'UqSkorKesehatanTenantIdTenantTanggal');
            $tabel->index(['Tanggal', 'Kategori'], 'IdxSkorKesehatanTenantTanggalKategori');
        });

        Schema::create('TugasTenant', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->UuidPublik();
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkTugasTenantIdTenant')->restrictOnDelete();
            $tabel->string('Jenis', 25);
            $tabel->string('Status', 10);
            $tabel->unsignedTinyInteger('KunciTerbuka')->nullable();
            $tabel->string('Ringkasan', 255);
            $tabel->timestamp('SelesaiPada')->nullable();
            $tabel->string('CaraSelesai', 10)->nullable();
            $tabel->foreignId('SelesaiOleh')->nullable()->constrained('PenggunaPengelola', 'Id', 'FkTugasTenantSelesaiOleh')->restrictOnDelete();
            $tabel->string('CatatanSelesai', 500)->nullable();
            $tabel->WaktuStandar();
            $tabel->unique(['IdTenant', 'Jenis', 'KunciTerbuka'], 'UqTugasTenantIdTenantJenisTerbuka');
            $tabel->index(['Status', 'Jenis'], 'IdxTugasTenantStatusJenis');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('TugasTenant');
        Schema::dropIfExists('SkorKesehatanTenant');
    }
};
