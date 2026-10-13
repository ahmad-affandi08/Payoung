<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Price engine lapis 1 (PRD §F-03 "Harga manual kasir, butuh izin `pos.harga.timpa`"). Selama ini harga baris datang apa
 * adanya dari perangkat tanpa jejak bahwa kasir mengubahnya. Sekarang baris `Penjualan.Buat` yang harganya diubah kasir
 * membawa `HargaManual {HargaAsli, Alasan}` + `UuidPenyetujuHarga` (PIN supervisor), dan server menyimpannya:
 * - `PenjualanDetail.HargaAsli` = harga sebelum diubah (null = harga mengikuti price engine) dan `AlasanHargaManual`;
 * - `Penjualan.IdPenyetujuHarga` = pengguna yang menyetujui perubahan harga bila kasirnya tanpa izin.
 * Harga yang dipakai tetap `HargaSatuan` (snapshot transaksi); kolom baru hanya jejak untuk audit & laporan.
 *
 * Izin `pos.harga.timpa` diberikan ke setiap peran yang sekarang bisa menyetujui diskon (Supervisor, Manajer Outlet, peran
 * kustom serupa), supaya tidak ada yang kehilangan wewenang menentukan harga; pemilik bisa mencabutnya di halaman peran.
 * Expand-only: kolom nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('PenjualanDetail', function (Blueprint $tabel): void {
            $tabel->decimal('HargaAsli', 18, 2)->nullable();
            $tabel->string('AlasanHargaManual', 100)->nullable();
        });
        Schema::table('Penjualan', function (Blueprint $tabel): void {
            $tabel->foreignId('IdPenyetujuHarga')->nullable()->constrained('Pengguna', 'Id', 'FkPenjualanIdPenyetujuHarga')->restrictOnDelete();
        });

        DB::table('PeranIzin')->insertUsing(
            ['IdTenant', 'IdPeran', 'KunciIzin'],
            DB::table('PeranIzin', 'Setujui')
                ->select(['Setujui.IdTenant', 'Setujui.IdPeran'])
                ->selectRaw("'pos.harga.timpa'")
                ->where('Setujui.KunciIzin', 'penjualan.diskon.setujui')
                ->whereNotExists(fn (Builder $kueri) => $kueri->from('PeranIzin', 'Timpa')
                    ->whereColumn('Timpa.IdPeran', 'Setujui.IdPeran')
                    ->where('Timpa.KunciIzin', 'pos.harga.timpa')),
        );
    }

    public function down(): void
    {
        DB::table('PeranIzin')->where('KunciIzin', 'pos.harga.timpa')->delete();
        Schema::table('Penjualan', function (Blueprint $tabel): void {
            $tabel->dropForeign('FkPenjualanIdPenyetujuHarga');
            $tabel->dropColumn('IdPenyetujuHarga');
        });
        Schema::table('PenjualanDetail', function (Blueprint $tabel): void {
            $tabel->dropColumn(['HargaAsli', 'AlasanHargaManual']);
        });
    }
};
