<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penawaran harga (F-21 bagian 3, D-168): dokumen sebelum invoice. Tidak berdampak stok maupun jurnal; angkanya hanya
 * perkiraan yang dihitung dengan mesin kalkulasi yang sama dengan invoice. Setelah `Terbit` isinya terkunci (aturan #8);
 * pelanggan menerima atau menolak dari halaman publik `/p/{kode}` sebelum `BerlakuSampai`, dan penawaran yang diterima
 * dijadikan draf invoice (`IdInvoice`).
 *
 * - `Nomor` baru terisi saat diterbitkan, seperti invoice (draf yang batal tidak meninggalkan celah nomor).
 * - `IdInvoice` unik: satu invoice berasal dari paling banyak satu penawaran. Bila invoice itu dibatalkan, penawaran bisa
 *   dijadikan invoice lagi (diperiksa aksi, bukan skema).
 * - `NamaPemutus`/`CatatanKeputusan` diketik pelanggan di halaman publik (data pribadi minimal), tidak dipakai untuk apa pun
 *   selain ditampilkan kepada penagih.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Penawaran', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->UuidPublik();
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkPenawaranIdTenant')->restrictOnDelete();
            $tabel->string('Nomor', 30)->nullable();
            $tabel->string('KodePublik', 32);
            $tabel->string('Status', 12);
            $tabel->foreignId('IdPelanggan')->constrained('Pelanggan', 'Id', 'FkPenawaranIdPelanggan')->restrictOnDelete();
            $tabel->foreignId('IdOutlet')->constrained('Outlet', 'Id', 'FkPenawaranIdOutlet')->restrictOnDelete();
            $tabel->date('Tanggal');
            $tabel->date('BerlakuSampai');
            // Termin yang akan dipakai invoice hasil penawaran ini.
            $tabel->unsignedSmallInteger('TerminHari')->default(0);
            $tabel->string('Perihal', 150)->nullable();
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
            $tabel->string('Catatan', 1000)->nullable();
            $tabel->string('Syarat', 1000)->nullable();
            $tabel->timestamp('DiterbitkanPada')->nullable();
            $tabel->timestamp('TerkirimPada')->nullable();
            $tabel->timestamp('DilihatPada')->nullable();
            $tabel->unsignedInteger('JumlahDilihat')->default(0);
            // Keputusan: `Pelanggan` (dari halaman publik) atau `Staf` (dikonfirmasi penagih, mis. diterima lewat telepon).
            $tabel->string('SumberKeputusan', 10)->nullable();
            $tabel->string('NamaPemutus', 100)->nullable();
            $tabel->string('CatatanKeputusan', 500)->nullable();
            $tabel->timestamp('DiputuskanPada')->nullable();
            $tabel->foreignId('IdInvoice')->nullable()->constrained('Invoice', 'Id', 'FkPenawaranIdInvoice')->restrictOnDelete();
            $tabel->foreignId('DibuatOleh')->nullable()->constrained('Pengguna', 'Id', 'FkPenawaranDibuatOleh')->restrictOnDelete();
            $tabel->foreignId('DiubahOleh')->nullable()->constrained('Pengguna', 'Id', 'FkPenawaranDiubahOleh')->restrictOnDelete();
            $tabel->foreignId('DibatalkanOleh')->nullable()->constrained('Pengguna', 'Id', 'FkPenawaranDibatalkanOleh')->restrictOnDelete();
            $tabel->timestamp('DibatalkanPada')->nullable();
            $tabel->string('AlasanBatal', 255)->nullable();
            $tabel->WaktuStandar();
            $tabel->unique(['IdTenant', 'Nomor'], 'UniqPenawaranIdTenantNomor');
            $tabel->unique(['KodePublik'], 'UniqPenawaranKodePublik');
            $tabel->unique(['IdInvoice'], 'UniqPenawaranIdInvoice');
            $tabel->index(['IdTenant', 'Status', 'Tanggal'], 'IdxPenawaranIdTenantStatusTanggal');
            $tabel->index(['IdTenant', 'IdPelanggan', 'Status'], 'IdxPenawaranIdTenantIdPelangganStatus');
            $tabel->index(['IdTenant', 'IdOutlet', 'Tanggal'], 'IdxPenawaranIdTenantIdOutletTanggal');
        });

        Schema::create('PenawaranDetail', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkPenawaranDetailIdTenant')->restrictOnDelete();
            $tabel->foreignId('IdPenawaran')->constrained('Penawaran', 'Id', 'FkPenawaranDetailIdPenawaran')->restrictOnDelete();
            $tabel->unsignedInteger('Urutan');
            $tabel->foreignId('IdProduk')->nullable()->constrained('Produk', 'Id', 'FkPenawaranDetailIdProduk')->restrictOnDelete();
            $tabel->string('Nama', 200);
            $tabel->string('Keterangan', 255)->nullable();
            $tabel->string('JenisBaris', 8);
            $tabel->string('SimbolSatuan', 20)->nullable();
            $tabel->decimal('Jumlah', 18, 4);
            $tabel->decimal('Harga', 18, 2);
            $tabel->decimal('Diskon', 18, 2)->default(0);
            $tabel->decimal('Subtotal', 18, 2);
            $tabel->boolean('HargaTermasukPajak')->nullable();
            $tabel->foreignId('IdKelompokPajak')->nullable()->constrained('KelompokPajak', 'Id', 'FkPenawaranDetailIdKelompokPajak')->nullOnDelete();
            $tabel->decimal('Pajak', 18, 2)->default(0);
            $tabel->WaktuStandar();
            $tabel->index(['IdTenant', 'IdPenawaran', 'Urutan'], 'IdxPenawaranDetailIdTenantIdPenawaranUrutan');
        });

        Schema::create('PengirimanPenawaran', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->UuidPublik();
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkPengirimanPenawaranIdTenant')->restrictOnDelete();
            $tabel->foreignId('IdPenawaran')->constrained('Penawaran', 'Id', 'FkPengirimanPenawaranIdPenawaran')->restrictOnDelete();
            $tabel->text('Tujuan');
            $tabel->string('Status', 20);
            $tabel->string('Penyedia', 30)->nullable();
            $tabel->string('IdPesanPenyedia', 191)->nullable();
            $tabel->string('PesanGalat', 300)->nullable();
            $tabel->unsignedTinyInteger('Percobaan')->default(0);
            $tabel->timestamp('TerkirimPada')->nullable();
            $tabel->foreignId('DikirimOleh')->nullable()->constrained('Pengguna', 'Id', 'FkPengirimanPenawaranDikirimOleh')->restrictOnDelete();
            $tabel->WaktuStandar();
            $tabel->index(['IdTenant', 'IdPenawaran'], 'IdxPengirimanPenawaranIdTenantIdPenawaran');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('PengirimanPenawaran');
        Schema::dropIfExists('PenawaranDetail');
        Schema::dropIfExists('Penawaran');
    }
};
