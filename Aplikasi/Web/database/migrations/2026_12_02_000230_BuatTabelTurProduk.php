<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PLT-12: tur produk interaktif di back-office. Satu baris per (pengguna, tur) berisi hasilnya: `Selesai` (sampai
 * langkah terakhir) atau `Dilewati`. Baris ada = tur itu tidak ditawarkan otomatis lagi kepada pengguna tersebut di
 * perangkat mana pun. Versi tur ikut di kodenya (`dashboard.v1`), jadi tur yang diperbarui ditawarkan ulang dengan kode
 * baru tanpa mengubah baris lama. Hanya menambah tabel (expand).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('TurProduk', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->UuidPublik();
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkTurProdukIdTenant')->restrictOnDelete();
            $tabel->foreignId('IdPengguna')->constrained('Pengguna', 'Id', 'FkTurProdukIdPengguna')->restrictOnDelete();
            $tabel->string('Kode', 40);
            $tabel->string('Status', 12);
            $tabel->WaktuStandar();
            $tabel->unique(['IdTenant', 'IdPengguna', 'Kode'], 'UniqTurProdukPenggunaKode');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('TurProduk');
    }
};
