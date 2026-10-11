<?php

declare(strict_types=1);

use App\Domain\Organisasi\Enum\IzinTenant;
use App\Http\Kontroler\Kelola\Persediaan\BahanTerbuangKontroler;
use App\Http\Kontroler\Kelola\Persediaan\OrderProduksiKontroler;
use App\Http\Kontroler\Kelola\Persediaan\PelacakanStokKontroler;
use App\Http\Kontroler\Kelola\Persediaan\PenyesuaianStokKontroler;
use App\Http\Kontroler\Kelola\Persediaan\StokOpnameKontroler;
use App\Http\Kontroler\Kelola\Persediaan\TransferStokKontroler;
use App\Http\Perantara\SiapkanAuditTenant;
use App\Http\Perantara\WajibIzinTenant;
use Illuminate\Support\Facades\Route;

/*
 * Rute back-office F-05b transfer stok, stok opname, dan penyesuaian stok (PRD Rincian F-05b, §13.6, D-06).
 * Didaftarkan dari routes/web.php di dalam grup `/kelola`, setelah routes/Persediaan.php. Semua rute memakai
 * `SiapkanAuditTenant` dan izin lewat `$izin`: lihat `persediaan.lihat`, ubah `persediaan.kelola`, persetujuan
 * `persediaan.penyesuaian.setujui`. Parameter dokumen dibatasi pola ULID dan dicari lewat `MilikTenant` di kontroler.
 */

$izin = static fn (IzinTenant $izin): string => WajibIzinTenant::class.':'.$izin->value;
$ulid = '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}';

