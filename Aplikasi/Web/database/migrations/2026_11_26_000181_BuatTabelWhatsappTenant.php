<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-108: WhatsApp milik tenant (nomor dan akun penyedia sendiri), satu per tenant. Pesan lewat nomor ini tidak memakai
 * kuota pesan paket. Pola sama dengan `GerbangPembayaranTenant`: `Kredensial` terenkripsi (APP_KEY) dengan
 * `PetunjukKredensial` 4 karakter terakhir, aktif hanya setelah uji koneksi berhasil, dan perubahan isian
 * mengembalikan status ke `BelumDiuji` serta menonaktifkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('WhatsappTenant', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->char('Uuid', 26);
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkWhatsappTenantIdTenant')->restrictOnDelete();
            $tabel->string('Penyedia', 30);
            $tabel->json('Pengaturan');
            $tabel->text('Kredensial');
            $tabel->json('PetunjukKredensial');
            $tabel->string('StatusUji', 20)->default('BelumDiuji');
            $tabel->string('PesanUji', 300)->nullable();
            $tabel->timestamp('DiujiPada')->nullable();
            $tabel->boolean('Aktif')->default(false);
            $tabel->WaktuStandar();
            $tabel->unique('IdTenant', 'UniqWhatsappTenantIdTenant');
            $tabel->unique(['IdTenant', 'Uuid'], 'UniqWhatsappTenantIdTenantUuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('WhatsappTenant');
    }
};
