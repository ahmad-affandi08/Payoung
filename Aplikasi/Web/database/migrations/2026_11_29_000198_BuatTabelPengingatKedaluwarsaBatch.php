<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F23 (INV-09, OWN-03): penanda bahwa batch tertentu sudah diumumkan lewat push pada satu tingkat kedaluwarsa
 * (`H30` = dalam 30 hari, `H7` = dalam 7 hari, `Lewat` = sudah lewat). Satu batch diumumkan satu kali per tingkat per
 * tanggal kedaluwarsa, bukan tiap hari, supaya pemilik tidak dibanjiri pemberitahuan yang sama. `KunciBatch` =
 * `{uuid produk}-{uuid lokasi stok}-{nomor batch}` seperti kunci di Kotak Tindakan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('PengingatKedaluwarsaBatch', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkPengingatKedaluwarsaBatchIdTenant')->restrictOnDelete();
            $tabel->string('KunciBatch', 150);
            $tabel->string('Tingkat', 6);
            $tabel->date('TanggalKedaluwarsa');
            $tabel->timestamp('DibuatPada');
            $tabel->unique(['IdTenant', 'KunciBatch', 'Tingkat', 'TanggalKedaluwarsa'], 'UniqPengingatKedaluwarsaBatchKunciTingkatTanggal');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('PengingatKedaluwarsaBatch');
    }
};
