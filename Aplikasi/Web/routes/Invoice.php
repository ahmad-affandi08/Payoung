<?php

declare(strict_types=1);

use App\Domain\Organisasi\Enum\IzinTenant;
use App\Http\Kontroler\Kelola\Invoice\InvoiceKontroler;
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
    Route::get('/uang-muka', [InvoiceKontroler::class, 'UangMukaTersedia'])->name('kelola.invoice.uang-muka');

    Route::get('/', [InvoiceKontroler::class, 'Daftar'])->name('kelola.invoice.daftar');
    Route::get('/buat', [InvoiceKontroler::class, 'Buat'])->name('kelola.invoice.buat');
    Route::post('/', [InvoiceKontroler::class, 'Simpan'])->name('kelola.invoice.simpan');
    Route::get('/{invoice}', [InvoiceKontroler::class, 'Detail'])->where('invoice', $ulid)->name('kelola.invoice.detail');
    Route::get('/{invoice}/ubah', [InvoiceKontroler::class, 'Ubah'])->where('invoice', $ulid)->name('kelola.invoice.ubah');
    Route::put('/{invoice}', [InvoiceKontroler::class, 'Perbarui'])->where('invoice', $ulid)->name('kelola.invoice.perbarui');
    Route::get('/{invoice}/cetak', [InvoiceKontroler::class, 'Cetak'])->where('invoice', $ulid)->name('kelola.invoice.cetak');
    Route::post('/{invoice}/terbitkan', [InvoiceKontroler::class, 'TerbitkanDraf'])->where('invoice', $ulid)->name('kelola.invoice.terbitkan');
    Route::post('/{invoice}/batalkan', [InvoiceKontroler::class, 'Batalkan'])->where('invoice', $ulid)->name('kelola.invoice.batalkan');
    Route::post('/{invoice}/pembayaran', [InvoiceKontroler::class, 'CatatPembayaran'])->where('invoice', $ulid)->name('kelola.invoice.pembayaran');
    Route::post('/{invoice}/kirim', [InvoiceKontroler::class, 'Kirim'])->where('invoice', $ulid)->name('kelola.invoice.kirim');
});
