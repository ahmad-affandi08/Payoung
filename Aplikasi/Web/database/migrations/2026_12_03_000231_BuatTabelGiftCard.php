<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM-06 gift card (D-153, §15 `GiftCard`, J-16.9):
 * - `GiftCard` kartu bernilai berkode milik tenant, dipakai di semua outlet; pemegangnya tidak harus pelanggan
 *   terdaftar (`IdPelanggan` hanya mencatat pembeli bila dipilih). Saldo = cache Σ `MutasiGiftCard.Jumlah`.
 * - `MutasiGiftCard` buku kartu append-only; saldo kartu dikunci per baris `GiftCard` sehingga `SaldoSetelah`
 *   berurutan. Satu mutasi per (Jenis, JenisSumber, IdSumber) = idempoten per dokumen sumber. Penjualan, hangus, dan
 *   pengembalian uang dari back-office memakai baris mutasinya sendiri sebagai sumber jurnal (`IdJurnal`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('GiftCard', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->UuidPublik();
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkGiftCardIdTenant')->restrictOnDelete();
            $tabel->string('Kode', 20);
            $tabel->decimal('NilaiAwal', 18, 2);
            $tabel->decimal('Saldo', 18, 2);
            $tabel->date('BerlakuSampai')->nullable();
            $tabel->string('Status', 20);
            $tabel->foreignId('IdPelanggan')->nullable()->constrained('Pelanggan', 'Id', 'FkGiftCardIdPelanggan')->restrictOnDelete();
            $tabel->string('NamaPemegang', 100)->nullable();
            $tabel->string('Catatan', 255)->nullable();
            $tabel->foreignId('IdPenerbit')->nullable()->constrained('Pengguna', 'Id', 'FkGiftCardIdPenerbit')->restrictOnDelete();
            $tabel->timestamp('DiterbitkanPada');
            $tabel->WaktuStandar();
            $tabel->unique(['IdTenant', 'Kode'], 'UniqGiftCardIdTenantKode');
            $tabel->index(['IdTenant', 'Status', 'BerlakuSampai'], 'IdxGiftCardIdTenantStatusBerlaku');
            $tabel->index(['IdTenant', 'Id'], 'IdxGiftCardIdTenantId');
        });

        Schema::create('MutasiGiftCard', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->UuidPublik();
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkMutasiGiftCardIdTenant')->restrictOnDelete();
            $tabel->foreignId('IdGiftCard')->constrained('GiftCard', 'Id', 'FkMutasiGiftCardIdGiftCard')->restrictOnDelete();
            $tabel->string('Jenis', 20);
            $tabel->decimal('Jumlah', 18, 2);
            $tabel->decimal('SaldoSetelah', 18, 2);
            $tabel->string('JenisSumber', 20);
            $tabel->unsignedBigInteger('IdSumber')->nullable();
            $tabel->string('NomorSumber', 80)->nullable();
            $tabel->date('Tanggal');
            $tabel->foreignId('IdAkunKasBank')->nullable()->constrained('Akun', 'Id', 'FkMutasiGiftCardIdAkunKasBank')->restrictOnDelete();
            $tabel->foreignId('IdJurnal')->nullable()->constrained('Jurnal', 'Id', 'FkMutasiGiftCardIdJurnal')->restrictOnDelete();
            $tabel->string('Keterangan', 255)->nullable();
            $tabel->foreignId('IdPengguna')->nullable()->constrained('Pengguna', 'Id', 'FkMutasiGiftCardIdPengguna')->restrictOnDelete();
            $tabel->WaktuStandar();
            $tabel->unique(['IdTenant', 'Jenis', 'JenisSumber', 'IdSumber'], 'UniqMutasiGiftCardIdTenantJenisSumber');
            $tabel->index(['IdTenant', 'IdGiftCard', 'Id'], 'IdxMutasiGiftCardIdTenantIdGiftCard');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('MutasiGiftCard');
        Schema::dropIfExists('GiftCard');
    }
};
