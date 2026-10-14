<?php

declare(strict_types=1);

use App\Domain\Organisasi\Enum\IzinTenant;
use App\Http\Kontroler\Kelola\Penjualan\FakturPajakPenjualanKontroler;
use App\Http\Kontroler\Kelola\Penjualan\PenjualanKontroler;
use App\Http\Kontroler\Kelola\Penjualan\PesananPenjualanKontroler;
use App\Http\Perantara\EksporDiLatarBelakang;
use App\Http\Perantara\SiapkanAuditTenant;
use App\Http\Perantara\WajibIzinTenant;
use Illuminate\Support\Facades\Route;

/*
 * Rute back-office F-07b penjualan & F-09 void/retur (PRD §13.6, D-06), baca saja. Didaftarkan dari routes/web.php di dalam grup
 * `/kelola`. Parameter berpola ULID dan dicari lewat `MilikTenant` (milik tenant lain atau outlet di luar akses = 404).
 */

$lihat = WajibIzinTenant::class.':'.IzinTenant::LaporanPenjualanLihat->value;
$ulid = '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}';

Route::middleware([SiapkanAuditTenant::class, $lihat])->group(function () use ($ulid): void {
    Route::get('/penjualan', [PenjualanKontroler::class, 'Daftar'])->name('kelola.penjualan.daftar');
    Route::get('/penjualan/ekspor', [PenjualanKontroler::class, 'Ekspor'])->middleware('throttle:10,1')->middleware(EksporDiLatarBelakang::class)->name('kelola.penjualan.ekspor');
    Route::get('/penjualan/{penjualan}', [PenjualanKontroler::class, 'Detail'])->where('penjualan', $ulid)->name('kelola.penjualan.detail');
    // F-09: daftar void & retur (dasar laporan anti-fraud BR-09.3) dan detail retur.
    Route::get('/penjualan/void-retur', [PenjualanKontroler::class, 'VoidRetur'])->name('kelola.penjualan.void-retur');
    Route::get('/penjualan/retur/{retur}', [PenjualanKontroler::class, 'DetailRetur'])->where('retur', $ulid)->name('kelola.penjualan.retur.detail');
    // F-12 bagian 2: pre-order & uang muka (lihat: laporan.penjualan.lihat; tandai siap: penjualan.buat; batal &
    // selesaikan sisa DP: akuntansi.kelola).
    Route::get('/pre-order', [PesananPenjualanKontroler::class, 'Daftar'])->name('kelola.pre-order.daftar');
    Route::get('/pre-order/{pesananPenjualan}', [PesananPenjualanKontroler::class, 'Detail'])->where('pesananPenjualan', $ulid)->name('kelola.pre-order.detail');
    Route::post('/pre-order/{pesananPenjualan}/siap', [PesananPenjualanKontroler::class, 'Siap'])
        ->middleware(WajibIzinTenant::class.':'.IzinTenant::PenjualanBuat->value)->where('pesananPenjualan', $ulid)->name('kelola.pre-order.siap');
    Route::post('/pre-order/{pesananPenjualan}/selesaikan-uang-muka', [PesananPenjualanKontroler::class, 'Selesaikan'])
        ->middleware(WajibIzinTenant::class.':'.IzinTenant::AkuntansiKelola->value)->where('pesananPenjualan', $ulid)->name('kelola.pre-order.selesaikan-uang-muka');
});

// TAX-07: Faktur Pajak per transaksi kasir atas permintaan pembeli ber-NPWP/NIK (izin akuntansi.kelola, termasuk pajak).
Route::middleware([SiapkanAuditTenant::class, WajibIzinTenant::class.':'.IzinTenant::AkuntansiKelola->value])->group(function () use ($ulid): void {
    Route::get('/penjualan/faktur-pajak/pelanggan', [FakturPajakPenjualanKontroler::class, 'CariPelanggan'])->name('kelola.penjualan.faktur-pajak.pelanggan');
    Route::post('/penjualan/{penjualan}/faktur-pajak', [FakturPajakPenjualanKontroler::class, 'Terbitkan'])->where('penjualan', $ulid)->middleware('throttle:60,1')->name('kelola.penjualan.faktur-pajak.terbitkan');
    Route::put('/penjualan/{penjualan}/faktur-pajak/{faktur}/nomor', [FakturPajakPenjualanKontroler::class, 'UbahNomor'])->where(['penjualan' => $ulid, 'faktur' => $ulid])->name('kelola.penjualan.faktur-pajak.nomor');
    Route::post('/penjualan/{penjualan}/faktur-pajak/{faktur}/batalkan', [FakturPajakPenjualanKontroler::class, 'Batalkan'])->where(['penjualan' => $ulid, 'faktur' => $ulid])->name('kelola.penjualan.faktur-pajak.batalkan');
});