Route::middleware(SiapkanAuditTenant::class)->group(function () use ($izin, $ulid): void {
    $lihat = $izin(IzinTenant::PersediaanLihat);
    $kelola = $izin(IzinTenant::PersediaanKelola);
    $setujui = $izin(IzinTenant::PersediaanPenyesuaianSetujui);

    Route::get('/persediaan/transfer', [TransferStokKontroler::class, 'Daftar'])->middleware($lihat)->name('kelola.persediaan.transfer.daftar');
    Route::get('/persediaan/transfer/buat', [TransferStokKontroler::class, 'Buat'])->middleware($kelola)->name('kelola.persediaan.transfer.buat');
    Route::post('/persediaan/transfer', [TransferStokKontroler::class, 'Simpan'])->middleware($kelola)->name('kelola.persediaan.transfer.simpan');
    Route::get('/persediaan/transfer/{transferStok}', [TransferStokKontroler::class, 'Detail'])->middleware($lihat)->where('transferStok', $ulid)->name('kelola.persediaan.transfer.detail');
    Route::get('/persediaan/transfer/{transferStok}/cetak', [TransferStokKontroler::class, 'Cetak'])->middleware($lihat)->where('transferStok', $ulid)->name('kelola.persediaan.transfer.cetak');
    Route::get('/persediaan/transfer/{transferStok}/ubah', [TransferStokKontroler::class, 'Ubah'])->middleware($kelola)->where('transferStok', $ulid)->name('kelola.persediaan.transfer.ubah');
    Route::put('/persediaan/transfer/{transferStok}', [TransferStokKontroler::class, 'Perbarui'])->middleware($kelola)->where('transferStok', $ulid)->name('kelola.persediaan.transfer.perbarui');
    Route::post('/persediaan/transfer/{transferStok}/kirim', [TransferStokKontroler::class, 'Kirim'])->middleware($kelola)->where('transferStok', $ulid)->name('kelola.persediaan.transfer.kirim');
    Route::post('/persediaan/transfer/{transferStok}/terima', [TransferStokKontroler::class, 'Terima'])->middleware($kelola)->where('transferStok', $ulid)->name('kelola.persediaan.transfer.terima');
    Route::post('/persediaan/transfer/{transferStok}/tutup', [TransferStokKontroler::class, 'Tutup'])->middleware($kelola)->where('transferStok', $ulid)->name('kelola.persediaan.transfer.tutup');
    Route::post('/persediaan/transfer/{transferStok}/batalkan', [TransferStokKontroler::class, 'Batalkan'])->middleware($kelola)->where('transferStok', $ulid)->name('kelola.persediaan.transfer.batalkan');

    Route::get('/persediaan/opname', [StokOpnameKontroler::class, 'Daftar'])->middleware($lihat)->name('kelola.persediaan.opname.daftar');
    Route::post('/persediaan/opname', [StokOpnameKontroler::class, 'Mulai'])->middleware($kelola)->name('kelola.persediaan.opname.mulai');
    Route::get('/persediaan/opname/{stokOpname}', [StokOpnameKontroler::class, 'Detail'])->middleware($lihat)->where('stokOpname', $ulid)->name('kelola.persediaan.opname.detail');
    Route::put('/persediaan/opname/{stokOpname}/hitung', [StokOpnameKontroler::class, 'SimpanHitung'])->middleware($kelola)->where('stokOpname', $ulid)->name('kelola.persediaan.opname.hitung');
    Route::post('/persediaan/opname/{stokOpname}/ajukan', [StokOpnameKontroler::class, 'Ajukan'])->middleware($kelola)->where('stokOpname', $ulid)->name('kelola.persediaan.opname.ajukan');
    Route::post('/persediaan/opname/{stokOpname}/kembalikan', [StokOpnameKontroler::class, 'Kembalikan'])->middleware($setujui)->where('stokOpname', $ulid)->name('kelola.persediaan.opname.kembalikan');
    Route::post('/persediaan/opname/{stokOpname}/setujui', [StokOpnameKontroler::class, 'Setujui'])->middleware($setujui)->where('stokOpname', $ulid)->name('kelola.persediaan.opname.setujui');
    Route::post('/persediaan/opname/{stokOpname}/batalkan', [StokOpnameKontroler::class, 'Batalkan'])->middleware($kelola)->where('stokOpname', $ulid)->name('kelola.persediaan.opname.batalkan');

    Route::get('/persediaan/penyesuaian', [PenyesuaianStokKontroler::class, 'Daftar'])->middleware($lihat)->name('kelola.persediaan.penyesuaian.daftar');
    Route::get('/persediaan/penyesuaian/buat', [PenyesuaianStokKontroler::class, 'Buat'])->middleware($kelola)->name('kelola.persediaan.penyesuaian.buat');
    Route::post('/persediaan/penyesuaian', [PenyesuaianStokKontroler::class, 'Simpan'])->middleware($kelola)->name('kelola.persediaan.penyesuaian.simpan');
    Route::get('/persediaan/penyesuaian/{penyesuaianStok}', [PenyesuaianStokKontroler::class, 'Detail'])->middleware($lihat)->where('penyesuaianStok', $ulid)->name('kelola.persediaan.penyesuaian.detail');
    Route::get('/persediaan/penyesuaian/{penyesuaianStok}/ubah', [PenyesuaianStokKontroler::class, 'Ubah'])->middleware($kelola)->where('penyesuaianStok', $ulid)->name('kelola.persediaan.penyesuaian.ubah');
    Route::put('/persediaan/penyesuaian/{penyesuaianStok}', [PenyesuaianStokKontroler::class, 'Perbarui'])->middleware($kelola)->where('penyesuaianStok', $ulid)->name('kelola.persediaan.penyesuaian.perbarui');
    Route::post('/persediaan/penyesuaian/{penyesuaianStok}/ajukan', [PenyesuaianStokKontroler::class, 'Ajukan'])->middleware($kelola)->where('penyesuaianStok', $ulid)->name('kelola.persediaan.penyesuaian.ajukan');
    Route::post('/persediaan/penyesuaian/{penyesuaianStok}/setujui', [PenyesuaianStokKontroler::class, 'Setujui'])->middleware($setujui)->where('penyesuaianStok', $ulid)->name('kelola.persediaan.penyesuaian.setujui');
    Route::post('/persediaan/penyesuaian/{penyesuaianStok}/tolak', [PenyesuaianStokKontroler::class, 'Tolak'])->middleware($setujui)->where('penyesuaianStok', $ulid)->name('kelola.persediaan.penyesuaian.tolak');
    Route::post('/persediaan/penyesuaian/{penyesuaianStok}/batalkan', [PenyesuaianStokKontroler::class, 'Batalkan'])->middleware($kelola)->where('penyesuaianStok', $ulid)->name('kelola.persediaan.penyesuaian.batalkan');

    // F-05e order produksi (resep → bahan keluar, hasil masuk, J-05.6).
    Route::get('/persediaan/produksi', [OrderProduksiKontroler::class, 'Daftar'])->middleware($lihat)->name('kelola.persediaan.produksi.daftar');
    Route::get('/persediaan/produksi/buat', [OrderProduksiKontroler::class, 'Buat'])->middleware($kelola)->name('kelola.persediaan.produksi.buat');
    Route::get('/persediaan/produksi/resep', [OrderProduksiKontroler::class, 'Resep'])->middleware($kelola)->name('kelola.persediaan.produksi.resep');
    Route::post('/persediaan/produksi', [OrderProduksiKontroler::class, 'Simpan'])->middleware($kelola)->name('kelola.persediaan.produksi.simpan');
    Route::get('/persediaan/produksi/{orderProduksi}', [OrderProduksiKontroler::class, 'Detail'])->middleware($lihat)->where('orderProduksi', $ulid)->name('kelola.persediaan.produksi.detail');
    Route::get('/persediaan/produksi/{orderProduksi}/ubah', [OrderProduksiKontroler::class, 'Ubah'])->middleware($kelola)->where('orderProduksi', $ulid)->name('kelola.persediaan.produksi.ubah');
    Route::put('/persediaan/produksi/{orderProduksi}', [OrderProduksiKontroler::class, 'Perbarui'])->middleware($kelola)->where('orderProduksi', $ulid)->name('kelola.persediaan.produksi.perbarui');
    Route::post('/persediaan/produksi/{orderProduksi}/posting', [OrderProduksiKontroler::class, 'Posting'])->middleware($kelola)->where('orderProduksi', $ulid)->name('kelola.persediaan.produksi.posting');
    Route::post('/persediaan/produksi/{orderProduksi}/batalkan', [OrderProduksiKontroler::class, 'Batalkan'])->middleware($kelola)->where('orderProduksi', $ulid)->name('kelola.persediaan.produksi.batalkan');

    // F-05f bahan terbuang (waste) + ringkasan food cost.
    Route::get('/persediaan/bahan-terbuang', [BahanTerbuangKontroler::class, 'Daftar'])->middleware($lihat)->name('kelola.persediaan.bahan-terbuang.daftar');
    Route::post('/persediaan/bahan-terbuang', [BahanTerbuangKontroler::class, 'Catat'])->middleware($izin(IzinTenant::PersediaanTerbuangCatat))->name('kelola.persediaan.bahan-terbuang.catat');
    Route::post('/persediaan/bahan-terbuang/{bahanTerbuang}/batalkan', [BahanTerbuangKontroler::class, 'Batalkan'])->middleware($kelola)->where('bahanTerbuang', $ulid)->name('kelola.persediaan.bahan-terbuang.batalkan');

    Route::get('/persediaan/pelacakan', [PelacakanStokKontroler::class, 'Tampilkan'])->middleware($lihat)->name('kelola.persediaan.pelacakan');
});
