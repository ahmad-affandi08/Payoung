<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E8: **hapus-buku piutang** `HB/{YYYY}/{MM}/{SEQ4}` — sisa piutang yang dinyatakan tidak akan tertagih dibukukan
 * sebagai beban (Dr Beban Piutang Tak Tertagih, Cr Piutang Usaha) sehingga neraca tidak menampilkan tagihan yang
 * sebenarnya sudah mati.
 *
 * Piutangnya tidak dihapus dan tidak diedit nilainya: sisanya dipindahkan ke `JumlahDikurangi` dan statusnya menjadi
 * `DihapusBuku`, jadi umur piutang dan pelunasan lama tetap terbaca. Dokumen ini langsung diposting dan hanya bisa
 * dikoreksi lewat pembatalan (jurnal pembalik, aturan #8) — pembatalan mengembalikan tagihannya sehingga pelanggan
 * yang ternyata membayar kemudian tetap bisa dilunasi.
 *
 * Satu piutang hanya boleh punya satu hapus-buku berlaku. Penjaganya di skema: `IdPiutangAktif` salinan unik yang
 * dikosongkan saat dibatalkan (pola `PencairanDetail.IdPembayaranAktif`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('HapusBukuPiutang', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->UuidPublik();
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkHapusBukuPiutangIdTenant')->restrictOnDelete();
            $tabel->string('Nomor', 30);
            $tabel->foreignId('IdPiutang')->constrained('Piutang', 'Id', 'FkHapusBukuPiutangIdPiutang')->restrictOnDelete();
            $tabel->foreignId('IdPelanggan')->nullable()->constrained('Pelanggan', 'Id', 'FkHapusBukuPiutangIdPelanggan')->restrictOnDelete();
            $tabel->foreignId('IdOutlet')->constrained('Outlet', 'Id', 'FkHapusBukuPiutangIdOutlet')->restrictOnDelete();
            // Snapshot supaya dokumen terbaca tanpa menggabung tabel piutang.
            $tabel->string('NomorPiutang', 80);
            $tabel->date('Tanggal');
            $tabel->decimal('Jumlah', 18, 2);
            $tabel->string('Alasan', 255);
            $tabel->string('Status', 20);
            // Penahan klaim: sama dengan `IdPiutang` selama hapus-bukunya berlaku, NULL setelah dibatalkan.
            $tabel->unsignedBigInteger('IdPiutangAktif')->nullable();
            $tabel->foreignId('IdJurnal')->nullable()->constrained('Jurnal', 'Id', 'FkHapusBukuPiutangIdJurnal')->restrictOnDelete();
            $tabel->foreignId('IdJurnalPembatalan')->nullable()->constrained('Jurnal', 'Id', 'FkHapusBukuPiutangIdJurnalPembatalan')->restrictOnDelete();
            $tabel->foreignId('DibuatOleh')->nullable()->constrained('Pengguna', 'Id', 'FkHapusBukuPiutangDibuatOleh')->restrictOnDelete();
            $tabel->foreignId('DiubahOleh')->nullable()->constrained('Pengguna', 'Id', 'FkHapusBukuPiutangDiubahOleh')->restrictOnDelete();
            $tabel->foreignId('DibatalkanOleh')->nullable()->constrained('Pengguna', 'Id', 'FkHapusBukuPiutangDibatalkanOleh')->restrictOnDelete();
            $tabel->timestamp('DibatalkanPada')->nullable();
            $tabel->string('AlasanBatal', 255)->nullable();
            $tabel->WaktuStandar();
            $tabel->unique(['IdTenant', 'Nomor'], 'UniqHapusBukuPiutangIdTenantNomor');
            $tabel->unique(['IdTenant', 'IdPiutangAktif'], 'UniqHapusBukuPiutangIdTenantIdPiutangAktif');
            $tabel->index(['IdTenant', 'Status', 'Tanggal'], 'IdxHapusBukuPiutangIdTenantStatusTanggal');
            $tabel->index(['IdTenant', 'IdPelanggan'], 'IdxHapusBukuPiutangIdTenantIdPelanggan');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('HapusBukuPiutang');
    }
};
