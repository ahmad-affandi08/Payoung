<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FnB reservasi meja + DP (§9.1, D-145): tamu memesan meja (atau area) untuk jumlah orang dan jam tertentu. Berbeda dari
 * `Reservasi` (layanan per staf, F-07 mode service). Meja boleh dipilih belakangan (wajib sebelum "Sudah datang");
 * bentrok waktu per meja dijaga Aksi dengan kunci baris meja. Uang muka (DP) dibukukan sebagai kewajiban Uang Muka
 * Pelanggan (Dr kas/bank, Cr Uang Muka Pelanggan), lalu terpakai membayar tagihan, dikembalikan, atau hangus (pendapatan
 * lain). Nominal uang DECIMAL (CLAUDE.md #7). Dokumen kerja tanpa soft delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ReservasiMeja', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->UuidPublik();
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkReservasiMejaIdTenant')->restrictOnDelete();
            $tabel->foreignId('IdOutlet')->constrained('Outlet', 'Id', 'FkReservasiMejaIdOutlet')->restrictOnDelete();
            $tabel->string('Nomor', 30);
            $tabel->foreignId('IdMeja')->nullable()->constrained('Meja', 'Id', 'FkReservasiMejaIdMeja')->restrictOnDelete();
            $tabel->foreignId('IdAreaMeja')->nullable()->constrained('AreaMeja', 'Id', 'FkReservasiMejaIdAreaMeja')->restrictOnDelete();
            $tabel->foreignId('IdPelanggan')->nullable()->constrained('Pelanggan', 'Id', 'FkReservasiMejaIdPelanggan')->restrictOnDelete();
            $tabel->string('NamaPemesan', 100);
            $tabel->string('NoHp', 20);
            $tabel->unsignedSmallInteger('JumlahTamu');
            $tabel->dateTime('MulaiPada');
            $tabel->unsignedSmallInteger('DurasiMenit')->default(120);
            $tabel->string('Status', 20);
            $tabel->string('Sumber', 20);
            $tabel->string('Catatan', 255)->nullable();
            $tabel->string('AlasanBatal', 255)->nullable();
            // Uang muka (DP): satu kali dicatat; sisa = JumlahDp - DpTerpakai - DpDikembalikan - DpHangus.
            $tabel->decimal('JumlahDp', 18, 2)->default(0);
            $tabel->decimal('DpTerpakai', 18, 2)->default(0);
            $tabel->decimal('DpDikembalikan', 18, 2)->default(0);
            $tabel->decimal('DpHangus', 18, 2)->default(0);
            $tabel->timestamp('DpDicatatPada')->nullable();
            $tabel->unsignedBigInteger('IdJurnalDp')->nullable();
            $tabel->unsignedBigInteger('IdJurnalPenyelesaianDp')->nullable();
            $tabel->foreignId('IdPesananTerbuka')->nullable()->constrained('PesananTerbuka', 'Id', 'FkReservasiMejaIdPesananTerbuka')->restrictOnDelete();
            $tabel->foreignId('IdPenjualan')->nullable()->constrained('Penjualan', 'Id', 'FkReservasiMejaIdPenjualan')->restrictOnDelete();
            $tabel->timestamp('HadirPada')->nullable();
            $tabel->foreignId('DibuatOleh')->nullable()->constrained('Pengguna', 'Id', 'FkReservasiMejaDibuatOleh')->restrictOnDelete();
            $tabel->WaktuStandar();
            $tabel->unique(['IdTenant', 'Nomor'], 'UniqReservasiMejaIdTenantNomor');
            $tabel->index(['IdTenant', 'IdOutlet', 'MulaiPada'], 'IdxReservasiMejaIdTenantIdOutletMulaiPada');
            $tabel->index(['IdTenant', 'IdMeja', 'MulaiPada'], 'IdxReservasiMejaIdTenantIdMejaMulaiPada');
            $tabel->index(['IdTenant', 'Status', 'MulaiPada'], 'IdxReservasiMejaIdTenantStatusMulaiPada');
            $tabel->index(['IdTenant', 'NoHp'], 'IdxReservasiMejaIdTenantNoHp');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ReservasiMeja');
    }
};
