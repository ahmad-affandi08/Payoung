<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F7 (CRM-08): umpan balik pelanggan atas satu transaksi dari struk digital `/s/{kodeStruk}`: nilai 1–5 dan komentar
 * opsional. Satu umpan balik per penjualan (kiriman pertama yang berlaku, tidak bisa ditimpa), tanpa data pribadi
 * pengirim (pelanggan hanya terhubung bila transaksinya sudah memiliki pelanggan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('UmpanBalikTransaksi', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkUmpanBalikTransaksiIdTenant')->restrictOnDelete();
            $tabel->char('Uuid', 26)->unique('UniqUmpanBalikTransaksiUuid');
            $tabel->foreignId('IdPenjualan')->constrained('Penjualan', 'Id', 'FkUmpanBalikTransaksiIdPenjualan')->restrictOnDelete();
            $tabel->foreignId('IdOutlet')->constrained('Outlet', 'Id', 'FkUmpanBalikTransaksiIdOutlet')->restrictOnDelete();
            $tabel->unsignedBigInteger('IdPelanggan')->nullable();
            $tabel->unsignedTinyInteger('Nilai');
            $tabel->string('Komentar', 500)->nullable();
            $tabel->timestamp('DibuatPada')->useCurrent();
            $tabel->timestamp('DiubahPada')->nullable();
            $tabel->unique(['IdTenant', 'IdPenjualan'], 'UniqUmpanBalikTransaksiIdTenantIdPenjualan');
            $tabel->index(['IdTenant', 'IdOutlet', 'DibuatPada'], 'IdxUmpanBalikTransaksiIdTenantIdOutletDibuatPada');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('UmpanBalikTransaksi');
    }
};
