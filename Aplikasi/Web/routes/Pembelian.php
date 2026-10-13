<?php

declare(strict_types=1);

use App\Domain\Organisasi\Enum\IzinTenant;
use App\Http\Kontroler\Kelola\Pembelian\BiayaTambahanKontroler;
use App\Http\Kontroler\Kelola\Pembelian\FakturPembelianKontroler;
use App\Http\Kontroler\Kelola\Pembelian\HutangKontroler;
use App\Http\Kontroler\Kelola\Pembelian\ImporPemasokKontroler;
use App\Http\Kontroler\Kelola\Pembelian\KonsinyasiKontroler;
use App\Http\Kontroler\Kelola\Pembelian\PemasokKontroler;
use App\Http\Kontroler\Kelola\Pembelian\PenerimaanBarangKontroler;
use App\Http\Kontroler\Kelola\Pembelian\PengaturanPembelianKontroler;
use App\Http\Kontroler\Kelola\Pembelian\PesananPembelianKontroler;
use App\Http\Kontroler\Kelola\Pembelian\ProdukPembelianKontroler;
use App\Http\Kontroler\Kelola\Pembelian\ReturPembelianKontroler;
use App\Http\Perantara\EksporDiLatarBelakang;
use App\Http\Perantara\SiapkanAuditTenant;
use App\Http\Perantara\WajibIzinTenant;
use Illuminate\Support\Facades\Route;

/*
 * Rute back-office F-04 fase 1 pembelian (PRD "Rincian F-04 fase 1", §13.6, D-06): pemasok, pesanan pembelian,
 * penerimaan barang, belanja stok, faktur, hutang & pembayaran, retur, dan pengaturan. Didaftarkan dari
 * routes/web.php di dalam grup `/kelola` (auth + IdentifikasiTenantSesi … BatasiTenantDitangguhkan). Semua rute
 * memakai `SiapkanAuditTenant`; izin `pembelian.kelola`, persetujuan PO & pengaturan `pembelian.po.setujui`.
 * Parameter dokumen dibatasi pola ULID dan dicari lewat `MilikTenant` + batas outlet di kontroler (lainnya = 404).
 */

$izin = static fn (IzinTenant $izin): string => WajibIzinTenant::class.':'.$izin->value;
$ulid = '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}';

