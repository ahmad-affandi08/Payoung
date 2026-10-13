<?php

declare(strict_types=1);

use App\Domain\Organisasi\Enum\IzinTenant;
use App\Http\Kontroler\Kelola\Grosir\ArmadaKontroler;
use App\Http\Kontroler\Kelola\Grosir\FakturPenjualanKontroler;
use App\Http\Kontroler\Kelola\Grosir\KanvasKontroler;
use App\Http\Kontroler\Kelola\Grosir\KunjunganSalesKontroler;
use App\Http\Kontroler\Kelola\Grosir\PesananGrosirKontroler;
use App\Http\Kontroler\Kelola\Grosir\ProdukGrosirKontroler;
use App\Http\Kontroler\Kelola\Grosir\ReturGrosirKontroler;
use App\Http\Kontroler\Kelola\Grosir\SuratJalanKontroler;
use App\Http\Perantara\SiapkanAuditTenant;
use App\Http\Perantara\WajibIzinTenant;
use Illuminate\Support\Facades\Route;

/*
 * Rute back-office grosir (F-12, §9.7, D-32): pesanan grosir (SO), surat jalan, faktur penjualan, retur (BR-12.7),
 * kunjungan salesman (Modul Salesman, hanya baca), kanvas (Modul Salesman bagian 3: kendaraan & rekap harian; tambah
 * kendaraan = tambah outlet sehingga memakai izin `outlet.kelola`), dan halaman cetak A4 (daftar ambil barang dicetak dari SO-nya).
 * Didaftarkan dari routes/web.php di dalam grup `/kelola` (auth + IdentifikasiTenantSesi … BatasiTenantDitangguhkan). Semua rute memakai
 * `SiapkanAuditTenant` dan izin `grosir.kelola`; konfirmasi SO yang melewati limit kredit butuh `grosir.setujui-kredit`
 * (diperiksa di Aksi, BR-12.6, karena yang tahu paparannya hanya Aksi itu).
 * Parameter dokumen dibatasi pola ULID dan dicari lewat `MilikTenant` + batas outlet di kontroler (lainnya = 404).
 */

$izin = static fn (IzinTenant $izin): string => WajibIzinTenant::class.':'.$izin->value;
$ulid = '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}';

