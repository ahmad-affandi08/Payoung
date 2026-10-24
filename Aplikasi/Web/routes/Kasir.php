<?php

declare(strict_types=1);

use App\Domain\Lisensi\Enum\EdisiAplikasi;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Http\Kontroler\Kelola\Kasir\KategoriKasKontroler;
use App\Http\Kontroler\Kelola\Kasir\PengaturanKasirKontroler;
use App\Http\Kontroler\Kelola\Kasir\PengaturanStrukKontroler;
use App\Http\Kontroler\Kelola\Kasir\ShiftKontroler;
use App\Http\Kontroler\Kelola\Kasir\TutupHarianKontroler;
use App\Http\Kontroler\Kelola\Pembayaran\AktivasiQrisKontroler;
use App\Http\Kontroler\Kelola\Pembayaran\GerbangPembayaranKontroler;
use App\Http\Perantara\AktivasiQrisHanyaBilaDibuka;
use App\Http\Perantara\GerbangTokoHanyaEdisiLisensi;
use App\Http\Perantara\SiapkanAuditTenant;
use App\Http\Perantara\WajibIzinTenant;
use Illuminate\Support\Facades\Route;

/*
 * Rute back-office F-06 shift & kas dan gerbang pembayaran tenant (PRD §13.6, D-06). Didaftarkan dari routes/web.php di dalam grup `/kelola`.
 * Parameter berpola ULID dan dicari lewat `MilikTenant` (milik tenant lain atau outlet di luar akses = 404).
 */

$izin = static fn (IzinTenant $izin): string => WajibIzinTenant::class.':'.$izin->value;
$ulid = '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}';

