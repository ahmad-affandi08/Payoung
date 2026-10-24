<?php

declare(strict_types=1);

use App\Domain\Organisasi\Enum\IzinTenant;
use App\Http\Kontroler\Kelola\PanduanAwalKontroler;
use App\Http\Kontroler\Kelola\PanduanAwalMetodePembayaranKontroler;
use App\Http\Kontroler\Kelola\PanduanAwalPerangkatKontroler;
use App\Http\Kontroler\Kelola\PanduanAwalProdukKontroler;
use App\Http\Perantara\SiapkanAuditTenant;
use App\Http\Perantara\WajibIzinTenant;
use Illuminate\Support\Facades\Route;

/*
 * Rute back-office F-01 Panduan awal (onboarding wizard), PRD §13.6, D-06. Didaftarkan dari routes/web.php di dalam
 * grup `/kelola` (auth + IdentifikasiTenantSesi … BatasiTenantDitangguhkan). Semua rute butuh izin
 * `panduan-awal.kelola`; perangkat kasir juga butuh `perangkat.kelola`. ID di URL adalah ULID publik yang dicari di
 * dalam scope tenant aktif (bukan route model binding). Simpan memakai POST karena bisa multipart.
 */

$izin = static fn (IzinTenant $izin): string => WajibIzinTenant::class.':'.$izin->value;

Route::middleware([SiapkanAuditTenant::class, $izin(IzinTenant::PanduanAwalKelola)])->prefix('panduan-awal')->group(function () use ($izin): void {
    Route::get('/', [PanduanAwalKontroler::class, 'Indeks'])->name('kelola.panduan-awal');

    Route::get('/profil-usaha', [PanduanAwalKontroler::class, 'TampilkanProfilUsaha'])->name('kelola.panduan-awal.profil-usaha');
    Route::post('/profil-usaha', [PanduanAwalKontroler::class, 'SimpanProfilUsaha'])->name('kelola.panduan-awal.profil-usaha.simpan');
    Route::get('/profil-usaha/logo', [PanduanAwalKontroler::class, 'UnduhLogo'])->name('kelola.panduan-awal.profil-usaha.logo');

    Route::get('/sektor', [PanduanAwalKontroler::class, 'TampilkanSektor'])->name('kelola.panduan-awal.sektor');
    Route::post('/sektor', [PanduanAwalKontroler::class, 'TerapkanSektor'])->name('kelola.panduan-awal.sektor.terapkan');
    Route::post('/sektor/siapkan-otomatis', [PanduanAwalKontroler::class, 'SiapkanOtomatis'])->name('kelola.panduan-awal.sektor.siapkan-otomatis');

    Route::get('/pajak', [PanduanAwalKontroler::class, 'TampilkanPajak'])->name('kelola.panduan-awal.pajak');
    Route::post('/pajak', [PanduanAwalKontroler::class, 'SimpanPajak'])->name('kelola.panduan-awal.pajak.simpan');

    Route::get('/produk', [PanduanAwalProdukKontroler::class, 'Tampilkan'])->name('kelola.panduan-awal.produk');
    Route::post('/produk/contoh', [PanduanAwalProdukKontroler::class, 'SimpanContoh'])->name('kelola.panduan-awal.produk.contoh.simpan');
    Route::post('/produk', [PanduanAwalProdukKontroler::class, 'Simpan'])->name('kelola.panduan-awal.produk.simpan');

    Route::get('/metode-pembayaran', [PanduanAwalMetodePembayaranKontroler::class, 'Tampilkan'])->name('kelola.panduan-awal.metode-pembayaran');
    Route::post('/metode-pembayaran', [PanduanAwalMetodePembayaranKontroler::class, 'Simpan'])->name('kelola.panduan-awal.metode-pembayaran.simpan');
    Route::post('/metode-pembayaran/{metodePembayaran}/nonaktifkan', [PanduanAwalMetodePembayaranKontroler::class, 'Nonaktifkan'])->name('kelola.panduan-awal.metode-pembayaran.nonaktifkan');
    Route::post('/metode-pembayaran/{metodePembayaran}/batas-hari-menunggu', [PanduanAwalMetodePembayaranKontroler::class, 'UbahBatasHariMenunggu'])->name('kelola.panduan-awal.metode-pembayaran.batas-hari-menunggu');
    Route::post('/metode-pembayaran/{metodePembayaran}/teks-qris', [PanduanAwalMetodePembayaranKontroler::class, 'AturTeksQris'])->name('kelola.panduan-awal.metode-pembayaran.teks-qris');
    Route::post('/metode-pembayaran/{metodePembayaran}/aktifkan', [PanduanAwalMetodePembayaranKontroler::class, 'Aktifkan'])->name('kelola.panduan-awal.metode-pembayaran.aktifkan');
    Route::get('/metode-pembayaran/{metodePembayaran}/gambar-qris', [PanduanAwalMetodePembayaranKontroler::class, 'UnduhGambarQris'])->name('kelola.panduan-awal.metode-pembayaran.gambar-qris');

    Route::get('/perangkat', [PanduanAwalPerangkatKontroler::class, 'Tampilkan'])->name('kelola.panduan-awal.perangkat');
    Route::post('/perangkat', [PanduanAwalPerangkatKontroler::class, 'Simpan'])->middleware($izin(IzinTenant::PerangkatKelola))->name('kelola.panduan-awal.perangkat.simpan');
    Route::post('/perangkat/{perangkat}/kode-aktivasi', [PanduanAwalPerangkatKontroler::class, 'BuatKodeAktivasi'])->middleware($izin(IzinTenant::PerangkatKelola))->name('kelola.panduan-awal.perangkat.kode-aktivasi.buat');

    Route::post('/langkah/{langkah}/lewati', [PanduanAwalKontroler::class, 'Lewati'])->name('kelola.panduan-awal.langkah.lewati');
    Route::post('/langkah/{langkah}/selesai', [PanduanAwalKontroler::class, 'TandaiSelesai'])->name('kelola.panduan-awal.langkah.selesai');
    Route::post('/selesai', [PanduanAwalKontroler::class, 'Selesaikan'])->name('kelola.panduan-awal.selesai');
});
