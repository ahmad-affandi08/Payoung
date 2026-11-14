<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-300 (tahap 4): saldo WhatsApp prabayar tenant. Saldo adalah titipan tenant kepada Payoung untuk pembayaran pesan
 * broadcast WhatsApp, bukan akun pembukuan tenant. `SaldoWhatsapp` (satu baris per tenant) dan `KreditBroadcastOutlet`
 * (anggaran per outlet hasil alokasi) hanya cache yang dikunci saat mutasi; kebenarannya `MutasiSaldoWhatsapp`
 * (append-only). `TopUpSaldoWhatsapp` mencatat pembayaran top-up lewat gerbang billing platform. `KodeAlokasiKredit`
 * menyimpan kode OTP alokasi (hanya hash, berlaku 10 menit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('SaldoWhatsapp', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkSaldoWhatsappIdTenant')->restrictOnDelete();
            $tabel->decimal('Saldo', 15, 2)->default(0);
            $tabel->WaktuStandar();
            $tabel->unique('IdTenant', 'UniqSaldoWhatsappIdTenant');
        });

        Schema::create('KreditBroadcastOutlet', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkKreditBroadcastOutletIdTenant')->restrictOnDelete();
            $tabel->foreignId('IdOutlet')->constrained('Outlet', 'Id', 'FkKreditBroadcastOutletIdOutlet')->restrictOnDelete();
            $tabel->decimal('Saldo', 15, 2)->default(0);
            $tabel->WaktuStandar();
            $tabel->unique(['IdTenant', 'IdOutlet'], 'UniqKreditBroadcastOutletTenantOutlet');
        });

        Schema::create('MutasiSaldoWhatsapp', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkMutasiSaldoWhatsappIdTenant')->restrictOnDelete();
            $tabel->foreignId('IdOutlet')->nullable()->constrained('Outlet', 'Id', 'FkMutasiSaldoWhatsappIdOutlet')->restrictOnDelete();
            $tabel->string('Jenis', 30);
            $tabel->decimal('Jumlah', 15, 2);
            $tabel->decimal('SaldoSetelah', 15, 2);
            $tabel->string('Referensi', 60)->nullable();
            $tabel->string('Keterangan', 255)->nullable();
            $tabel->foreignId('IdPengguna')->nullable()->constrained('Pengguna', 'Id', 'FkMutasiSaldoWhatsappIdPengguna')->nullOnDelete();
            $tabel->WaktuStandar();
            $tabel->index(['IdTenant', 'DibuatPada'], 'IdxMutasiSaldoWhatsappTenantWaktu');
            $tabel->unique(['IdTenant', 'Jenis', 'Referensi'], 'UniqMutasiSaldoWhatsappJenisReferensi');
        });

        Schema::create('TopUpSaldoWhatsapp', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->char('Uuid', 26);
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkTopUpSaldoWhatsappIdTenant')->restrictOnDelete();
            $tabel->decimal('Jumlah', 15, 2);
            $tabel->string('Status', 20)->default('Menunggu');
            $tabel->string('RefGateway', 80)->nullable();
            $tabel->text('UrlBayar')->nullable();
            $tabel->foreignId('IdPengguna')->nullable()->constrained('Pengguna', 'Id', 'FkTopUpSaldoWhatsappIdPengguna')->nullOnDelete();
            $tabel->timestamp('DibayarPada')->nullable();
            $tabel->WaktuStandar();
            $tabel->unique(['IdTenant', 'Uuid'], 'UniqTopUpSaldoWhatsappIdTenantUuid');
            $tabel->unique('RefGateway', 'UniqTopUpSaldoWhatsappRefGateway');
            $tabel->index(['Status', 'DibuatPada'], 'IdxTopUpSaldoWhatsappStatusWaktu');
        });

        Schema::create('KodeAlokasiKredit', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkKodeAlokasiKreditIdTenant')->restrictOnDelete();
            $tabel->foreignId('IdPengguna')->constrained('Pengguna', 'Id', 'FkKodeAlokasiKreditIdPengguna')->cascadeOnDelete();
            $tabel->foreignId('IdOutlet')->constrained('Outlet', 'Id', 'FkKodeAlokasiKreditIdOutlet')->restrictOnDelete();
            $tabel->decimal('Jumlah', 15, 2);
            $tabel->string('KodeHash', 64);
            $tabel->unsignedTinyInteger('Percobaan')->default(0);
            $tabel->timestamp('KedaluwarsaPada');
            $tabel->timestamp('DigunakanPada')->nullable();
            $tabel->WaktuStandar();
            $tabel->index(['IdTenant', 'IdPengguna'], 'IdxKodeAlokasiKreditTenantPengguna');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('KodeAlokasiKredit');
        Schema::dropIfExists('TopUpSaldoWhatsapp');
        Schema::dropIfExists('MutasiSaldoWhatsapp');
        Schema::dropIfExists('KreditBroadcastOutlet');
        Schema::dropIfExists('SaldoWhatsapp');
    }
};
