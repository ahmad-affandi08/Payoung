<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoice & penagihan B2B (F-21, D-166): invoice penjualan jasa atau barang tanpa stok langsung kepada pelanggan,
 * tanpa kasir dan tanpa surat jalan. Berbeda dari `FakturPenjualan` grosir yang hanya menagih barang berstok yang sudah
 * diserahkan lewat surat jalan, invoice ini mengakui pendapatan sendiri saat diterbitkan (Dr Piutang Usaha, Cr Pendapatan,
 * Cr PPN Keluaran), karena tidak ada penyerahan stok yang menjadi titik pengakuan lain.
 *
 * - `Invoice.Nomor` baru terisi saat diterbitkan (draf yang dihapus tidak meninggalkan celah nomor); `KodePublik` adalah
 *   tautan rahasia halaman invoice milik pelanggan (`/i/{kode}`), tidak bisa ditebak.
 * - `Jenis` UangMuka = invoice DP: Dr Piutang, Cr Uang Muka Pelanggan (DPP) + PPN Keluaran, karena PPN terutang saat
 *   pembayaran uang muka (UU PPN Pasal 11 ayat 1 huruf b). Invoice akhir memotongnya: `PotonganUangMukaDpp/Pajak`, dan
 *   `IdInvoiceAkhir` pada invoice DP menandai DP itu sudah dipakai.
 * - `NilaiPph` hanya informasi harapan potongan PPh yang akan dilakukan pelanggan (mis. PPh 23); pencatatan sebenarnya
 *   terjadi saat pelunasan (`PembayaranPiutang.PotonganPph`) karena nilainya mengikuti bukti potong pelanggan.
 *
 * `Piutang` di-expand lagi (aturan #15): tambah `IdInvoice` sebagai sumber ketiga; kueri aging, pengingat, pelunasan F-12
 * dipakai ulang apa adanya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Invoice', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->UuidPublik();
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkInvoiceIdTenant')->restrictOnDelete();
            // Terisi saat diterbitkan; draf belum bernomor.
            $tabel->string('Nomor', 30)->nullable();
            $tabel->string('KodePublik', 32);
            $tabel->string('Jenis', 12);
            $tabel->string('Status', 12);
            $tabel->foreignId('IdPelanggan')->constrained('Pelanggan', 'Id', 'FkInvoiceIdPelanggan')->restrictOnDelete();
            $tabel->foreignId('IdOutlet')->constrained('Outlet', 'Id', 'FkInvoiceIdOutlet')->restrictOnDelete();
            $tabel->date('Tanggal');
            $tabel->date('JatuhTempo');
            $tabel->unsignedSmallInteger('TerminHari')->default(0);
            $tabel->string('Perihal', 150)->nullable();
            // Nomor PO / kontrak milik pelanggan, supaya mudah dicocokkan di sisi mereka.
            $tabel->string('NomorReferensi', 60)->nullable();
            $tabel->boolean('HargaTermasukPajak')->default(false);
            $tabel->decimal('TarifPpn', 9, 6)->nullable();
            $tabel->unsignedInteger('PengaliDppPembilang')->nullable();
            $tabel->unsignedInteger('PengaliDppPenyebut')->nullable();
            $tabel->decimal('Subtotal', 18, 2)->default(0);
            $tabel->decimal('Diskon', 18, 2)->default(0);
            $tabel->decimal('DasarPengenaanPajak', 18, 2)->default(0);
            $tabel->decimal('Pajak', 18, 2)->default(0);
            $tabel->decimal('Total', 18, 2)->default(0);
            $tabel->json('RincianPajak')->nullable();
            $tabel->decimal('PotonganUangMukaDpp', 18, 2)->default(0);
            $tabel->decimal('PotonganUangMukaPajak', 18, 2)->default(0);
            // Yang benar-benar ditagih = Total - potongan uang muka; inilah jumlah piutangnya.
            $tabel->decimal('Tagihan', 18, 2)->default(0);
            $tabel->string('KodePph', 20)->nullable();
            $tabel->decimal('TarifPph', 9, 6)->nullable();
            $tabel->decimal('DasarPph', 18, 2)->default(0);
            $tabel->decimal('NilaiPph', 18, 2)->default(0);
            $tabel->string('Catatan', 1000)->nullable();
            $tabel->string('Syarat', 1000)->nullable();
            $tabel->string('InfoPembayaran', 500)->nullable();
            $tabel->unsignedBigInteger('IdInvoiceAkhir')->nullable();
            $tabel->timestamp('DiterbitkanPada')->nullable();
            $tabel->timestamp('TerkirimPada')->nullable();
            $tabel->timestamp('DilihatPada')->nullable();
            $tabel->unsignedInteger('JumlahDilihat')->default(0);
            $tabel->foreignId('IdJurnal')->nullable()->constrained('Jurnal', 'Id', 'FkInvoiceIdJurnal')->restrictOnDelete();
            $tabel->foreignId('IdJurnalPembatalan')->nullable()->constrained('Jurnal', 'Id', 'FkInvoiceIdJurnalPembatalan')->restrictOnDelete();
            $tabel->foreignId('DibuatOleh')->nullable()->constrained('Pengguna', 'Id', 'FkInvoiceDibuatOleh')->restrictOnDelete();
            $tabel->foreignId('DiubahOleh')->nullable()->constrained('Pengguna', 'Id', 'FkInvoiceDiubahOleh')->restrictOnDelete();
            $tabel->foreignId('DibatalkanOleh')->nullable()->constrained('Pengguna', 'Id', 'FkInvoiceDibatalkanOleh')->restrictOnDelete();
            $tabel->timestamp('DibatalkanPada')->nullable();
            $tabel->string('AlasanBatal', 255)->nullable();
            $tabel->WaktuStandar();
            $tabel->unique(['IdTenant', 'Nomor'], 'UniqInvoiceIdTenantNomor');
            $tabel->unique(['KodePublik'], 'UniqInvoiceKodePublik');
            $tabel->index(['IdTenant', 'Status', 'Tanggal'], 'IdxInvoiceIdTenantStatusTanggal');
            $tabel->index(['IdTenant', 'IdPelanggan', 'Status'], 'IdxInvoiceIdTenantIdPelangganStatus');
            $tabel->index(['IdTenant', 'IdOutlet', 'Tanggal'], 'IdxInvoiceIdTenantIdOutletTanggal');
        });

        Schema::table('Invoice', function (Blueprint $tabel): void {
            $tabel->foreign('IdInvoiceAkhir', 'FkInvoiceIdInvoiceAkhir')->references('Id')->on('Invoice')->restrictOnDelete();
        });

        Schema::create('InvoiceDetail', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkInvoiceDetailIdTenant')->restrictOnDelete();
            $tabel->foreignId('IdInvoice')->constrained('Invoice', 'Id', 'FkInvoiceDetailIdInvoice')->restrictOnDelete();
            $tabel->unsignedInteger('Urutan');
            // Produk Jasa / Tanpa stok dari katalog (boleh kosong untuk baris bebas yang diketik).
            $tabel->foreignId('IdProduk')->nullable()->constrained('Produk', 'Id', 'FkInvoiceDetailIdProduk')->restrictOnDelete();
            $tabel->string('Nama', 200);
            $tabel->string('Keterangan', 255)->nullable();
            // Jasa = Pendapatan Jasa, Barang = Penjualan (barang tanpa stok).
            $tabel->string('JenisBaris', 8);
            $tabel->string('SimbolSatuan', 20)->nullable();
            $tabel->decimal('Jumlah', 18, 4);
            $tabel->decimal('Harga', 18, 2);
            $tabel->decimal('Diskon', 18, 2)->default(0);
            // Kotor (Harga x Jumlah); diskonnya kolom tersendiri.
            $tabel->decimal('Subtotal', 18, 2);
            $tabel->boolean('HargaTermasukPajak')->nullable();
            $tabel->foreignId('IdKelompokPajak')->nullable()->constrained('KelompokPajak', 'Id', 'FkInvoiceDetailIdKelompokPajak')->nullOnDelete();
            $tabel->decimal('Pajak', 18, 2)->default(0);
            // Bruto - pajak inklusif: kredit pendapatan baris ini di jurnal (diskon didebit ke Diskon Penjualan).
            $tabel->decimal('Pendapatan', 18, 2)->default(0);
            $tabel->WaktuStandar();
            $tabel->index(['IdTenant', 'IdInvoice', 'Urutan'], 'IdxInvoiceDetailIdTenantIdInvoiceUrutan');
        });

        Schema::table('Piutang', function (Blueprint $tabel): void {
            $tabel->foreignId('IdInvoice')->nullable()->after('IdFakturPenjualan')
                ->constrained('Invoice', 'Id', 'FkPiutangIdInvoice')->restrictOnDelete();
            $tabel->unique(['IdInvoice'], 'UniqPiutangIdInvoice');
        });

        Schema::table('PembayaranPiutang', function (Blueprint $tabel): void {
            $tabel->decimal('PotonganPph', 18, 2)->default(0)->after('Jumlah');
            $tabel->string('NomorBuktiPotong', 40)->nullable()->after('PotonganPph');
        });

        Schema::table('PembayaranPiutangAlokasi', function (Blueprint $tabel): void {
            $tabel->decimal('PotonganPph', 18, 2)->default(0)->after('Jumlah');
        });
    }

    public function down(): void
    {
        Schema::table('PembayaranPiutangAlokasi', function (Blueprint $tabel): void {
            $tabel->dropColumn('PotonganPph');
        });

        Schema::table('PembayaranPiutang', function (Blueprint $tabel): void {
            $tabel->dropColumn(['PotonganPph', 'NomorBuktiPotong']);
        });

        Schema::table('Piutang', function (Blueprint $tabel): void {
            $tabel->dropForeign('FkPiutangIdInvoice');
            $tabel->dropUnique('UniqPiutangIdInvoice');
            $tabel->dropColumn('IdInvoice');
        });

        Schema::dropIfExists('InvoiceDetail');
        Schema::dropIfExists('Invoice');
    }
};
