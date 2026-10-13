<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v6.07 (F-04 Nota Debit, BR-04.10): pengurang hutang atas satu faktur pembelian untuk selisih harga atau klaim
 * pemasok **tanpa barang kembali** (retur tetap untuk barang yang kembali). `NilaiKlaim` dipecah per baris faktur:
 * bagian untuk stok yang masih ada menurunkan nilai persediaan (mutasi penilaian ulang), bagian untuk barang yang sudah
 * terjual mengurangi HPP (BR-04.4). `PotongHutang` memotong sisa hutang faktur, `NilaiRefund` = bagian yang melebihi sisa
 * (faktur sudah dibayar) diterima pemasok sebagai uang ke kas/bank. Jurnal J-04.7.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('NotaDebitPembelian', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->UuidPublik();
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkNotaDebitPembelianIdTenant')->restrictOnDelete();
            $tabel->string('Nomor', 30);
            $tabel->string('NomorNotaPemasok', 60)->nullable();
            $tabel->foreignId('IdFakturPembelian')->constrained('FakturPembelian', 'Id', 'FkNotaDebitPembelianIdFaktur')->restrictOnDelete();
            $tabel->foreignId('IdPemasok')->nullable()->constrained('Pemasok', 'Id', 'FkNotaDebitPembelianIdPemasok')->restrictOnDelete();
            $tabel->foreignId('IdOutlet')->nullable()->constrained('Outlet', 'Id', 'FkNotaDebitPembelianIdOutlet')->restrictOnDelete();
            $tabel->string('Jenis', 20);
            $tabel->date('Tanggal');
            $tabel->decimal('NilaiKlaim', 18, 2);
            $tabel->decimal('Pajak', 18, 2)->default(0);
            $tabel->decimal('Total', 18, 2);
            $tabel->decimal('PotongHutang', 18, 2);
            $tabel->decimal('NilaiRefund', 18, 2)->default(0);
            $tabel->foreignId('IdAkunRefund')->nullable()->constrained('Akun', 'Id', 'FkNotaDebitPembelianIdAkunRefund')->restrictOnDelete();
            $tabel->decimal('KePersediaan', 18, 2)->default(0);
            $tabel->decimal('KeHpp', 18, 2)->default(0);
            $tabel->string('Alasan', 255);
            $tabel->string('Status', 15);
            $tabel->unsignedBigInteger('IdJurnal')->nullable();
            $tabel->unsignedBigInteger('IdJurnalPembatalan')->nullable();
            $tabel->foreignId('DibuatOleh')->nullable()->constrained('Pengguna', 'Id', 'FkNotaDebitPembelianDibuatOleh')->restrictOnDelete();
            $tabel->foreignId('DibatalkanOleh')->nullable()->constrained('Pengguna', 'Id', 'FkNotaDebitPembelianDibatalkanOleh')->restrictOnDelete();
            $tabel->timestamp('DibatalkanPada')->nullable();
            $tabel->string('AlasanBatal', 255)->nullable();
            $tabel->WaktuStandar();
            $tabel->unique(['IdTenant', 'Nomor'], 'UniqNotaDebitPembelianIdTenantNomor');
            $tabel->index(['IdTenant', 'IdFakturPembelian', 'Status'], 'IdxNotaDebitPembelianIdTenantIdFaktur');
            $tabel->index(['IdTenant', 'Tanggal'], 'IdxNotaDebitPembelianIdTenantTanggal');
        });

        Schema::create('NotaDebitPembelianDetail', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkNotaDebitPembelianDetailIdTenant')->restrictOnDelete();
            $tabel->foreignId('IdNotaDebitPembelian')->constrained('NotaDebitPembelian', 'Id', 'FkNotaDebitPembelianDetailIdNota')->restrictOnDelete();
            $tabel->unsignedInteger('Urutan');
            $tabel->foreignId('IdFakturPembelianDetail')->constrained('FakturPembelianDetail', 'Id', 'FkNotaDebitPembelianDetailIdFakturDetail')->restrictOnDelete();
            $tabel->foreignId('IdProduk')->constrained('Produk', 'Id', 'FkNotaDebitPembelianDetailIdProduk')->restrictOnDelete();
            $tabel->string('NamaProduk', 150);
            $tabel->decimal('NilaiKlaim', 18, 2);
            $tabel->decimal('Pajak', 18, 2)->default(0);
            $tabel->decimal('KePersediaan', 18, 2)->default(0);
            $tabel->decimal('KeHpp', 18, 2)->default(0);
            $tabel->WaktuStandar();
            $tabel->index(['IdTenant', 'IdNotaDebitPembelian'], 'IdxNotaDebitPembelianDetailIdTenantIdNota');
            $tabel->index(['IdTenant', 'IdFakturPembelianDetail'], 'IdxNotaDebitPembelianDetailIdTenantIdFakturDetail');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('NotaDebitPembelianDetail');
        Schema::dropIfExists('NotaDebitPembelian');
    }
};
