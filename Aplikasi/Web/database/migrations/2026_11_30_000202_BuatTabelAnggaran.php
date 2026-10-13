<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F8 (FIN-11): anggaran per akun pendapatan/HPP/beban, per bulan, per tahun, untuk seluruh usaha (`IdOutlet` kosong)
 * atau satu outlet. Anggaran adalah rencana, bukan dokumen transaksi: boleh diubah dan perubahannya diaudit. Satu angka
 * per (tahun, bulan, akun, cakupan); `KunciOutlet` (0 = seluruh usaha) membuat keunikan berlaku juga untuk cakupan
 * seluruh usaha, karena unique index MySQL tidak menganggap dua NULL sama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Anggaran', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkAnggaranIdTenant')->restrictOnDelete();
            $tabel->char('Uuid', 26)->unique('UniqAnggaranUuid');
            $tabel->unsignedSmallInteger('Tahun');
            $tabel->unsignedTinyInteger('Bulan');
            $tabel->foreignId('IdAkun')->constrained('Akun', 'Id', 'FkAnggaranIdAkun')->restrictOnDelete();
            $tabel->foreignId('IdOutlet')->nullable()->constrained('Outlet', 'Id', 'FkAnggaranIdOutlet')->restrictOnDelete();
            $tabel->unsignedBigInteger('KunciOutlet')->storedAs('COALESCE(`IdOutlet`, 0)');
            $tabel->decimal('Jumlah', 20, 2);
            $tabel->timestamp('DibuatPada')->useCurrent();
            $tabel->timestamp('DiubahPada')->nullable();
            $tabel->unique(['IdTenant', 'Tahun', 'Bulan', 'IdAkun', 'KunciOutlet'], 'UniqAnggaranIdTenantTahunBulanIdAkunKunciOutlet');
            $tabel->index(['IdTenant', 'Tahun', 'KunciOutlet'], 'IdxAnggaranIdTenantTahunKunciOutlet');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Anggaran');
    }
};
