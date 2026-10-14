<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PGL-15 (PRD v6.28, D-149): tiket masuk dari email dan WhatsApp. `PesanMasukDukungan` mencatat tiap pesan masuk yang
 * sudah diproses (kanal + id pesan dari penyedia unik) sehingga pengiriman ulang penyedia tidak membuat tiket ganda, dan
 * menyimpan hasilnya (tiket baru, balasan, atau ditolak dengan kodenya). Tabel platform tanpa `IdTenant` dan tanpa isi
 * pesan, pengirim, atau lampiran: isi pesan hanya hidup di tiketnya. Hanya menambah tabel (expand).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('PesanMasukDukungan')) {
            return;
        }

        Schema::create('PesanMasukDukungan', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->string('Kanal', 20);
            // Id pesan dari penyedia (Message-ID email atau id pesan WhatsApp), dipotong 190 karakter.
            $tabel->string('IdPesan', 190);
            // TiketBaru, Balasan, atau Ditolak.
            $tabel->string('Hasil', 20);
            $tabel->string('KodeTolak', 60)->nullable();
            // Nomor tiket yang dibuat/dibalas (`TKT-2026-000123`): cukup untuk menjawab pengiriman ulang tanpa membaca
            // tiketnya, dan tanpa FK karena tabel ini platform sedangkan tiketnya milik tenant.
            $tabel->string('NomorTiket', 30)->nullable();
            $tabel->WaktuStandar();
            $tabel->unique(['Kanal', 'IdPesan'], 'UniqPesanMasukDukunganKanalPesan');
            $tabel->index('DibuatPada', 'IdxPesanMasukDukunganDibuatPada');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('PesanMasukDukungan');
    }
};
