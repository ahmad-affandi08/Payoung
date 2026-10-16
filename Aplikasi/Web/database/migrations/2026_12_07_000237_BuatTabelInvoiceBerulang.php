<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoice berulang (F-21): templat tagihan langganan, sewa, retainer, atau maintenance yang diterbitkan otomatis tiap
 * periode. Templat bukan dokumen akuntansi (tanpa jurnal, tanpa nomor), jadi boleh diubah dan dihentikan; yang terbit
 * darinya adalah `Invoice` biasa yang tunduk pada semua aturan invoice.
 *
 * - Jadwal: `Frekuensi` × `Interval`, dihitung dari `TanggalMulai` untuk siklus ke-N (bukan dari tanggal terbit
 *   sebelumnya), sehingga tanggal 31 tidak "menyusut" selamanya setelah melewati Februari.
 * - `SiklusBerikutnya` + `BerikutnyaPada` adalah penanda jadwal; `Invoice.TanggalSiklus` + indeks unik
 *   `(IdInvoiceBerulang, TanggalSiklus)` menjamin satu siklus tidak pernah menerbitkan dua invoice, walau perintah
 *   terjadwal berjalan dua kali atau diulang setelah galat.
 * - `Mode`: draf saja, terbitkan otomatis, atau terbitkan lalu kirim WhatsApp.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('InvoiceBerulang', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->UuidPublik();
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkInvoiceBerulangIdTenant')->restrictOnDelete();
            $tabel->string('Nama', 120);
            $tabel->foreignId('IdPelanggan')->constrained('Pelanggan', 'Id', 'FkInvoiceBerulangIdPelanggan')->restrictOnDelete();
            $tabel->foreignId('IdOutlet')->constrained('Outlet', 'Id', 'FkInvoiceBerulangIdOutlet')->restrictOnDelete();
            $tabel->string('Frekuensi', 10);
            $tabel->unsignedTinyInteger('Interval')->default(1);
            $tabel->date('TanggalMulai');
            $tabel->date('TanggalBerakhir')->nullable();
            $tabel->unsignedSmallInteger('BatasKali')->nullable();
            $tabel->unsignedSmallInteger('SiklusBerikutnya')->default(0);
            $tabel->unsignedSmallInteger('JumlahTerbit')->default(0);
            $tabel->date('BerikutnyaPada')->nullable();
            $tabel->string('Status', 10);
            $tabel->string('Mode', 20);
            $tabel->unsignedSmallInteger('TerminHari')->nullable();
            $tabel->boolean('HargaTermasukPajak')->default(false);
            $tabel->string('Perihal', 150)->nullable();
            $tabel->string('NomorReferensi', 60)->nullable();
            $tabel->string('KodePph', 20)->nullable();
            $tabel->decimal('TarifPph', 9, 6)->nullable();
            $tabel->string('Catatan', 1000)->nullable();
            $tabel->string('Syarat', 1000)->nullable();
            $tabel->string('InfoPembayaran', 500)->nullable();
            $tabel->timestamp('TerakhirTerbitPada')->nullable();
            $tabel->string('GalatTerakhir', 300)->nullable();
            $tabel->timestamp('GalatPada')->nullable();
            $tabel->foreignId('DibuatOleh')->constrained('Pengguna', 'Id', 'FkInvoiceBerulangDibuatOleh')->restrictOnDelete();
            $tabel->foreignId('DiubahOleh')->nullable()->constrained('Pengguna', 'Id', 'FkInvoiceBerulangDiubahOleh')->restrictOnDelete();
            $tabel->WaktuStandar();
            $tabel->index(['IdTenant', 'Status', 'BerikutnyaPada'], 'IdxInvoiceBerulangIdTenantStatusBerikutnyaPada');
            $tabel->index(['IdTenant', 'IdPelanggan'], 'IdxInvoiceBerulangIdTenantIdPelanggan');
        });

        Schema::create('InvoiceBerulangDetail', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkInvoiceBerulangDetailIdTenant')->restrictOnDelete();
            $tabel->foreignId('IdInvoiceBerulang')->constrained('InvoiceBerulang', 'Id', 'FkInvoiceBerulangDetailIdInvoiceBerulang')->cascadeOnDelete();
            $tabel->unsignedInteger('Urutan');
            $tabel->foreignId('IdProduk')->nullable()->constrained('Produk', 'Id', 'FkInvoiceBerulangDetailIdProduk')->restrictOnDelete();
            $tabel->string('Nama', 200);
            $tabel->string('Keterangan', 255)->nullable();
            $tabel->string('JenisBaris', 8);
            $tabel->string('SimbolSatuan', 20)->nullable();
            $tabel->decimal('Jumlah', 18, 4);
            $tabel->decimal('Harga', 18, 2);
            $tabel->decimal('Diskon', 18, 2)->default(0);
            $tabel->boolean('HargaTermasukPajak')->nullable();
            $tabel->foreignId('IdKelompokPajak')->nullable()->constrained('KelompokPajak', 'Id', 'FkInvoiceBerulangDetailIdKelompokPajak')->nullOnDelete();
            $tabel->WaktuStandar();
            $tabel->index(['IdTenant', 'IdInvoiceBerulang', 'Urutan'], 'IdxInvoiceBerulangDetailIdTenantIdInvoiceBerulangUrutan');
        });

        Schema::table('Invoice', function (Blueprint $tabel): void {
            $tabel->foreignId('IdInvoiceBerulang')->nullable()->after('IdInvoiceAkhir')
                ->constrained('InvoiceBerulang', 'Id', 'FkInvoiceIdInvoiceBerulang')->restrictOnDelete();
            $tabel->date('TanggalSiklus')->nullable()->after('IdInvoiceBerulang');
            $tabel->unique(['IdInvoiceBerulang', 'TanggalSiklus'], 'UniqInvoiceIdInvoiceBerulangTanggalSiklus');
        });
    }

    public function down(): void
    {
        Schema::table('Invoice', function (Blueprint $tabel): void {
            $tabel->dropForeign('FkInvoiceIdInvoiceBerulang');
            $tabel->dropUnique('UniqInvoiceIdInvoiceBerulangTanggalSiklus');
            $tabel->dropColumn(['IdInvoiceBerulang', 'TanggalSiklus']);
        });
        Schema::dropIfExists('InvoiceBerulangDetail');
        Schema::dropIfExists('InvoiceBerulang');
    }
};
