<?php

declare(strict_types=1);

use App\Domain\Organisasi\Enum\IzinTenant;
use App\Http\Kontroler\Kelola\Akuntansi\AnggaranKontroler;
use App\Http\Kontroler\Kelola\Akuntansi\AsetTetapKontroler;
use App\Http\Kontroler\Kelola\Akuntansi\BaganAkunKontroler;
use App\Http\Kontroler\Kelola\Akuntansi\GiroKontroler;
use App\Http\Kontroler\Kelola\Akuntansi\JadwalKasBankKontroler;
use App\Http\Kontroler\Kelola\Akuntansi\JurnalKontroler;
use App\Http\Kontroler\Kelola\Akuntansi\JurnalUmumKontroler;
use App\Http\Kontroler\Kelola\Akuntansi\LaporanKeuanganKontroler;
use App\Http\Kontroler\Kelola\Akuntansi\PemetaanAkunKontroler;
use App\Http\Kontroler\Kelola\Akuntansi\PencairanKontroler;
use App\Http\Kontroler\Kelola\Akuntansi\RekonsiliasiBankKontroler;
use App\Http\Kontroler\Kelola\Akuntansi\TagihanQrisTanpaPenjualanKontroler;
use App\Http\Kontroler\Kelola\Akuntansi\TransaksiKasBankKontroler;
use App\Http\Kontroler\Kelola\Akuntansi\TutupBukuKontroler;
use App\Http\Perantara\EksporDiLatarBelakang;
use App\Http\Perantara\SiapkanAuditTenant;
use App\Http\Perantara\WajibIzinTenant;
use Illuminate\Support\Facades\Route;

/*
 * Rute back-office akuntansi, PRD §13.6, D-06. Didaftarkan dari routes/web.php di dalam grup `/kelola`.
 * - F-05a jurnal (baca saja, DesainF05a D): `laporan.keuangan.lihat` (H-13: tidak ada `akuntansi.lihat`).
 * - F-13a bagan akun, pemetaan akun, transaksi kas & bank, laporan keuangan: lihat `laporan.keuangan.lihat`, ubah
 *   `akuntansi.kelola`.
 * - F-08 BR-08.4 pencairan dana non-tunai (J-08.1): izin sama dengan transaksi kas & bank, karena dampaknya sejenis
 *   (uang masuk rekening + beban biaya pembayaran).
 * Parameter ULID dicari lewat `MilikTenant` di kueri (data tenant lain = 404).
 */

$izin = static fn (IzinTenant $izin): string => WajibIzinTenant::class.':'.$izin->value;
$ulid = '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}';

