<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-21 (D-166): kirim invoice atau pengingat tagihannya ke pelanggan lewat WhatsApp. Satu baris per pengiriman. Nomor
 * tujuan terenkripsi dan tidak pernah masuk log. `Tahap` hanya terisi untuk pengingat otomatis (H-3, H0, H+3 ...) dan
 * dikunci unik per invoice supaya satu tahap tidak terkirim dua kali.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('PengirimanInvoice', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->UuidPublik();
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkPengirimanInvoiceIdTenant')->restrictOnDelete();
            $tabel->foreignId('IdInvoice')->constrained('Invoice', 'Id', 'FkPengirimanInvoiceIdInvoice')->restrictOnDelete();
            $tabel->string('Jenis', 20);
            $tabel->string('Tahap', 20)->nullable();
            $tabel->text('Tujuan');
            $tabel->string('Status', 20);
            $tabel->string('Penyedia', 30)->nullable();
            $tabel->string('IdPesanPenyedia', 191)->nullable();
            $tabel->string('PesanGalat', 300)->nullable();
            $tabel->unsignedTinyInteger('Percobaan')->default(0);
            $tabel->timestamp('TerkirimPada')->nullable();
            $tabel->foreignId('DikirimOleh')->nullable()->constrained('Pengguna', 'Id', 'FkPengirimanInvoiceDikirimOleh')->restrictOnDelete();
            $tabel->WaktuStandar();
            $tabel->index(['IdTenant', 'IdInvoice'], 'IdxPengirimanInvoiceIdTenantIdInvoice');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('PengirimanInvoice');
    }
};
