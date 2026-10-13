<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-12 BR-12.7: **pengembalian uang ke pembeli** pada retur grosir. Sampai sekarang retur atas surat jalan yang
 * fakturnya sudah (hampir) dibayar ditolak (`SisaPiutangTidakCukup`) karena sisa tagihan tidak cukup menampung nilai
 * retur — padahal itu kasus yang lazim: pembeli sudah lunas, lalu mengembalikan barang.
 *
 * Retur sekarang membagi nilainya menjadi bagian yang **mengurangi sisa tagihan** faktur dan sisanya `NilaiRefund`
 * yang **dibayarkan kembali** dari `IdAkunRefund` (kas/bank). J-12.4: Dr Retur Penjualan + Dr PPN Keluaran + Dr
 * Persediaan, Cr Piutang Usaha (bagian pengurang tagihan) + Cr kas/bank (`NilaiRefund`) + Cr HPP. Keduanya disimpan di
 * dokumen supaya pembatalannya membalik ke akun yang sama (cermin `ReturPembelian.NilaiRefund`).
 *
 * Expand-only: kolom nullable/berbawaan 0, retur lama tidak berubah makna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ReturGrosir', function (Blueprint $tabel): void {
            $tabel->decimal('NilaiRefund', 18, 2)->default(0)->after('Total');
            $tabel->foreignId('IdAkunRefund')->nullable()->after('NilaiRefund')->constrained('Akun', 'Id', 'FkReturGrosirIdAkunRefund')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ReturGrosir', function (Blueprint $tabel): void {
            $tabel->dropForeign('FkReturGrosirIdAkunRefund');
            $tabel->dropColumn(['NilaiRefund', 'IdAkunRefund']);
        });
    }
};
