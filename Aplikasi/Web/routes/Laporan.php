<?php

declare(strict_types=1);

use App\Domain\Organisasi\Enum\IzinTenant;
use App\Http\Kontroler\Kelola\Laporan\LaporanApotekKontroler;
use App\Http\Kontroler\Kelola\Laporan\LaporanKontroler;
use App\Http\Kontroler\Kelola\Laporan\LaporanPembelianKontroler;
use App\Http\Kontroler\Kelola\Laporan\LaporanPphDipotongKontroler;
use App\Http\Perantara\EksporDiLatarBelakang;
use App\Http\Perantara\SiapkanAuditTenant;
use App\Http\Perantara\WajibIzinTenant;
use Illuminate\Support\Facades\Route;

/*
 * Rute back-office F-14a laporan (PRD §13.6, D-06, "Rincian F-14a"), baca saja kecuali langganan insight mingguan
 * milik pelaku sendiri (X6, v3.79). Didaftarkan dari routes/web.php di dalam grup `/kelola`. Data dibatasi tenant aktif
 * (`MilikTenant`) dan outlet akses pelaku.
 */

$izin = static fn (IzinTenant $izin): string => WajibIzinTenant::class.':'.$izin->value;

Route::middleware(SiapkanAuditTenant::class)->group(function () use ($izin): void {
    Route::middleware($izin(IzinTenant::LaporanPenjualanLihat))->group(function (): void {
        Route::get('/laporan/penjualan', [LaporanKontroler::class, 'Penjualan'])->name('kelola.laporan.penjualan');
        Route::get('/laporan/penjualan/ekspor', [LaporanKontroler::class, 'EksporPenjualan'])->middleware(EksporDiLatarBelakang::class)->name('kelola.laporan.penjualan.ekspor');
        Route::put('/laporan/penjualan/insight-whatsapp', [LaporanKontroler::class, 'UbahInsightWhatsapp'])->name('kelola.laporan.penjualan.insight-whatsapp');
        // Apotek (§9.5): penjualan obat wajib resep & data pendukung SIPNAP (data pasien utuh butuh apotek.resep.lihat).
        Route::get('/laporan/apotek', [LaporanApotekKontroler::class, 'Halaman'])->name('kelola.laporan.apotek');
        Route::get('/laporan/apotek/ekspor', [LaporanApotekKontroler::class, 'EksporResep'])->name('kelola.laporan.apotek.ekspor');
        Route::get('/laporan/apotek/sipnap/ekspor', [LaporanApotekKontroler::class, 'EksporSipnap'])->name('kelola.laporan.apotek.sipnap.ekspor');
    });
    Route::middleware($izin(IzinTenant::LaporanKeuanganLihat))->group(function (): void {
        Route::get('/laporan/pajak', [LaporanKontroler::class, 'Pajak'])->name('kelola.laporan.pajak');
        Route::get('/laporan/pajak/ekspor', [LaporanKontroler::class, 'EksporPajak'])->name('kelola.laporan.pajak.ekspor');
        Route::get('/laporan/pajak/faktur-keluaran', [LaporanKontroler::class, 'RingkasFakturKeluaran'])->name('kelola.laporan.pajak.faktur-keluaran');
        Route::get('/laporan/pajak/faktur-keluaran/ekspor', [LaporanKontroler::class, 'EksporFakturKeluaran'])->name('kelola.laporan.pajak.faktur-keluaran.ekspor');
        Route::get('/laporan/pajak/nota-retur', [LaporanKontroler::class, 'RingkasNotaRetur'])->name('kelola.laporan.pajak.nota-retur');
        Route::get('/laporan/pajak/nota-retur/ekspor', [LaporanKontroler::class, 'EksporNotaRetur'])->name('kelola.laporan.pajak.nota-retur.ekspor');
        // F-21 bagian 5: PPh yang dipotong pelanggan beserta bukti potongnya (satu baris per pelunasan).
        Route::get('/laporan/pajak/pph-dipotong', [LaporanPphDipotongKontroler::class, 'Halaman'])->name('kelola.laporan.pajak.pph-dipotong');
        Route::get('/laporan/pajak/pph-dipotong/ekspor', [LaporanPphDipotongKontroler::class, 'Ekspor'])->middleware(EksporDiLatarBelakang::class)->name('kelola.laporan.pajak.pph-dipotong.ekspor');
    });
    // §10.10: pembelian per pemasok, per produk, dan PO outstanding (izin yang sama dengan menu Pembelian).
    Route::middleware($izin(IzinTenant::PembelianKelola))->group(function (): void {
        Route::get('/laporan/pembelian', [LaporanPembelianKontroler::class, 'Halaman'])->name('kelola.laporan.pembelian');
        Route::get('/laporan/pembelian/ekspor', [LaporanPembelianKontroler::class, 'Ekspor'])->middleware(EksporDiLatarBelakang::class)->name('kelola.laporan.pembelian.ekspor');
    });
    Route::middleware($izin(IzinTenant::PersediaanLihat))->group(function (): void {
        Route::get('/laporan/stok', [LaporanKontroler::class, 'Stok'])->name('kelola.laporan.stok');
        Route::get('/laporan/stok/ekspor', [LaporanKontroler::class, 'EksporStok'])->middleware(EksporDiLatarBelakang::class)->name('kelola.laporan.stok.ekspor');
    });
});
