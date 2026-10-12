<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-112 (P-08, audit E4/E6): tagihan manual untuk paket harga negosiasi (Enterprise) yang tidak punya `HargaPaket`,
 * hapus-buku tagihan lewat jatuh tempo, dan refund tagihan lunas.
 *
 * - `TagihanLangganan.IdHargaPaket` boleh kosong: tagihan manual membawa harga kesepakatan sendiri (`Subtotal`).
 * - `Manual` + `Keterangan` + `IdPenggunaPengelolaPembuat` menandai tagihan yang diterbitkan Keuangan/Super Admin;
 *   hanya tagihan manual yang boleh dibayar dengan transfer (bukti diunggah Owner), sisanya lewat gerbang DOKU (D-82).
 * - `DihapusBukuPada`/`AlasanHapusBuku` dan `DikembalikanPada` melengkapi transisi `JatuhTempo → Dihapuskan` dan
 *   `Lunas → Dikembalikan` yang sebelumnya tidak punya aksi.
 * - `RefundLangganan`: pengajuan refund dengan persetujuan kedua di atas batas (BR-P08.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE `TagihanLangganan` MODIFY `IdHargaPaket` BIGINT UNSIGNED NULL');

        Schema::table('TagihanLangganan', function (Blueprint $tabel): void {
            $tabel->boolean('Manual')->default(false)->after('Status');
            $tabel->string('Keterangan', 300)->nullable()->after('Manual');
            $tabel->foreignId('IdPenggunaPengelolaPembuat')->nullable()->after('IdPenggunaPembuat')
                ->constrained('PenggunaPengelola', 'Id', 'FkTagihanLanggananIdPenggunaPengelolaPembuat')->restrictOnDelete();
            $tabel->timestamp('DihapusBukuPada')->nullable()->after('AlasanBatal');
            $tabel->string('AlasanHapusBuku', 500)->nullable()->after('DihapusBukuPada');
            $tabel->timestamp('DikembalikanPada')->nullable()->after('AlasanHapusBuku');
        });

        Schema::create('RefundLangganan', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->UuidPublik();
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkRefundLanggananIdTenant')->restrictOnDelete();
            $tabel->foreignId('IdTagihanLangganan')->constrained('TagihanLangganan', 'Id', 'FkRefundLanggananIdTagihanLangganan')->restrictOnDelete();
            $tabel->string('Status', 20);
            $tabel->decimal('Jumlah', 18, 2);
            $tabel->string('Alasan', 500);
            // Cara uang dikembalikan ke tenant (nomor referensi transfer balik); pengembalian dana dilakukan Keuangan di luar sistem.
            $tabel->string('CatatanPengembalian', 300)->nullable();
            $tabel->foreignId('IdPenggunaPengelolaPengaju')->constrained('PenggunaPengelola', 'Id', 'FkRefundLanggananIdPengaju')->restrictOnDelete();
            $tabel->foreignId('IdPenggunaPengelolaPemutus')->nullable()->constrained('PenggunaPengelola', 'Id', 'FkRefundLanggananIdPemutus')->restrictOnDelete();
            $tabel->timestamp('DiputuskanPada')->nullable();
            $tabel->string('AlasanTolak', 500)->nullable();
            $tabel->boolean('LayananDiakhiri')->default(false);
            $tabel->WaktuStandar();
            $tabel->index(['IdTagihanLangganan', 'Status'], 'IdxRefundLanggananTagihanStatus');
            $tabel->index(['Status', 'DibuatPada'], 'IdxRefundLanggananStatus');
        });
    }

    public function down(): void
    {
        // Tidak dibalik: tagihan manual/refund yang sudah tercatat tidak boleh kehilangan kolomnya.
    }
};
