<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit E5 (UU PDP, hak atas data): ekspor data lengkap usaha yang diminta Pemilik. Berkas ZIP berisi satu CSV per tabel
 * data usaha, dibuat di antrean, disimpan di disk privat, dan kedaluwarsa otomatis. Baris ini hanya jejak permintaan
 * dan status; isinya ada di berkas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('EksporDataTenant', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->UuidPublik();
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkEksporDataTenantIdTenant')->restrictOnDelete();
            $tabel->string('Status', 20);
            $tabel->foreignId('IdPenggunaPeminta')->nullable()->constrained('Pengguna', 'Id', 'FkEksporDataTenantIdPeminta')->restrictOnDelete();
            $tabel->string('PathBerkas', 300)->nullable();
            $tabel->unsignedBigInteger('UkuranByte')->default(0);
            $tabel->unsignedInteger('JumlahTabel')->default(0);
            $tabel->unsignedBigInteger('JumlahBaris')->default(0);
            $tabel->dateTime('SelesaiPada')->nullable();
            $tabel->dateTime('KedaluwarsaPada')->nullable();
            $tabel->dateTime('DiunduhPada')->nullable();
            $tabel->string('Galat', 300)->nullable();
            $tabel->WaktuStandar();
            $tabel->index(['IdTenant', 'Status'], 'IdxEksporDataTenantStatus');
            $tabel->index(['Status', 'KedaluwarsaPada'], 'IdxEksporDataTenantKedaluwarsa');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('EksporDataTenant');
    }
};
