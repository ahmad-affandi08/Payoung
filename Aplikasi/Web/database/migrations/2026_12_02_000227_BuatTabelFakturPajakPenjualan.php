<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TAX-07: Faktur Pajak per transaksi kasir atas permintaan pembeli ber-NPWP/NIK (PRD v6.27, D-147). Dokumen ini tidak
 * punya dampak stok maupun jurnal (pendapatan dan PPN sudah dibukukan oleh penjualannya); ia hanya menautkan satu
 * penjualan Lunas ke satu pelanggan ber-identitas pajak dan menyimpan Nomor Faktur Pajak dari Coretax, lalu ikut
 * ekspor XML Faktur Pajak Keluaran. `KunciPenjualanAktif` menjamin satu penjualan hanya punya satu Faktur Pajak aktif;
 * dibuat lewat Blueprint (`storedAs`) agar jalan di MySQL maupun MariaDB. Hanya menambah tabel (expand).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('FakturPajakPenjualan')) {
            return;
        }

        Schema::create('FakturPajakPenjualan', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->UuidPublik();
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkFakturPajakPenjualanIdTenant')->restrictOnDelete();
            $tabel->foreignId('IdOutlet')->constrained('Outlet', 'Id', 'FkFakturPajakPenjualanIdOutlet')->restrictOnDelete();
            $tabel->foreignId('IdPenjualan')->constrained('Penjualan', 'Id', 'FkFakturPajakPenjualanIdPenjualan')->restrictOnDelete();
            $tabel->foreignId('IdPelanggan')->constrained('Pelanggan', 'Id', 'FkFakturPajakPenjualanIdPelanggan')->restrictOnDelete();
            $tabel->date('Tanggal');
            $tabel->string('Status', 20)->default('Diposting');
            $tabel->string('NomorFakturPajak', 30)->nullable();
            $tabel->string('Catatan', 500)->nullable();
            $tabel->unsignedBigInteger('DibuatOleh')->nullable();
            $tabel->unsignedBigInteger('DiubahOleh')->nullable();
            $tabel->unsignedBigInteger('DibatalkanOleh')->nullable();
            $tabel->timestamp('DibatalkanPada')->nullable();
            $tabel->string('AlasanBatal', 500)->nullable();
            $tabel->string('KunciPenjualanAktif', 30)->nullable()->storedAs("IF(`Status` = 'Dibatalkan', NULL, `IdPenjualan`)");
            $tabel->WaktuStandar();
            $tabel->unique(['IdTenant', 'KunciPenjualanAktif'], 'UniqFakturPajakPenjualanAktif');
            $tabel->index(['IdTenant', 'Tanggal', 'IdOutlet'], 'IdxFakturPajakPenjualanTanggal');
            $tabel->index(['IdTenant', 'IdPenjualan'], 'IdxFakturPajakPenjualanPenjualan');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('FakturPajakPenjualan');
    }
};
