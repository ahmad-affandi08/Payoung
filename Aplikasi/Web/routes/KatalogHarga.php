<?php

declare(strict_types=1);

use App\Domain\Organisasi\Enum\IzinTenant;
use App\Http\Kontroler\Kelola\Katalog\DaftarHargaKontroler;
use App\Http\Kontroler\Kelola\Katalog\HargaProdukKontroler;
use App\Http\Kontroler\Kelola\Katalog\KelompokPajakKontroler;
use App\Http\Perantara\SiapkanAuditTenant;
use App\Http\Perantara\WajibIzinTenant;
use Illuminate\Support\Facades\Route;

/*
 * Rute back-office F-03 Tim 2 Harga & Pajak (harga produk, daftar harga, kelompok pajak), PRD §13.6, D-06,
 * DesainF03 D.1. Didaftarkan dari routes/web.php di dalam grup `/kelola` (auth + IdentifikasiTenantSesi …
 * BatasiTenantDitangguhkan). Rute memakai `SiapkanAuditTenant` dan izin lewat `$izin`. Parameter ULID dibatasi pola
 * ULID. Rute POS katalog berada di routes/Pos.php.
 */

$izin = static fn (IzinTenant $izin): string => WajibIzinTenant::class.':'.$izin->value;
$ulid = '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}';

Route::middleware(SiapkanAuditTenant::class)->group(function () use ($izin, $ulid): void {
    $lihat = $izin(IzinTenant::ProdukLihat);
    $ubahHarga = $izin(IzinTenant::ProdukHargaUbah);
    $kelolaPajak = $izin(IzinTenant::AkuntansiKelola);

    // Harga produk (E.6).
    Route::get('/produk/{produk}/harga', [HargaProdukKontroler::class, 'Tampilkan'])->middleware($lihat)->where('produk', $ulid)->name('kelola.produk.harga');
    Route::put('/produk/{produk}/harga', [HargaProdukKontroler::class, 'Simpan'])->middleware($ubahHarga)->where('produk', $ulid)->name('kelola.produk.harga.simpan');
    Route::put('/produk/{produk}/harga/daftar-harga/{daftarHarga}', [HargaProdukKontroler::class, 'SimpanDaftarHarga'])
        ->middleware($ubahHarga)->where(['produk' => $ulid, 'daftarHarga' => $ulid])->name('kelola.produk.harga.daftar-harga.simpan');

    // Daftar harga (E.7).
    Route::get('/daftar-harga', [DaftarHargaKontroler::class, 'Daftar'])->middleware($lihat)->name('kelola.daftar-harga.daftar');
    Route::get('/daftar-harga/buat', [DaftarHargaKontroler::class, 'Buat'])->middleware($ubahHarga)->name('kelola.daftar-harga.buat');
    Route::post('/daftar-harga', [DaftarHargaKontroler::class, 'Simpan'])->middleware($ubahHarga)->name('kelola.daftar-harga.simpan');
    // "Atur harga ojol": isi daftar harga GoFood/GrabFood/ShopeeFood dari harga dasar (pratinjau lalu terapkan).
    Route::post('/daftar-harga/atur-kanal/pratinjau', [DaftarHargaKontroler::class, 'PratinjauHargaKanal'])->middleware($ubahHarga)->name('kelola.daftar-harga.atur-kanal.pratinjau');
    Route::post('/daftar-harga/atur-kanal', [DaftarHargaKontroler::class, 'TerapkanHargaKanal'])->middleware($ubahHarga)->name('kelola.daftar-harga.atur-kanal');
    Route::get('/daftar-harga/{daftarHarga}', [DaftarHargaKontroler::class, 'Detail'])->middleware($lihat)->where('daftarHarga', $ulid)->name('kelola.daftar-harga.detail');
    Route::put('/daftar-harga/{daftarHarga}', [DaftarHargaKontroler::class, 'Ubah'])->middleware($ubahHarga)->where('daftarHarga', $ulid)->name('kelola.daftar-harga.ubah');
    Route::post('/daftar-harga/{daftarHarga}/nonaktifkan', [DaftarHargaKontroler::class, 'Nonaktifkan'])->middleware($ubahHarga)->where('daftarHarga', $ulid)->name('kelola.daftar-harga.nonaktifkan');
    Route::post('/daftar-harga/{daftarHarga}/aktifkan', [DaftarHargaKontroler::class, 'Aktifkan'])->middleware($ubahHarga)->where('daftarHarga', $ulid)->name('kelola.daftar-harga.aktifkan');
    Route::put('/daftar-harga/{daftarHarga}/harga', [DaftarHargaKontroler::class, 'SimpanHarga'])->middleware($ubahHarga)->where('daftarHarga', $ulid)->name('kelola.daftar-harga.harga.simpan');

    // Kelompok pajak (E.8): lihat = produk.lihat, ubah = akuntansi.kelola (§19.1 Akuntan = pajak).
    Route::get('/kelompok-pajak', [KelompokPajakKontroler::class, 'Daftar'])->middleware($lihat)->name('kelola.kelompok-pajak.daftar');
    Route::get('/kelompok-pajak/buat', [KelompokPajakKontroler::class, 'Buat'])->middleware($kelolaPajak)->name('kelola.kelompok-pajak.buat');
    Route::post('/kelompok-pajak', [KelompokPajakKontroler::class, 'Simpan'])->middleware($kelolaPajak)->name('kelola.kelompok-pajak.simpan');
    Route::put('/kelompok-pajak/{kelompokPajak}', [KelompokPajakKontroler::class, 'Ubah'])->middleware($kelolaPajak)->where('kelompokPajak', $ulid)->name('kelola.kelompok-pajak.ubah');
});