Route::middleware([SiapkanAuditTenant::class, $izin(IzinTenant::LaporanKeuanganLihat)])->group(function () use ($ulid): void {
    // F-15: tutup buku (daftar periode & status kunci).
    Route::get('/akuntansi/tutup-buku', [TutupBukuKontroler::class, 'Tampilkan'])->name('kelola.akuntansi.tutup-buku');
    Route::get('/akuntansi/jurnal', [JurnalKontroler::class, 'Daftar'])->name('kelola.akuntansi.jurnal.daftar');
    // FIN-12 (F28): ekspor jurnal per baris untuk software akuntansi lain (Excel/CSV/cetak, cari & saringan tabel ikut).
    Route::get('/akuntansi/jurnal/ekspor', [JurnalKontroler::class, 'Ekspor'])->middleware(EksporDiLatarBelakang::class)->name('kelola.akuntansi.jurnal.ekspor');
    Route::get('/akuntansi/jurnal/{jurnal}', [JurnalKontroler::class, 'Detail'])->where('jurnal', $ulid)->name('kelola.akuntansi.jurnal.detail');

    // FIN-05 (D-131): jurnal umum manual (lihat dokumen & lampirannya; catat/balik di grup `akuntansi.kelola`).
    Route::get('/akuntansi/jurnal-umum/{jurnalUmum}', [JurnalUmumKontroler::class, 'Detail'])->where('jurnalUmum', $ulid)->name('kelola.akuntansi.jurnal-umum.detail');
    Route::get('/akuntansi/jurnal-umum/{jurnalUmum}/lampiran', [JurnalUmumKontroler::class, 'Lampiran'])->where('jurnalUmum', $ulid)->name('kelola.akuntansi.jurnal-umum.lampiran');

    // F-13a: bagan akun & pemetaan akun (lihat).
    Route::get('/akuntansi/akun', [BaganAkunKontroler::class, 'Daftar'])->name('kelola.akuntansi.akun.daftar');
    Route::get('/akuntansi/pemetaan', [PemetaanAkunKontroler::class, 'Daftar'])->name('kelola.akuntansi.pemetaan.daftar');

    // F-13a: transaksi kas & bank (lihat) dan lampirannya.
    Route::get('/akuntansi/kas-bank', [TransaksiKasBankKontroler::class, 'Daftar'])->name('kelola.akuntansi.kas-bank.daftar');
    // D-23 D: transaksi kas & bank berulang.
    Route::get('/akuntansi/kas-bank/berulang', [JadwalKasBankKontroler::class, 'Daftar'])->name('kelola.akuntansi.kas-bank.berulang.daftar');
    Route::get('/akuntansi/kas-bank/{transaksiKasBank}', [TransaksiKasBankKontroler::class, 'Detail'])->where('transaksiKasBank', $ulid)->name('kelola.akuntansi.kas-bank.detail');
    Route::get('/akuntansi/kas-bank/{transaksiKasBank}/lampiran', [TransaksiKasBankKontroler::class, 'Lampiran'])->where('transaksiKasBank', $ulid)->name('kelola.akuntansi.kas-bank.lampiran');

    // F-13a: laporan keuangan dari jurnal + ekspor CSV.
    // F-08 BR-08.4: pencairan dana non-tunai (daftar & detail).
    Route::get('/akuntansi/pencairan', [PencairanKontroler::class, 'Daftar'])->name('kelola.akuntansi.pencairan.daftar');
    Route::get('/akuntansi/pencairan/rekap-potongan/ekspor', [PencairanKontroler::class, 'EksporRekapPotongan'])->name('kelola.akuntansi.pencairan.rekap-potongan.ekspor');
    Route::get('/akuntansi/pencairan/{pencairan}', [PencairanKontroler::class, 'Detail'])->where('pencairan', $ulid)->name('kelola.akuntansi.pencairan.detail');
    // E8: uang QRIS yang masuk tanpa penjualan (daftar; tutup & buka kembali di bawah).
    Route::get('/akuntansi/qris-tanpa-penjualan', [TagihanQrisTanpaPenjualanKontroler::class, 'Daftar'])->name('kelola.akuntansi.qris-tanpa-penjualan.daftar');
    // FIN-10 (v3.38): aset tetap & penyusutan (daftar & rincian).
    Route::get('/akuntansi/aset-tetap', [AsetTetapKontroler::class, 'Daftar'])->name('kelola.akuntansi.aset-tetap.daftar');
    Route::get('/akuntansi/aset-tetap/{asetTetap}', [AsetTetapKontroler::class, 'Detail'])->where('asetTetap', $ulid)->name('kelola.akuntansi.aset-tetap.detail');
    // FIN-09 (v3.39): rekonsiliasi bank per akun kas/bank.
    Route::get('/akuntansi/rekonsiliasi', [RekonsiliasiBankKontroler::class, 'Awal'])->name('kelola.akuntansi.rekonsiliasi.awal');
    Route::get('/akuntansi/rekonsiliasi/{akun}', [RekonsiliasiBankKontroler::class, 'Tampilkan'])->where('akun', $ulid)->name('kelola.akuntansi.rekonsiliasi');
    // v3.42 (F-12): giro/cek mundur masuk & keluar.
    Route::get('/akuntansi/giro', [GiroKontroler::class, 'Daftar'])->name('kelola.akuntansi.giro.daftar');

    // F8 (FIN-11): anggaran vs realisasi (laporan & ekspor; susun/simpan/salin di grup `akuntansi.kelola`).
    Route::get('/akuntansi/anggaran', [AnggaranKontroler::class, 'Laporan'])->name('kelola.akuntansi.anggaran.laporan');
    Route::get('/akuntansi/anggaran/ekspor', [AnggaranKontroler::class, 'Ekspor'])->name('kelola.akuntansi.anggaran.ekspor');

    Route::get('/akuntansi/laporan/buku-besar', [LaporanKeuanganKontroler::class, 'BukuBesar'])->name('kelola.akuntansi.laporan.buku-besar');
    Route::get('/akuntansi/laporan/buku-besar/ekspor', [LaporanKeuanganKontroler::class, 'EksporBukuBesar'])->middleware(EksporDiLatarBelakang::class)->name('kelola.akuntansi.laporan.buku-besar.ekspor');
    Route::get('/akuntansi/laporan/neraca-saldo', [LaporanKeuanganKontroler::class, 'NeracaSaldo'])->name('kelola.akuntansi.laporan.neraca-saldo');
    Route::get('/akuntansi/laporan/neraca-saldo/ekspor', [LaporanKeuanganKontroler::class, 'EksporNeracaSaldo'])->name('kelola.akuntansi.laporan.neraca-saldo.ekspor');
    Route::get('/akuntansi/laporan/laba-rugi', [LaporanKeuanganKontroler::class, 'LabaRugi'])->name('kelola.akuntansi.laporan.laba-rugi');
    Route::get('/akuntansi/laporan/laba-rugi/ekspor', [LaporanKeuanganKontroler::class, 'EksporLabaRugi'])->name('kelola.akuntansi.laporan.laba-rugi.ekspor');
    Route::get('/akuntansi/laporan/neraca', [LaporanKeuanganKontroler::class, 'Neraca'])->name('kelola.akuntansi.laporan.neraca');
    Route::get('/akuntansi/laporan/neraca/ekspor', [LaporanKeuanganKontroler::class, 'EksporNeraca'])->name('kelola.akuntansi.laporan.neraca.ekspor');
    Route::get('/akuntansi/laporan/arus-kas', [LaporanKeuanganKontroler::class, 'ArusKas'])->name('kelola.akuntansi.laporan.arus-kas');
    Route::get('/akuntansi/laporan/arus-kas/ekspor', [LaporanKeuanganKontroler::class, 'EksporArusKas'])->name('kelola.akuntansi.laporan.arus-kas.ekspor');
});

