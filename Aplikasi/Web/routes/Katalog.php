<?php

declare(strict_types=1);

use App\Domain\Organisasi\Enum\IzinTenant;
use App\Http\Kontroler\Kelola\Katalog\BatasStokProdukKontroler;
use App\Http\Kontroler\Kelola\Katalog\CariProdukKontroler;
use App\Http\Kontroler\Kelola\Katalog\GambarProdukKontroler;
use App\Http\Kontroler\Kelola\Katalog\KategoriKontroler;
use App\Http\Kontroler\Kelola\Katalog\KetersediaanProdukKontroler;
use App\Http\Kontroler\Kelola\Katalog\LabelProdukKontroler;
use App\Http\Kontroler\Kelola\Katalog\PaketSesiKontroler;
use App\Http\Kontroler\Kelola\Katalog\ProdukKontroler;
use App\Http\Kontroler\Kelola\Katalog\SatuanKontroler;
use App\Http\Kontroler\Kelola\Katalog\StasiunDapurKontroler;
use App\Http\Kontroler\Kelola\Katalog\VarianProdukKontroler;
use App\Http\Perantara\SiapkanAuditTenant;
use App\Http\Perantara\WajibIzinTenant;
use Illuminate\Support\Facades\Route;

/*
 * Rute back-office F-03 Tim 1 Katalog Inti (produk, kategori, satuan, varian, gambar, barcode internal, batas stok),
 * PRD §13.6, D-06, DesainF03 D.1. Didaftarkan dari routes/web.php di dalam grup `/kelola` (auth +
 * IdentifikasiTenantSesi … BatasiTenantDitangguhkan). Rute memakai `SiapkanAuditTenant` dan izin lewat `$izin`.
 * Parameter `{produk}` dan ULID lain dibatasi pola ULID agar `/kelola/produk/buat`, `/cari`, `/impor`, `/ekspor`
 * tidak bentrok.
 */

$izin = static fn (IzinTenant $izin): string => WajibIzinTenant::class.':'.$izin->value;
$ulid = '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}';

