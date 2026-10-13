<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-04 (F-e): kirim pesanan pembelian ke pemasok lewat WhatsApp. Satu baris per pengiriman (boleh berkali-kali untuk satu
 * PO, dengan jeda). Nomor tujuan terenkripsi dan tidak pernah masuk log; dikirim tugas antrean setelah commit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('PengirimanPesananPembelian', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->UuidPublik();
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkPengirimanPesananPembelianIdTenant')->restrictOnDelete();
            $tabel->foreignId('IdPesananPembelian')->constrained('PesananPembelian', 'Id', 'FkPengirimanPesananPembelianIdPo')->restrictOnDelete();
            $tabel->text('Tujuan');
            $tabel->string('Status', 20);
            $tabel->string('Penyedia', 30)->nullable();
            $tabel->string('IdPesanPenyedia', 191)->nullable();
            $tabel->string('PesanGalat', 300)->nullable();
            $tabel->unsignedTinyInteger('Percobaan')->default(0);
            $tabel->timestamp('TerkirimPada')->nullable();
            $tabel->foreignId('DikirimOleh')->nullable()->constrained('Pengguna', 'Id', 'FkPengirimanPesananPembelianDikirimOleh')->restrictOnDelete();
            $tabel->WaktuStandar();
            $tabel->index(['IdTenant', 'IdPesananPembelian'], 'IdxPengirimanPesananPembelianIdTenantIdPo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('PengirimanPesananPembelian');
    }
};