Route::middleware([SiapkanAuditTenant::class, $izin(IzinTenant::GrosirKelola)])->prefix('grosir')->group(function () use ($ulid): void {
    Route::get('/produk/cari', [ProdukGrosirKontroler::class, 'Cari'])->name('kelola.grosir.produk.cari');
    Route::get('/pelanggan/cari', [ProdukGrosirKontroler::class, 'CariPelanggan'])->name('kelola.grosir.pelanggan.cari');

    Route::get('/pesanan', [PesananGrosirKontroler::class, 'Daftar'])->name('kelola.grosir.pesanan.daftar');
    Route::get('/pesanan/buat', [PesananGrosirKontroler::class, 'Buat'])->name('kelola.grosir.pesanan.buat');
    Route::post('/pesanan', [PesananGrosirKontroler::class, 'Simpan'])->name('kelola.grosir.pesanan.simpan');
    Route::get('/pesanan/{pesanan}', [PesananGrosirKontroler::class, 'Detail'])->where('pesanan', $ulid)->name('kelola.grosir.pesanan.detail');
    Route::get('/pesanan/{pesanan}/ambil-barang', [PesananGrosirKontroler::class, 'CetakAmbil'])->where('pesanan', $ulid)->name('kelola.grosir.pesanan.ambil-barang');
    Route::get('/pesanan/{pesanan}/ubah', [PesananGrosirKontroler::class, 'Ubah'])->where('pesanan', $ulid)->name('kelola.grosir.pesanan.ubah');
    Route::put('/pesanan/{pesanan}', [PesananGrosirKontroler::class, 'Perbarui'])->where('pesanan', $ulid)->name('kelola.grosir.pesanan.perbarui');
    Route::post('/pesanan/{pesanan}/konfirmasi', [PesananGrosirKontroler::class, 'Konfirmasi'])->where('pesanan', $ulid)->name('kelola.grosir.pesanan.konfirmasi');
    Route::post('/pesanan/{pesanan}/kirim', [PesananGrosirKontroler::class, 'Kirim'])->where('pesanan', $ulid)->name('kelola.grosir.pesanan.kirim');
    Route::post('/pesanan/{pesanan}/batalkan', [PesananGrosirKontroler::class, 'Batalkan'])->where('pesanan', $ulid)->name('kelola.grosir.pesanan.batalkan');

    Route::get('/surat-jalan', [SuratJalanKontroler::class, 'Daftar'])->name('kelola.grosir.surat-jalan.daftar');
    Route::get('/surat-jalan/{suratJalan}', [SuratJalanKontroler::class, 'Detail'])->where('suratJalan', $ulid)->name('kelola.grosir.surat-jalan.detail');
    Route::get('/surat-jalan/{suratJalan}/cetak', [SuratJalanKontroler::class, 'Cetak'])->where('suratJalan', $ulid)->name('kelola.grosir.surat-jalan.cetak');
    Route::post('/surat-jalan/{suratJalan}/pengiriman/{langkah}', [SuratJalanKontroler::class, 'Pengiriman'])->where('suratJalan', $ulid)->where('langkah', 'berangkat|diterima|gagal|batal-berangkat')->name('kelola.grosir.surat-jalan.pengiriman');
    Route::post('/surat-jalan/{suratJalan}/batalkan', [SuratJalanKontroler::class, 'Batalkan'])->where('suratJalan', $ulid)->name('kelola.grosir.surat-jalan.batalkan');

    Route::get('/faktur', [FakturPenjualanKontroler::class, 'Daftar'])->name('kelola.grosir.faktur.daftar');
    Route::get('/faktur/buat', [FakturPenjualanKontroler::class, 'Buat'])->name('kelola.grosir.faktur.buat');
    Route::post('/faktur', [FakturPenjualanKontroler::class, 'Simpan'])->name('kelola.grosir.faktur.simpan');
    Route::get('/faktur/{faktur}', [FakturPenjualanKontroler::class, 'Detail'])->where('faktur', $ulid)->name('kelola.grosir.faktur.detail');
    Route::get('/faktur/{faktur}/cetak', [FakturPenjualanKontroler::class, 'Cetak'])->where('faktur', $ulid)->name('kelola.grosir.faktur.cetak');
    Route::put('/faktur/{faktur}/nomor-pajak', [FakturPenjualanKontroler::class, 'UbahNomorPajak'])->where('faktur', $ulid)->name('kelola.grosir.faktur.nomor-pajak');
    Route::post('/faktur/{faktur}/batalkan', [FakturPenjualanKontroler::class, 'Batalkan'])->where('faktur', $ulid)->name('kelola.grosir.faktur.batalkan');

    Route::get('/retur', [ReturGrosirKontroler::class, 'Daftar'])->name('kelola.grosir.retur.daftar');
    Route::get('/retur/buat/{suratJalan}', [ReturGrosirKontroler::class, 'Buat'])->where('suratJalan', $ulid)->name('kelola.grosir.retur.buat');
    Route::post('/retur', [ReturGrosirKontroler::class, 'Simpan'])->name('kelola.grosir.retur.simpan');
    Route::get('/retur/{retur}', [ReturGrosirKontroler::class, 'Detail'])->where('retur', $ulid)->name('kelola.grosir.retur.detail');
    Route::get('/retur/{retur}/cetak', [ReturGrosirKontroler::class, 'Cetak'])->where('retur', $ulid)->name('kelola.grosir.retur.cetak');
    Route::post('/retur/{retur}/batalkan', [ReturGrosirKontroler::class, 'Batalkan'])->where('retur', $ulid)->name('kelola.grosir.retur.batalkan');

    // Armada pengiriman (master; rumahnya Pengaturan).
    Route::get('/armada', [ArmadaKontroler::class, 'Daftar'])->name('kelola.grosir.armada.daftar');
    Route::post('/armada', [ArmadaKontroler::class, 'Simpan'])->name('kelola.grosir.armada.simpan');
    Route::put('/armada/{armada}', [ArmadaKontroler::class, 'Ubah'])->where('armada', $ulid)->name('kelola.grosir.armada.ubah');
    Route::post('/armada/{armada}/nonaktifkan', [ArmadaKontroler::class, 'Nonaktifkan'])->where('armada', $ulid)->name('kelola.grosir.armada.nonaktifkan');
    Route::post('/armada/{armada}/aktifkan', [ArmadaKontroler::class, 'Aktifkan'])->where('armada', $ulid)->name('kelola.grosir.armada.aktifkan');

    // Modul Salesman bagian 1: kunjungan dari aplikasi salesman (hanya baca) + ekspor CSV.
    Route::get('/kunjungan', [KunjunganSalesKontroler::class, 'Daftar'])->name('kelola.grosir.kunjungan.daftar');
    Route::get('/kunjungan/ekspor', [KunjunganSalesKontroler::class, 'Ekspor'])->name('kelola.grosir.kunjungan.ekspor');

    // Modul Salesman bagian 3: kendaraan kanvas + rekap harian (muat, terjual, retur, bongkar, sisa, setoran).
    Route::get('/kanvas', [KanvasKontroler::class, 'Daftar'])->name('kelola.grosir.kanvas.daftar');
});

// Kendaraan kanvas = outlet baru (batas paket BR-02.1), jadi izinnya sama dengan tambah outlet.
Route::middleware([SiapkanAuditTenant::class, $izin(IzinTenant::OutletKelola)])->prefix('grosir')->group(function (): void {
    Route::post('/kanvas', [KanvasKontroler::class, 'Simpan'])->name('kelola.grosir.kanvas.simpan');
});
