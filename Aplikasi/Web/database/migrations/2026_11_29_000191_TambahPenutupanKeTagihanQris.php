<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E8: penutupan **uang QRIS yang masuk tanpa penjualan**. Tagihan QRIS dinamis POS yang dibayar setelah kedaluwarsa /
 * dibatalkan / tidak pasti ditandai `PerluTinjauan` (audit P0 F-02), tetapi sampai sekarang tidak ada cara menutupnya:
 * butir Kotak Tindakan hanya bisa disembunyikan ("sudah dicek"), padahal uang pelanggannya nyata dan harus berujung di
 * salah satu dari dua tempat — dikembalikan ke pelanggan, atau diakui sebagai pendapatan lain.
 *
 * Kolom penutupan ada di `TagihanQris` sendiri (expand, semuanya nullable) karena penutupan satu-ke-satu dengan
 * tagihan dan bukan dokumen yang punya nomor sendiri. `HasilPenutupan`:
 * - `Dikembalikan`: dana dikembalikan ke pelanggan di luar sistem (lewat dasbor gerbang). Tanpa jurnal: uang yang masuk
 *   lalu keluar lagi tidak pernah dibukukan, jadi tidak ada yang perlu dibalik;
 * - `DiakuiPendapatan`: pelanggan tidak menuntut; jurnal Dr kas/bank (akun tempat uangnya mendarat) / Cr Pendapatan
 *   Lain di `IdJurnalPenutupan`. Membuka kembali penutupan membalik jurnal itu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('TagihanQris', function (Blueprint $tabel): void {
            $tabel->timestamp('DitutupPada')->nullable()->after('AlasanTinjauan');
            $tabel->foreignId('DitutupOleh')->nullable()->after('DitutupPada')->constrained('Pengguna', 'Id', 'FkTagihanQrisDitutupOleh')->restrictOnDelete();
            $tabel->string('HasilPenutupan', 20)->nullable()->after('DitutupOleh');
            $tabel->string('CatatanPenutupan', 255)->nullable()->after('HasilPenutupan');
            $tabel->foreignId('IdJurnalPenutupan')->nullable()->after('CatatanPenutupan')->constrained('Jurnal', 'Id', 'FkTagihanQrisIdJurnalPenutupan')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('TagihanQris', function (Blueprint $tabel): void {
            $tabel->dropForeign('FkTagihanQrisIdJurnalPenutupan');
            $tabel->dropForeign('FkTagihanQrisDitutupOleh');
            $tabel->dropColumn(['DitutupPada', 'DitutupOleh', 'HasilPenutupan', 'CatatanPenutupan', 'IdJurnalPenutupan']);
        });
    }
};