Route::middleware([SiapkanAuditTenant::class, $izin(IzinTenant::PembelianKelola)])->prefix('pembelian')->group(function () use ($ulid): void {
    Route::get('/produk/cari', [ProdukPembelianKontroler::class, 'Cari'])->name('kelola.pembelian.produk.cari');

    Route::get('/pemasok', [PemasokKontroler::class, 'Daftar'])->name('kelola.pembelian.pemasok.daftar');
    Route::get('/pemasok/buat', [PemasokKontroler::class, 'Buat'])->name('kelola.pembelian.pemasok.buat');
    // PLT-10 (F31): impor pemasok dari Excel/CSV (periksa → terapkan).
    Route::get('/pemasok/impor', [ImporPemasokKontroler::class, 'Halaman'])->name('kelola.pembelian.pemasok.impor');
    Route::post('/pemasok/impor', [ImporPemasokKontroler::class, 'Kirim'])->middleware('throttle:20,1')->name('kelola.pembelian.pemasok.impor.kirim');
    Route::get('/pemasok/impor/templat', [ImporPemasokKontroler::class, 'Templat'])->name('kelola.pembelian.pemasok.impor.templat');
    Route::post('/pemasok', [PemasokKontroler::class, 'Simpan'])->name('kelola.pembelian.pemasok.simpan');
    Route::put('/pemasok/{pemasok}', [PemasokKontroler::class, 'Perbarui'])->where('pemasok', $ulid)->name('kelola.pembelian.pemasok.perbarui');
    Route::post('/pemasok/{pemasok}/status', [PemasokKontroler::class, 'UbahStatus'])->where('pemasok', $ulid)->name('kelola.pembelian.pemasok.status');
    Route::delete('/pemasok/{pemasok}', [PemasokKontroler::class, 'Hapus'])->where('pemasok', $ulid)->name('kelola.pembelian.pemasok.hapus');

    Route::get('/pesanan', [PesananPembelianKontroler::class, 'Daftar'])->name('kelola.pembelian.pesanan.daftar');
    Route::get('/pesanan/buat', [PesananPembelianKontroler::class, 'Buat'])->name('kelola.pembelian.pesanan.buat');
    Route::post('/pesanan', [PesananPembelianKontroler::class, 'Simpan'])->name('kelola.pembelian.pesanan.simpan');
    Route::post('/pesanan/draf-otomatis', [PesananPembelianKontroler::class, 'BuatDrafOtomatis'])->name('kelola.pembelian.pesanan.draf-otomatis');
    Route::get('/pesanan/{pesanan}', [PesananPembelianKontroler::class, 'Detail'])->where('pesanan', $ulid)->name('kelola.pembelian.pesanan.detail');
    Route::get('/pesanan/{pesanan}/ubah', [PesananPembelianKontroler::class, 'Ubah'])->where('pesanan', $ulid)->name('kelola.pembelian.pesanan.ubah');
    Route::put('/pesanan/{pesanan}', [PesananPembelianKontroler::class, 'Perbarui'])->where('pesanan', $ulid)->name('kelola.pembelian.pesanan.perbarui');
    Route::post('/pesanan/{pesanan}/ajukan', [PesananPembelianKontroler::class, 'Ajukan'])->where('pesanan', $ulid)->name('kelola.pembelian.pesanan.ajukan');
    Route::post('/pesanan/{pesanan}/batalkan', [PesananPembelianKontroler::class, 'Batalkan'])->where('pesanan', $ulid)->name('kelola.pembelian.pesanan.batalkan');
    Route::post('/pesanan/{pesanan}/tutup', [PesananPembelianKontroler::class, 'Tutup'])->where('pesanan', $ulid)->name('kelola.pembelian.pesanan.tutup');
    Route::get('/pesanan/{pesanan}/cetak', [PesananPembelianKontroler::class, 'Cetak'])->where('pesanan', $ulid)->name('kelola.pembelian.pesanan.cetak');

    Route::get('/penerimaan', [PenerimaanBarangKontroler::class, 'Daftar'])->name('kelola.pembelian.penerimaan.daftar');
    Route::get('/penerimaan/buat', [PenerimaanBarangKontroler::class, 'Buat'])->name('kelola.pembelian.penerimaan.buat');
    Route::post('/penerimaan', [PenerimaanBarangKontroler::class, 'Simpan'])->name('kelola.pembelian.penerimaan.simpan');
    Route::get('/penerimaan/{penerimaan}', [PenerimaanBarangKontroler::class, 'Detail'])->where('penerimaan', $ulid)->name('kelola.pembelian.penerimaan.detail');
    Route::post('/penerimaan/{penerimaan}/batalkan', [PenerimaanBarangKontroler::class, 'Batalkan'])->where('penerimaan', $ulid)->name('kelola.pembelian.penerimaan.batalkan');
    Route::get('/penerimaan/{penerimaan}/lampiran', [PenerimaanBarangKontroler::class, 'Lampiran'])->where('penerimaan', $ulid)->name('kelola.pembelian.penerimaan.lampiran');

    Route::get('/belanja-stok', [PenerimaanBarangKontroler::class, 'BuatBelanja'])->name('kelola.pembelian.belanja-stok.buat');
    Route::post('/belanja-stok', [PenerimaanBarangKontroler::class, 'SimpanBelanja'])->name('kelola.pembelian.belanja-stok.simpan');

    Route::get('/faktur', [FakturPembelianKontroler::class, 'Daftar'])->name('kelola.pembelian.faktur.daftar');
    Route::get('/faktur/buat', [FakturPembelianKontroler::class, 'Buat'])->name('kelola.pembelian.faktur.buat');
    Route::post('/faktur', [FakturPembelianKontroler::class, 'Simpan'])->name('kelola.pembelian.faktur.simpan');
    Route::get('/faktur/{faktur}', [FakturPembelianKontroler::class, 'Detail'])->where('faktur', $ulid)->name('kelola.pembelian.faktur.detail');
    Route::post('/faktur/{faktur}/batalkan', [FakturPembelianKontroler::class, 'Batalkan'])->where('faktur', $ulid)->name('kelola.pembelian.faktur.batalkan');
    Route::get('/faktur/{faktur}/lampiran', [FakturPembelianKontroler::class, 'Lampiran'])->where('faktur', $ulid)->name('kelola.pembelian.faktur.lampiran');

    Route::get('/hutang', [HutangKontroler::class, 'Hutang'])->name('kelola.pembelian.hutang.daftar');
    Route::get('/hutang/ekspor', [HutangKontroler::class, 'EksporHutang'])->middleware(EksporDiLatarBelakang::class)->name('kelola.pembelian.hutang.ekspor');
    Route::get('/pembayaran', [HutangKontroler::class, 'Daftar'])->name('kelola.pembelian.pembayaran.daftar');
    Route::get('/pembayaran/buat', [HutangKontroler::class, 'Buat'])->name('kelola.pembelian.pembayaran.buat');
    Route::post('/pembayaran', [HutangKontroler::class, 'Simpan'])->name('kelola.pembelian.pembayaran.simpan');
    Route::get('/pembayaran/{pembayaran}', [HutangKontroler::class, 'Detail'])->where('pembayaran', $ulid)->name('kelola.pembelian.pembayaran.detail');
    Route::post('/pembayaran/{pembayaran}/batalkan', [HutangKontroler::class, 'Batalkan'])->where('pembayaran', $ulid)->name('kelola.pembelian.pembayaran.batalkan');
    Route::get('/pembayaran/{pembayaran}/lampiran', [HutangKontroler::class, 'Lampiran'])->where('pembayaran', $ulid)->name('kelola.pembelian.pembayaran.lampiran');

    // v3.41 (INV-14): biaya tambahan pembelian dari pihak ketiga (landed cost setelah GRN).
    Route::get('/biaya-tambahan', [BiayaTambahanKontroler::class, 'Daftar'])->name('kelola.pembelian.biaya-tambahan.daftar');
    Route::get('/biaya-tambahan/buat', [BiayaTambahanKontroler::class, 'Buat'])->name('kelola.pembelian.biaya-tambahan.buat');
    Route::post('/biaya-tambahan', [BiayaTambahanKontroler::class, 'Simpan'])->name('kelola.pembelian.biaya-tambahan.simpan');
    Route::get('/biaya-tambahan/{biaya}', [BiayaTambahanKontroler::class, 'Detail'])->where('biaya', $ulid)->name('kelola.pembelian.biaya-tambahan.detail');
    Route::post('/biaya-tambahan/{biaya}/batalkan', [BiayaTambahanKontroler::class, 'Batalkan'])->where('biaya', $ulid)->name('kelola.pembelian.biaya-tambahan.batalkan');

    // F-05i konsinyasi: penitip & hutang, dokumen titipan masuk/retur, rincian penitip, setoran.
    Route::get('/konsinyasi', [KonsinyasiKontroler::class, 'Daftar'])->name('kelola.pembelian.konsinyasi.daftar');
    Route::get('/konsinyasi/dokumen', [KonsinyasiKontroler::class, 'Dokumen'])->name('kelola.pembelian.konsinyasi.dokumen');
    Route::get('/konsinyasi/buat', [KonsinyasiKontroler::class, 'Buat'])->name('kelola.pembelian.konsinyasi.buat');
    Route::get('/konsinyasi/produk/cari', [KonsinyasiKontroler::class, 'CariProduk'])->name('kelola.pembelian.konsinyasi.produk.cari');
    Route::post('/konsinyasi', [KonsinyasiKontroler::class, 'Simpan'])->name('kelola.pembelian.konsinyasi.simpan');
    Route::get('/konsinyasi/dokumen/{dokumen}', [KonsinyasiKontroler::class, 'DetailDokumen'])->where('dokumen', $ulid)->name('kelola.pembelian.konsinyasi.dokumen.detail');
    Route::get('/konsinyasi/penitip/{pemasok}', [KonsinyasiKontroler::class, 'Penitip'])->where('pemasok', $ulid)->name('kelola.pembelian.konsinyasi.penitip');
    Route::post('/konsinyasi/penitip/{pemasok}/setoran', [KonsinyasiKontroler::class, 'SimpanSetoran'])->where('pemasok', $ulid)->name('kelola.pembelian.konsinyasi.setoran.simpan');
    Route::get('/konsinyasi/setoran/{setoran}', [KonsinyasiKontroler::class, 'Setoran'])->where('setoran', $ulid)->name('kelola.pembelian.konsinyasi.setoran.detail');
    Route::post('/konsinyasi/setoran/{setoran}/batalkan', [KonsinyasiKontroler::class, 'BatalkanSetoran'])->where('setoran', $ulid)->name('kelola.pembelian.konsinyasi.setoran.batalkan');

    Route::get('/retur', [ReturPembelianKontroler::class, 'Daftar'])->name('kelola.pembelian.retur.daftar');
    Route::get('/retur/buat', [ReturPembelianKontroler::class, 'Buat'])->name('kelola.pembelian.retur.buat');
    Route::post('/retur', [ReturPembelianKontroler::class, 'Simpan'])->name('kelola.pembelian.retur.simpan');
    Route::get('/retur/{retur}', [ReturPembelianKontroler::class, 'Detail'])->where('retur', $ulid)->name('kelola.pembelian.retur.detail');
    Route::post('/retur/{retur}/batalkan', [ReturPembelianKontroler::class, 'Batalkan'])->where('retur', $ulid)->name('kelola.pembelian.retur.batalkan');
});

Route::middleware([SiapkanAuditTenant::class, $izin(IzinTenant::PembelianPoSetujui)])->prefix('pembelian')->group(function () use ($ulid): void {
    Route::post('/pesanan/{pesanan}/setujui', [PesananPembelianKontroler::class, 'Setujui'])->where('pesanan', $ulid)->name('kelola.pembelian.pesanan.setujui');
    Route::post('/pesanan/{pesanan}/tolak', [PesananPembelianKontroler::class, 'Tolak'])->where('pesanan', $ulid)->name('kelola.pembelian.pesanan.tolak');
    Route::get('/pengaturan', [PengaturanPembelianKontroler::class, 'Tampilkan'])->name('kelola.pembelian.pengaturan');
    Route::put('/pengaturan', [PengaturanPembelianKontroler::class, 'Simpan'])->name('kelola.pembelian.pengaturan.simpan');
});
