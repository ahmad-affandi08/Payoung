<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit E2: pemakaian penyimpanan berkas per tenant (`BatasPenyimpananMb` paket). Satu baris per tenant; `Byte` diukur
 * ulang tiap malam dari folder berkas tenant di semua disk dan bertambah saat unggahan yang dibatasi paket berhasil,
 * sehingga penjaga unggahan tidak perlu menelusuri disk setiap permintaan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('PemakaianPenyimpanan', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkPemakaianPenyimpananIdTenant')->restrictOnDelete();
            $tabel->unsignedBigInteger('Byte')->default(0);
            $tabel->dateTime('DiukurPada')->nullable();
            $tabel->WaktuStandar();
            $tabel->unique('IdTenant', 'UniqPemakaianPenyimpananIdTenant');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('PemakaianPenyimpanan');
    }
};
