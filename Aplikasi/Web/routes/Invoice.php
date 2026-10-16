<?php

declare(strict_types=1);

use App\Domain\Organisasi\Enum\IzinTenant;
use App\Http\Kontroler\Kelola\Invoice\InvoiceBerulangKontroler;
use App\Http\Kontroler\Kelola\Invoice\InvoiceKontroler;
use App\Http\Perantara\EksporDiLatarBelakang;
use App\Http\Perantara\SiapkanAuditTenant;
use App\Http\Perantara\WajibIzinTenant;
use Illuminate\Support\Facades\Route;

/*
 * Rute back-office invoice (F-21, D-166): daftar, form draf, detail, terbit, batal, pembayaran, kirim WhatsApp, cetak A4,
 * dan pencarian untuk formulir. Didaftarkan dari routes/web.php di dalam grup `/kelola` (auth + IdentifikasiTenantSesi …
 * BatasiTenantDitangguhkan). Semua rute memakai `SiapkanAuditTenant` dan izin `invoice.kelola`. Parameter dokumen dibatasi
 * pola ULID dan dicari lewat `MilikTenant` + batas outlet di kontroler (lainnya = 404).
 */

$ulid = '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}';

Route::middleware([SiapkanAuditTenant::class, WajibIzinTenant::class.':'.IzinTenant::InvoiceKelola->value])->prefix('invoice')->group(function () use ($ulid): void {
    Route::get('/produk/cari', [InvoiceKontroler::class, 'CariProduk'])->name('kelola.invoice.produk.cari');
    Route::get('/pelanggan/cari', [InvoiceKontroler::class, 'CariPelanggan'])->name('kelola.invoice.pelanggan.cari');
    Route::get('/ekspor', [InvoiceKontroler::class, 'Ekspor'])->middleware(EksporDiLatarBelakang::class)->name('kelola.invoice.ekspor');
    Route::get('/uang-muka', [InvoiceKontroler::class, 'UangMukaTersedia'])->name('kelola.invoice.uang-muka');

    // Invoice berulang: templat yang menerbitkan invoice biasa tiap periode.
    Route::get('/berulang', [InvoiceBerulangKontroler::class, 'Daftar'])->name('kelola.invoice.berulang.daftar');
    Route::get('/berulang/buat', [InvoiceBerulangKontroler::class, 'Buat'])->name('kelola.invoice.berulang.buat');
    Route::post('/berulang', [InvoiceBerulangKontroler::class, 'Simpan'])->name('kelola.invoice.berulang.simpan');
    Route::get('/berulang/{templat}', [InvoiceBerulangKontroler::class, 'Detail'])->where('templat', $ulid)->name('kelola.invoice.berulang.detail');
    Route::get('/berulang/{templat}/ubah', [InvoiceBerulangKontroler::class, 'Ubah'])->where('templat', $ulid)->name('kelola.invoice.berulang.ubah');
    Route::put('/berulang/{templat}', [InvoiceBerulangKontroler::class, 'Perbarui'])->where('templat', $ulid)->name('kelola.invoice.berulang.perbarui');
    Route::post('/berulang/{templat}/jeda', [InvoiceBerulangKontroler::class, 'Jeda'])->where('templat', $ulid)->name('kelola.invoice.berulang.jeda');
    Route::post('/berulang/{templat}/lanjut', [InvoiceBerulangKontroler::class, 'Lanjut'])->where('templat', $ulid)->name('kelola.invoice.berulang.lanjut');
    Route::post('/berulang/{templat}/hentikan', [InvoiceBerulangKontroler::class, 'Hentikan'])->where('templat', $ulid)->name('kelola.invoice.berulang.hentikan');

    Route::get('/', [InvoiceKontroler::class, 'Daftar'])->name('kelola.invoice.daftar');
    Route::get('/buat', [InvoiceKontroler::class, 'Buat'])->name('kelola.invoice.buat');
    Route::post('/', [InvoiceKontroler::class, 'Simpan'])->name('kelola.invoice.simpan');
    Route::post('/massal/{aksi}', [InvoiceKontroler::class, 'ProsesMassal'])->where('aksi', 'terbitkan|kirim|ingatkan')->name('kelola.invoice.massal');
    Route::get('/{invoice}', [InvoiceKontroler::class, 'Detail'])->where('invoice', $ulid)->name('kelola.invoice.detail');
    Route::get('/{invoice}/ubah', [InvoiceKontroler::class, 'Ubah'])->where('invoice', $ulid)->name('kelola.invoice.ubah');
    Route::put('/{invoice}', [InvoiceKontroler::class, 'Perbarui'])->where('invoice', $ulid)->name('kelola.invoice.perbarui');
    Route::get('/{invoice}/cetak', [InvoiceKontroler::class, 'Cetak'])->where('invoice', $ulid)->name('kelola.invoice.cetak');
    Route::post('/{invoice}/terbitkan', [InvoiceKontroler::class, 'TerbitkanDraf'])->where('invoice', $ulid)->name('kelola.invoice.terbitkan');
    Route::post('/{invoice}/batalkan', [InvoiceKontroler::class, 'Batalkan'])->where('invoice', $ulid)->name('kelola.invoice.batalkan');
    Route::post('/{invoice}/pembayaran', [InvoiceKontroler::class, 'CatatPembayaran'])->where('invoice', $ulid)->name('kelola.invoice.pembayaran');
    Route::post('/{invoice}/kirim', [InvoiceKontroler::class, 'Kirim'])->where('invoice', $ulid)->name('kelola.invoice.kirim');
});
