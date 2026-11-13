<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-299 tahap 2 dan 3: sakelar pesan otomatis WhatsApp per jenis (`PengaturanPesanWhatsapp`, satu baris per tenant ×
 * jenis, tanpa baris = nilai bawaan jenis itu), riwayat pesan otomatis (`RiwayatPesanWhatsapp`, nomor tujuan hanya
 * ditampilkan tersamar), dan penerima laporan karyawan (`PenerimaLaporanWhatsapp`, maksimal 6 per outlet).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('PengaturanPesanWhatsapp', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkPengaturanPesanWhatsappIdTenant')->restrictOnDelete();
            $tabel->string('Jenis', 40);
            $tabel->boolean('Aktif');
            $tabel->WaktuStandar();
            $tabel->unique(['IdTenant', 'Jenis'], 'UniqPengaturanPesanWhatsappIdTenantJenis');
        });

        Schema::create('RiwayatPesanWhatsapp', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkRiwayatPesanWhatsappIdTenant')->restrictOnDelete();
            $tabel->foreignId('IdOutlet')->nullable()->constrained('Outlet', 'Id', 'FkRiwayatPesanWhatsappIdOutlet')->restrictOnDelete();
            $tabel->string('Jenis', 40);
            $tabel->string('Tujuan', 30);
            $tabel->string('Status', 20);
            $tabel->string('PesanGalat', 300)->nullable();
            $tabel->WaktuStandar();
            $tabel->index(['IdTenant', 'DibuatPada'], 'IdxRiwayatPesanWhatsappTenantWaktu');
        });

        Schema::create('PenerimaLaporanWhatsapp', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->char('Uuid', 26);
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkPenerimaLaporanWhatsappIdTenant')->restrictOnDelete();
            $tabel->foreignId('IdOutlet')->constrained('Outlet', 'Id', 'FkPenerimaLaporanWhatsappIdOutlet')->restrictOnDelete();
            $tabel->string('Nama', 60);
            $tabel->string('NomorTelepon', 20);
            $tabel->WaktuStandar();
            $tabel->unique(['IdTenant', 'Uuid'], 'UniqPenerimaLaporanWhatsappIdTenantUuid');
            $tabel->unique(['IdOutlet', 'NomorTelepon'], 'UniqPenerimaLaporanWhatsappOutletNomor');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('PenerimaLaporanWhatsapp');
        Schema::dropIfExists('RiwayatPesanWhatsapp');
        Schema::dropIfExists('PengaturanPesanWhatsapp');
    }
};