Route::middleware(SiapkanAuditTenant::class)->group(function () use ($izin, $ulid): void {
    $lihat = $izin(IzinTenant::ProdukLihat);
    $kelola = $izin(IzinTenant::ProdukKelola);

    // F-16d bagian 2: master paket sesi (produk Jasa yang dijual sebagai N sesi).
    Route::get('/paket-sesi', [PaketSesiKontroler::class, 'Daftar'])->middleware($lihat)->name('kelola.paket-sesi.daftar');
    Route::get('/paket-sesi/buat', [PaketSesiKontroler::class, 'Buat'])->middleware($kelola)->name('kelola.paket-sesi.buat');
    Route::post('/paket-sesi', [PaketSesiKontroler::class, 'Simpan'])->middleware($kelola)->name('kelola.paket-sesi.simpan');
    Route::get('/paket-sesi/{paketSesi}/ubah', [PaketSesiKontroler::class, 'Ubah'])->middleware($kelola)->where('paketSesi', $ulid)->name('kelola.paket-sesi.ubah');
    Route::put('/paket-sesi/{paketSesi}', [PaketSesiKontroler::class, 'Perbarui'])->middleware($kelola)->where('paketSesi', $ulid)->name('kelola.paket-sesi.perbarui');

    // Produk (E.2–E.4).
    Route::get('/produk', [ProdukKontroler::class, 'Daftar'])->middleware($lihat)->name('kelola.produk.daftar');
    Route::get('/produk/buat', [ProdukKontroler::class, 'Buat'])->middleware($kelola)->name('kelola.produk.buat');
    Route::post('/produk', [ProdukKontroler::class, 'Simpan'])->middleware($kelola)->name('kelola.produk.simpan');
    Route::get('/produk/cari', [CariProdukKontroler::class, 'Cari'])->middleware($lihat)->name('kelola.produk.cari');
    // PRD-10: cetak label harga/barcode (halaman, pencarian baris, barcode internal atas pilihan sadar).
    Route::get('/produk/label', [LabelProdukKontroler::class, 'Halaman'])->middleware($lihat)->name('kelola.produk.label');
    Route::get('/produk/label/cari', [LabelProdukKontroler::class, 'Cari'])->middleware($lihat)->name('kelola.produk.label.cari');
    Route::post('/produk/label/barcode-internal', [LabelProdukKontroler::class, 'BuatBarcode'])->middleware($kelola)->name('kelola.produk.label.barcode-internal');
    Route::get('/produk/{produk}', [ProdukKontroler::class, 'Detail'])->middleware($lihat)->where('produk', $ulid)->name('kelola.produk.detail');
    Route::get('/produk/{produk}/ubah', [ProdukKontroler::class, 'Ubah'])->middleware($kelola)->where('produk', $ulid)->name('kelola.produk.ubah');
    Route::put('/produk/{produk}', [ProdukKontroler::class, 'Perbarui'])->middleware($kelola)->where('produk', $ulid)->name('kelola.produk.perbarui');
    Route::post('/produk/{produk}/habis', [KetersediaanProdukKontroler::class, 'Ubah'])->middleware($kelola)->where('produk', $ulid)->name('kelola.produk.habis');
    // Audit kemudahan pakai #19: aksi massal produk terpilih (arsip, kategori, tampil di kasir).
    Route::post('/produk/massal', [ProdukKontroler::class, 'Massal'])->middleware($kelola)->name('kelola.produk.massal');
    Route::post('/produk/harga-massal', [ProdukKontroler::class, 'HargaMassal'])->middleware($izin(IzinTenant::ProdukHargaUbah))->name('kelola.produk.harga-massal');
    Route::post('/produk/{produk}/arsipkan', [ProdukKontroler::class, 'Arsipkan'])->middleware($kelola)->where('produk', $ulid)->name('kelola.produk.arsipkan');
    Route::post('/produk/{produk}/pulihkan', [ProdukKontroler::class, 'Pulihkan'])->middleware($kelola)->where('produk', $ulid)->name('kelola.produk.pulihkan');
    Route::delete('/produk/{produk}', [ProdukKontroler::class, 'Hapus'])->middleware($kelola)->where('produk', $ulid)->name('kelola.produk.hapus');

    // Gambar, varian, barcode internal, batas stok.
    Route::get('/produk/{produk}/gambar', [GambarProdukKontroler::class, 'Unduh'])->middleware($lihat)->where('produk', $ulid)->name('kelola.produk.gambar');
    Route::post('/produk/{produk}/gambar', [GambarProdukKontroler::class, 'Simpan'])->middleware($kelola)->where('produk', $ulid)->name('kelola.produk.gambar.simpan');
    Route::delete('/produk/{produk}/gambar', [GambarProdukKontroler::class, 'Hapus'])->middleware($kelola)->where('produk', $ulid)->name('kelola.produk.gambar.hapus');
    Route::post('/produk/{produk}/varian', [VarianProdukKontroler::class, 'Generasikan'])->middleware($kelola)->where('produk', $ulid)->name('kelola.produk.varian.generasi');
    Route::put('/produk/{produk}/varian', [VarianProdukKontroler::class, 'SimpanMassal'])->middleware($kelola)->where('produk', $ulid)->name('kelola.produk.varian.simpan');
    Route::post('/produk/{produk}/varian/stok-awal', [VarianProdukKontroler::class, 'CatatStokAwal'])->middleware($kelola)->where('produk', $ulid)->name('kelola.produk.varian.stok-awal');
    Route::post('/produk/{produk}/satuan/{produkSatuan}/barcode-internal', [ProdukKontroler::class, 'BuatBarcodeInternal'])
        ->middleware($kelola)->where(['produk' => $ulid, 'produkSatuan' => $ulid])->name('kelola.produk.barcode-internal.buat');
    Route::put('/produk/{produk}/batas-stok', [BatasStokProdukKontroler::class, 'Simpan'])
        ->middleware($izin(IzinTenant::PersediaanKelola))->where('produk', $ulid)->name('kelola.produk.batas-stok.simpan');

    // Kategori & satuan (E.5).
    Route::get('/kategori', [KategoriKontroler::class, 'Daftar'])->middleware($lihat)->name('kelola.kategori.daftar');
    Route::post('/kategori', [KategoriKontroler::class, 'Simpan'])->middleware($kelola)->name('kelola.kategori.simpan');
    Route::put('/kategori/{kategori}', [KategoriKontroler::class, 'Ubah'])->middleware($kelola)->where('kategori', $ulid)->name('kelola.kategori.ubah');
    Route::delete('/kategori/{kategori}', [KategoriKontroler::class, 'Hapus'])->middleware($kelola)->where('kategori', $ulid)->name('kelola.kategori.hapus');

    // F-10a stasiun dapur (kategori → stasiun untuk KDS/printer dapur).
    Route::get('/stasiun-dapur', [StasiunDapurKontroler::class, 'Daftar'])->middleware($lihat)->name('kelola.stasiun-dapur.daftar');
    Route::post('/stasiun-dapur', [StasiunDapurKontroler::class, 'Simpan'])->middleware($kelola)->name('kelola.stasiun-dapur.simpan');
    Route::put('/stasiun-dapur/{stasiunDapur}', [StasiunDapurKontroler::class, 'Ubah'])->middleware($kelola)->where('stasiunDapur', $ulid)->name('kelola.stasiun-dapur.ubah');
    Route::post('/stasiun-dapur/{stasiunDapur}/arsipkan', [StasiunDapurKontroler::class, 'Arsipkan'])->middleware($kelola)->where('stasiunDapur', $ulid)->name('kelola.stasiun-dapur.arsipkan');
    Route::post('/stasiun-dapur/{stasiunDapur}/pulihkan', [StasiunDapurKontroler::class, 'Pulihkan'])->middleware($kelola)->where('stasiunDapur', $ulid)->name('kelola.stasiun-dapur.pulihkan');

    Route::get('/satuan', [SatuanKontroler::class, 'Daftar'])->middleware($lihat)->name('kelola.satuan.daftar');
    Route::post('/satuan', [SatuanKontroler::class, 'Simpan'])->middleware($kelola)->name('kelola.satuan.simpan');
    Route::put('/satuan/{satuan}', [SatuanKontroler::class, 'Ubah'])->middleware($kelola)->where('satuan', $ulid)->name('kelola.satuan.ubah');
    Route::delete('/satuan/{satuan}', [SatuanKontroler::class, 'Hapus'])->middleware($kelola)->where('satuan', $ulid)->name('kelola.satuan.hapus');
});