Route::middleware([SiapkanAuditTenant::class, $izin(IzinTenant::AkuntansiKelola)])->group(function () use ($ulid): void {
    // F-13a: bagan akun (ubah).
    Route::post('/akuntansi/akun', [BaganAkunKontroler::class, 'Simpan'])->name('kelola.akuntansi.akun.simpan');
    Route::put('/akuntansi/akun/{akun}', [BaganAkunKontroler::class, 'Perbarui'])->where('akun', $ulid)->name('kelola.akuntansi.akun.perbarui');
    Route::put('/akuntansi/akun/{akun}/status', [BaganAkunKontroler::class, 'UbahStatus'])->where('akun', $ulid)->name('kelola.akuntansi.akun.status');
    Route::delete('/akuntansi/akun/{akun}', [BaganAkunKontroler::class, 'Hapus'])->where('akun', $ulid)->name('kelola.akuntansi.akun.hapus');

    // F-13a: pemetaan akun (ubah & hapus override outlet).
    Route::put('/akuntansi/pemetaan', [PemetaanAkunKontroler::class, 'Simpan'])->name('kelola.akuntansi.pemetaan.simpan');
    Route::delete('/akuntansi/pemetaan', [PemetaanAkunKontroler::class, 'Hapus'])->name('kelola.akuntansi.pemetaan.hapus');
    // Audit kemudahan pakai #12: lengkapi akun & pemetaan yang belum ada dari template sektor (aditif).
    Route::post('/akuntansi/pemetaan/perbaiki-otomatis', [PemetaanAkunKontroler::class, 'PerbaikiOtomatis'])->middleware('throttle:10,1')->name('kelola.akuntansi.pemetaan.perbaiki-otomatis');

    // FIN-05 (D-131): catat jurnal umum manual dan dokumen pembaliknya.
    Route::get('/akuntansi/jurnal-umum/buat', [JurnalUmumKontroler::class, 'Buat'])->name('kelola.akuntansi.jurnal-umum.buat');
    Route::post('/akuntansi/jurnal-umum', [JurnalUmumKontroler::class, 'Simpan'])->middleware('throttle:60,1')->name('kelola.akuntansi.jurnal-umum.simpan');
    Route::post('/akuntansi/jurnal-umum/{jurnalUmum}/pembalik', [JurnalUmumKontroler::class, 'Balikkan'])->where('jurnalUmum', $ulid)->middleware('throttle:60,1')->name('kelola.akuntansi.jurnal-umum.pembalik');

    // F8 (FIN-11): susun anggaran tahunan per akun & outlet, simpan, dan salin dari tahun lalu.
    Route::get('/akuntansi/anggaran/susun', [AnggaranKontroler::class, 'Susun'])->name('kelola.akuntansi.anggaran.susun');
    Route::put('/akuntansi/anggaran', [AnggaranKontroler::class, 'Simpan'])->middleware('throttle:30,1')->name('kelola.akuntansi.anggaran.simpan');
    Route::post('/akuntansi/anggaran/salin', [AnggaranKontroler::class, 'Salin'])->middleware('throttle:30,1')->name('kelola.akuntansi.anggaran.salin');

    // F-13a: transaksi kas & bank (halaman catat, simpan & pembalik).
    Route::get('/akuntansi/kas-bank/buat', [TransaksiKasBankKontroler::class, 'Buat'])->name('kelola.akuntansi.kas-bank.buat');
    Route::post('/akuntansi/kas-bank', [TransaksiKasBankKontroler::class, 'Simpan'])->middleware('throttle:60,1')->name('kelola.akuntansi.kas-bank.simpan');
    Route::put('/akuntansi/kas-bank/berulang/{jadwalKasBank}', [JadwalKasBankKontroler::class, 'Ubah'])->where('jadwalKasBank', $ulid)->name('kelola.akuntansi.kas-bank.berulang.ubah');
    // F-15: kunci & buka kunci periode (YYYY-MM).
    Route::post('/akuntansi/tutup-buku/{periode}/kunci', [TutupBukuKontroler::class, 'Kunci'])->where('periode', '\d{4}-\d{2}')->name('kelola.akuntansi.tutup-buku.kunci');
    Route::post('/akuntansi/tutup-buku/{periode}/buka-kunci', [TutupBukuKontroler::class, 'BukaKunci'])->where('periode', '\d{4}-\d{2}')->name('kelola.akuntansi.tutup-buku.buka-kunci');
    // F-15 tutup tahun (J-15.1).
    Route::post('/akuntansi/tutup-buku/tahun/{tahun}/tutup', [TutupBukuKontroler::class, 'TutupTahun'])->where('tahun', '\d{4}')->name('kelola.akuntansi.tutup-buku.tutup-tahun');

    Route::post('/akuntansi/kas-bank/{transaksiKasBank}/pembalik', [TransaksiKasBankKontroler::class, 'Balikkan'])->where('transaksiKasBank', $ulid)->name('kelola.akuntansi.kas-bank.pembalik');

    // F-08 BR-08.4: catat & batalkan pencairan. Rute `buat` didaftarkan sebelum `{pencairan}` agar tidak tertangkap
    // pola ULID-nya (pola `where` sudah mencegahnya, urutan ini sekadar membuat maksudnya jelas).
    Route::get('/akuntansi/pencairan/buat', [PencairanKontroler::class, 'Buat'])->name('kelola.akuntansi.pencairan.buat');
    Route::post('/akuntansi/pencairan', [PencairanKontroler::class, 'Simpan'])->middleware('throttle:60,1')->name('kelola.akuntansi.pencairan.simpan');
    Route::post('/akuntansi/pencairan/{pencairan}/batalkan', [PencairanKontroler::class, 'Batalkan'])->where('pencairan', $ulid)->name('kelola.akuntansi.pencairan.batalkan');
    // E8: tutup (kembalikan / akui pendapatan lain) dan buka kembali uang QRIS tanpa penjualan.
    Route::post('/akuntansi/qris-tanpa-penjualan/{tagihan}/tutup', [TagihanQrisTanpaPenjualanKontroler::class, 'Tutup'])->where('tagihan', $ulid)->middleware('throttle:60,1')->name('kelola.akuntansi.qris-tanpa-penjualan.tutup');
    Route::post('/akuntansi/qris-tanpa-penjualan/{tagihan}/buka-kembali', [TagihanQrisTanpaPenjualanKontroler::class, 'BukaKembali'])->where('tagihan', $ulid)->middleware('throttle:60,1')->name('kelola.akuntansi.qris-tanpa-penjualan.buka-kembali');
    // FIN-10 (v3.38): catat, susutkan, lepas, batalkan aset tetap. `buat` sebelum `{asetTetap}` (pola pencairan).
    Route::get('/akuntansi/aset-tetap/buat', [AsetTetapKontroler::class, 'Buat'])->name('kelola.akuntansi.aset-tetap.buat');
    Route::post('/akuntansi/aset-tetap', [AsetTetapKontroler::class, 'Simpan'])->middleware('throttle:60,1')->name('kelola.akuntansi.aset-tetap.simpan');
    Route::post('/akuntansi/aset-tetap/susutkan', [AsetTetapKontroler::class, 'Susutkan'])->middleware('throttle:20,1')->name('kelola.akuntansi.aset-tetap.susutkan');
    Route::post('/akuntansi/aset-tetap/{asetTetap}/lepas', [AsetTetapKontroler::class, 'Lepas'])->where('asetTetap', $ulid)->name('kelola.akuntansi.aset-tetap.lepas');
    Route::post('/akuntansi/aset-tetap/{asetTetap}/batalkan', [AsetTetapKontroler::class, 'Batalkan'])->where('asetTetap', $ulid)->name('kelola.akuntansi.aset-tetap.batalkan');
    // FIN-09 (v3.39): impor rekening koran, cocokkan otomatis, putuskan satu mutasi.
    Route::post('/akuntansi/rekonsiliasi/{akun}/impor', [RekonsiliasiBankKontroler::class, 'Impor'])->where('akun', $ulid)->middleware('throttle:20,1')->name('kelola.akuntansi.rekonsiliasi.impor');
    Route::post('/akuntansi/rekonsiliasi/{akun}/cocokkan-otomatis', [RekonsiliasiBankKontroler::class, 'CocokkanOtomatis'])->where('akun', $ulid)->name('kelola.akuntansi.rekonsiliasi.otomatis');
    Route::post('/akuntansi/rekonsiliasi/mutasi/{mutasiBank}', [RekonsiliasiBankKontroler::class, 'Putuskan'])->where('mutasiBank', $ulid)->name('kelola.akuntansi.rekonsiliasi.putuskan');
    // v3.42 (F-12): giro cair ke bank atau ditolak (pelunasan/pembayaran asalnya dibatalkan).
    Route::post('/akuntansi/giro/{giro}/cair', [GiroKontroler::class, 'Cairkan'])->where('giro', $ulid)->name('kelola.akuntansi.giro.cair');
    Route::post('/akuntansi/giro/{giro}/tolak', [GiroKontroler::class, 'Tolak'])->where('giro', $ulid)->name('kelola.akuntansi.giro.tolak');
});
