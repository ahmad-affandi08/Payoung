<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-108: meteran kuota pesan WhatsApp lewat nomor Payoung. Satu baris per tenant per bulan (`Periode` `YYYY-MM`,
 * bulan kalender WIB); `Jumlah` naik saat pesan hendak dikirim dan dikembalikan bila penyedia gagal, jadi hanya pesan
 * yang benar-benar terkirim yang memakai kuota. Pesan lewat nomor milik tenant sendiri tidak dihitung di tabel ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('PemakaianPesanWa', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkPemakaianPesanWaIdTenant')->restrictOnDelete();
            $tabel->char('Periode', 7);
            $tabel->unsignedInteger('Jumlah')->default(0);
            $tabel->WaktuStandar();
            $tabel->unique(['IdTenant', 'Periode'], 'UniqPemakaianPesanWaIdTenantPeriode');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('PemakaianPesanWa');
    }
};
