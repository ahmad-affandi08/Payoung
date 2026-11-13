<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-299 tahap 1: nomor pengirim WhatsApp per outlet (ala Majoo). Tenant mendaftarkan nomor, memindai QR, dan memilih
 * fungsi nomor itu. Tidak ada token atau nama templat yang diisi tenant: sesi perangkat dibuat dan dipegang platform
 * lewat colokan `GerbangSesiWhatsapp`. `TokenPerangkat` terenkripsi dan disembunyikan dari serialisasi.
 * `IdOutlet` kosong berarti nomor tingkat usaha (dipakai semua outlet yang tidak punya nomor sendiri).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('NomorPengirimWhatsapp', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->char('Uuid', 26);
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkNomorPengirimWhatsappIdTenant')->restrictOnDelete();
            $tabel->foreignId('IdOutlet')->nullable()->constrained('Outlet', 'Id', 'FkNomorPengirimWhatsappIdOutlet')->restrictOnDelete();
            $tabel->string('Label', 60);
            $tabel->string('Fungsi', 30)->default('Umum');
            $tabel->string('NomorTelepon', 20);
            $tabel->string('Status', 20)->default('MenungguPindai');
            $tabel->boolean('Aktif')->default(false);
            $tabel->string('Penyedia', 30);
            $tabel->text('TokenPerangkat')->nullable();
            $tabel->string('PesanStatus', 300)->nullable();
            $tabel->timestamp('TerhubungPada')->nullable();
            $tabel->timestamp('DiperiksaPada')->nullable();
            $tabel->timestamp('PeringatanTerputusPada')->nullable();
            $tabel->WaktuStandar();
            $tabel->unique(['IdTenant', 'Uuid'], 'UniqNomorPengirimWhatsappIdTenantUuid');
            $tabel->unique(['IdTenant', 'NomorTelepon'], 'UniqNomorPengirimWhatsappIdTenantNomor');
            $tabel->index(['IdTenant', 'IdOutlet', 'Aktif'], 'IdxNomorPengirimWhatsappOutletAktif');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('NomorPengirimWhatsapp');
    }
};