Route::middleware(SiapkanAuditTenant::class)->group(function () use ($izin, $ulid): void {
    $lihat = $izin(IzinTenant::LaporanPenjualanLihat);
    $akuntansi = $izin(IzinTenant::AkuntansiKelola);
    $outlet = $izin(IzinTenant::OutletKelola);

    Route::get('/kasir/shift', [ShiftKontroler::class, 'Daftar'])->middleware($lihat)->name('kelola.kasir.shift.daftar');
    Route::get('/kasir/shift/{shift}', [ShiftKontroler::class, 'Detail'])->middleware($lihat)->where('shift', $ulid)->name('kelola.kasir.shift.detail');
    Route::post('/kasir/shift/{shift}/tutup-paksa', [ShiftKontroler::class, 'TutupPaksa'])->middleware($izin(IzinTenant::ShiftSelisihSetujui))->where('shift', $ulid)->name('kelola.kasir.shift.tutup-paksa');
    Route::get('/kasir/mutasi-kas/{mutasiKas}', [ShiftKontroler::class, 'MutasiKas'])->middleware($lihat)->where('mutasiKas', $ulid)->name('kelola.kasir.mutasi-kas');
    Route::get('/kasir/mutasi-kas/{mutasiKas}/bukti', [ShiftKontroler::class, 'BuktiKas'])->middleware($lihat)->where('mutasiKas', $ulid)->name('kelola.kasir.mutasi-kas.bukti');

    // F-15: tutup harian (End of Day) per outlet; menutup hari = tutup buku (`akuntansi.kelola`).
    Route::get('/kasir/tutup-harian', [TutupHarianKontroler::class, 'Tampilkan'])->middleware($lihat)->name('kelola.kasir.tutup-harian');
    Route::post('/kasir/tutup-harian', [TutupHarianKontroler::class, 'Tutup'])->middleware($akuntansi)->name('kelola.kasir.tutup-harian.tutup');

    Route::get('/kasir/kategori-kas', [KategoriKasKontroler::class, 'Daftar'])->middleware($akuntansi)->name('kelola.kasir.kategori-kas');
    Route::post('/kasir/kategori-kas', [KategoriKasKontroler::class, 'Simpan'])->middleware($akuntansi)->name('kelola.kasir.kategori-kas.simpan');
    Route::post('/kasir/kategori-kas/biaya-umum', [KategoriKasKontroler::class, 'SiapkanBiayaUmum'])->middleware($akuntansi)->name('kelola.kasir.kategori-kas.biaya-umum');
    Route::put('/kasir/kategori-kas/{kategoriKas}', [KategoriKasKontroler::class, 'Perbarui'])->middleware($akuntansi)->where('kategoriKas', $ulid)->name('kelola.kasir.kategori-kas.perbarui');
    Route::put('/kasir/kategori-kas/{kategoriKas}/status', [KategoriKasKontroler::class, 'UbahStatus'])->middleware($akuntansi)->where('kategoriKas', $ulid)->name('kelola.kasir.kategori-kas.status');

    Route::get('/kasir/pengaturan', [PengaturanKasirKontroler::class, 'Tampilkan'])->middleware($outlet)->name('kelola.kasir.pengaturan');
    Route::put('/kasir/pengaturan', [PengaturanKasirKontroler::class, 'Simpan'])->middleware($outlet)->name('kelola.kasir.pengaturan.simpan');
    Route::put('/kasir/pengaturan/barcode-timbangan', [PengaturanKasirKontroler::class, 'SimpanBarcodeTimbangan'])->middleware($outlet)->name('kelola.kasir.pengaturan.barcode-timbangan');

    // PLT-06 / POS-11 (PRD v1.79): pengaturan struk satu untuk semua outlet.
    Route::get('/kasir/struk', [PengaturanStrukKontroler::class, 'Tampilkan'])->middleware($outlet)->name('kelola.kasir.struk');
    Route::get('/kasir/struk/logo', [PengaturanStrukKontroler::class, 'UnduhLogo'])->middleware($outlet)->name('kelola.kasir.struk.logo');
    Route::put('/kasir/struk', [PengaturanStrukKontroler::class, 'Simpan'])->middleware($outlet)->name('kelola.kasir.struk.simpan');

    // F-08 / P-05 v2.06: gerbang pembayaran QRIS dinamis milik tenant (akun merchant sendiri). D-162: tenant SaaS tidak
    // lagi diminta mendaftar di dasbor DOKU; mereka memakai Aktivasi QRIS (foto KTP) di bawah, jadi halaman & aksi ini
    // hanya hidup di edisi Lisensi (di SaaS GET dialihkan ke Aktivasi QRIS, aksi tulis 404).
    Route::middleware([$izin(IzinTenant::PembayaranGerbangAtur), GerbangTokoHanyaEdisiLisensi::class])->prefix('/pembayaran/gerbang')->group(function (): void {
        Route::get('/', [GerbangPembayaranKontroler::class, 'Tampilkan'])->name('kelola.pembayaran.gerbang');
        Route::post('/', [GerbangPembayaranKontroler::class, 'Simpan'])->name('kelola.pembayaran.gerbang.simpan');
        Route::post('/uji', [GerbangPembayaranKontroler::class, 'Uji'])->name('kelola.pembayaran.gerbang.uji');
        Route::post('/aktifkan', [GerbangPembayaranKontroler::class, 'Aktifkan'])->name('kelola.pembayaran.gerbang.aktifkan');
        Route::post('/nonaktifkan', [GerbangPembayaranKontroler::class, 'Nonaktifkan'])->name('kelola.pembayaran.gerbang.nonaktifkan');
    });

    // Aktivasi QRIS otomatis lewat DOKU Partner API (KYB): hanya edisi SaaS, karena butuh akun Partner milik Payoung.
    // D-190: disembunyikan (GET dialihkan ke Metode pembayaran, aksi tulis 404) sampai `integrasi.AktivasiQrisTampil` dinyalakan.
    if (! EdisiAplikasi::CekLisensi()) {
        Route::middleware([$izin(IzinTenant::PembayaranGerbangAtur), AktivasiQrisHanyaBilaDibuka::class])->prefix('/pembayaran/aktivasi-qris')->group(function (): void {
            Route::get('/', [AktivasiQrisKontroler::class, 'Tampilkan'])->name('kelola.pembayaran.aktivasi-qris');
            Route::post('/draf', [AktivasiQrisKontroler::class, 'SimpanDraf'])->middleware('throttle:20,1')->name('kelola.pembayaran.aktivasi-qris.draf');
            Route::post('/kirim', [AktivasiQrisKontroler::class, 'Kirim'])->middleware('throttle:10,1')->name('kelola.pembayaran.aktivasi-qris.kirim');
            Route::post('/batal', [AktivasiQrisKontroler::class, 'Batal'])->name('kelola.pembayaran.aktivasi-qris.batal');
        });
    }
});
