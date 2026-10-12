<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E8: **pengembalian uang dari pemasok** pada retur pembelian. Sampai sekarang retur ditolak bila nilainya melebihi sisa
 * hutang faktur (faktur sudah dibayar, `MelebihiSisaHutang`) dan retur belanja stok tidak didukung sama sekali
 * (`ReturBelanjaStok`) — padahal kasus umumnya: barang dibeli tunai lalu dikembalikan, dan pemasok mengembalikan
 * uangnya.
 *
 * Retur sekarang membagi nilainya (harga faktur + ongkir + PPN) menjadi dua: bagian yang **memotong sisa hutang**
 * faktur, dan sisanya `NilaiRefund` yang **diterima tunai** ke `IdAkunRefund` (kas/bank). Jurnal: Dr Hutang Usaha
 * (bagian pemotong) + Dr kas/bank (`NilaiRefund`), Cr Persediaan, Cr PPN Masukan. Keduanya disimpan di dokumen supaya
 * pembatalannya membalik ke akun yang sama (cermin `ReturGrosir.MengurangiPiutang`).
 *
 * Expand-only: kolom nullable/berbawaan 0, retur lama tidak berubah makna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ReturPembelian', function (Blueprint $tabel): void {
            $tabel->decimal('NilaiRefund', 18, 2)->default(0)->after('Pajak');
            $tabel->foreignId('IdAkunRefund')->nullable()->after('NilaiRefund')->constrained('Akun', 'Id', 'FkReturPembelianIdAkunRefund')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ReturPembelian', function (Blueprint $tabel): void {
            $tabel->dropForeign('FkReturPembelianIdAkunRefund');
            $tabel->dropColumn(['NilaiRefund', 'IdAkunRefund']);
        });
    }
};
