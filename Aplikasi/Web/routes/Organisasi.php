<?php

declare(strict_types=1);

use App\Domain\Organisasi\Enum\IzinTenant;
use App\Http\Kontroler\Kelola\GudangKontroler;
use App\Http\Kontroler\Kelola\LogAuditKontroler;
use App\Http\Kontroler\Kelola\MejaKontroler;
use App\Http\Kontroler\Kelola\MerekKontroler;
use App\Http\Kontroler\Kelola\OutletKontroler;
use App\Http\Kontroler\Kelola\PenggunaKontroler;
use App\Http\Kontroler\Kelola\PeranKontroler;
use App\Http\Kontroler\Kelola\TimKontroler;
use App\Http\Perantara\SiapkanAuditTenant;
use App\Http\Perantara\WajibIzinTenant;
use Illuminate\Support\Facades\Route;

/*
 * Rute back-office F-02 Setup Organisasi (PRD §13.6, D-06). Didaftarkan dari routes/web.php di dalam grup
 * `/kelola` (auth + IdentifikasiTenantSesi). ID di URL adalah ULID publik; data dicari di dalam scope tenant aktif.
 */

$izin = static fn (IzinTenant $izin): string => WajibIzinTenant::class.':'.$izin->value;
// D-101: salah satu dari beberapa izin cukup.
$izinSalahSatu = static fn (IzinTenant ...$izin): string => WajibIzinTenant::class.':'.implode('|', array_map(static fn (IzinTenant $i): string => $i->value, $izin));

Route::middleware(SiapkanAuditTenant::class)->group(function () use ($izin, $izinSalahSatu): void {
    // Outlet, lokasi stok, merek (F-02 langkah 1–2).
    Route::get('/outlet', [OutletKontroler::class, 'Daftar'])->middleware($izin(IzinTenant::OutletLihat))->name('kelola.outlet.daftar');
    Route::get('/outlet/buat', [OutletKontroler::class, 'Buat'])->middleware($izin(IzinTenant::OutletKelola))->name('kelola.outlet.buat');
    Route::post('/outlet', [OutletKontroler::class, 'Simpan'])->middleware($izin(IzinTenant::OutletKelola))->name('kelola.outlet.simpan');
    Route::get('/outlet/{outlet}', [OutletKontroler::class, 'Detail'])->middleware($izin(IzinTenant::OutletLihat))->name('kelola.outlet.detail');
    Route::put('/outlet/{outlet}', [OutletKontroler::class, 'Ubah'])->middleware($izin(IzinTenant::OutletKelola))->name('kelola.outlet.ubah');
    Route::post('/outlet/{outlet}/arsipkan', [OutletKontroler::class, 'Arsipkan'])->middleware($izin(IzinTenant::OutletKelola))->name('kelola.outlet.arsipkan');
    Route::post('/outlet/{outlet}/jenis-pesanan', [OutletKontroler::class, 'AturJenisPesanan'])->middleware($izin(IzinTenant::OutletKelola))->name('kelola.outlet.jenis-pesanan');
    Route::post('/outlet/{outlet}/jam-operasional', [OutletKontroler::class, 'AturJamOperasional'])->middleware($izin(IzinTenant::OutletKelola))->name('kelola.outlet.jam-operasional');
    Route::post('/outlet/{outlet}/lokasi-absensi', [OutletKontroler::class, 'AturLokasiAbsensi'])->middleware($izin(IzinTenant::OutletKelola))->name('kelola.outlet.lokasi-absensi');
    // F-17 bagian 4: kios pesan sendiri outlet (sakelar + tautan rahasia).
    Route::post('/outlet/{outlet}/kios', [OutletKontroler::class, 'AturKios'])->middleware($izin(IzinTenant::OutletKelola))->name('kelola.outlet.kios');
    Route::post('/outlet/{outlet}/kios/buat-ulang', [OutletKontroler::class, 'BuatUlangKios'])->middleware($izin(IzinTenant::OutletKelola))->name('kelola.outlet.kios.buat-ulang');
    Route::post('/outlet/{outlet}/layar-absensi', [OutletKontroler::class, 'BuatLayarAbsensi'])->middleware($izin(IzinTenant::OutletKelola))->name('kelola.outlet.layar-absensi.buat');
    Route::delete('/outlet/{outlet}/layar-absensi', [OutletKontroler::class, 'CabutLayarAbsensi'])->middleware($izin(IzinTenant::OutletKelola))->name('kelola.outlet.layar-absensi.cabut');
    Route::post('/outlet/{outlet}/wajib-qr-absensi', [OutletKontroler::class, 'AturWajibQrAbsensi'])->middleware($izin(IzinTenant::OutletKelola))->name('kelola.outlet.wajib-qr-absensi');
    Route::post('/outlet/{outlet}/pulihkan', [OutletKontroler::class, 'Pulihkan'])->middleware($izin(IzinTenant::OutletKelola))->name('kelola.outlet.pulihkan');

    Route::post('/outlet/{outlet}/gudang', [GudangKontroler::class, 'Simpan'])->middleware($izin(IzinTenant::OutletKelola))->name('kelola.gudang.simpan');
    Route::put('/outlet/{outlet}/gudang/{gudang}', [GudangKontroler::class, 'Ubah'])->middleware($izin(IzinTenant::OutletKelola))->name('kelola.gudang.ubah');
    Route::post('/outlet/{outlet}/gudang/{gudang}/arsipkan', [GudangKontroler::class, 'Arsipkan'])->middleware($izin(IzinTenant::OutletKelola))->name('kelola.gudang.arsipkan');
    Route::post('/outlet/{outlet}/gudang/{gudang}/pulihkan', [GudangKontroler::class, 'Pulihkan'])->middleware($izin(IzinTenant::OutletKelola))->name('kelola.gudang.pulihkan');

    // F-10a area & meja per outlet (mode meja).
    Route::post('/outlet/{outlet}/area-meja', [MejaKontroler::class, 'SimpanArea'])->middleware($izin(IzinTenant::OutletKelola))->name('kelola.area-meja.simpan');
    Route::put('/outlet/{outlet}/area-meja/{areaMeja}', [MejaKontroler::class, 'UbahArea'])->middleware($izin(IzinTenant::OutletKelola))->name('kelola.area-meja.ubah');
    Route::post('/outlet/{outlet}/area-meja/{areaMeja}/arsipkan', [MejaKontroler::class, 'ArsipkanArea'])->middleware($izin(IzinTenant::OutletKelola))->name('kelola.area-meja.arsipkan');
    Route::post('/outlet/{outlet}/area-meja/{areaMeja}/pulihkan', [MejaKontroler::class, 'PulihkanArea'])->middleware($izin(IzinTenant::OutletKelola))->name('kelola.area-meja.pulihkan');
    Route::post('/outlet/{outlet}/meja', [MejaKontroler::class, 'Simpan'])->middleware($izin(IzinTenant::OutletKelola))->name('kelola.meja.simpan');
    Route::post('/outlet/{outlet}/meja/massal', [MejaKontroler::class, 'SimpanMassal'])->middleware($izin(IzinTenant::OutletKelola))->name('kelola.meja.simpan-massal');
    Route::put('/outlet/{outlet}/denah-meja', [MejaKontroler::class, 'SimpanDenah'])->middleware($izin(IzinTenant::OutletKelola))->name('kelola.denah-meja.simpan');
    Route::put('/outlet/{outlet}/meja/{meja}', [MejaKontroler::class, 'Ubah'])->middleware($izin(IzinTenant::OutletKelola))->name('kelola.meja.ubah');
    Route::post('/outlet/{outlet}/meja/{meja}/arsipkan', [MejaKontroler::class, 'Arsipkan'])->middleware($izin(IzinTenant::OutletKelola))->name('kelola.meja.arsipkan');
    Route::post('/outlet/{outlet}/meja/{meja}/pulihkan', [MejaKontroler::class, 'Pulihkan'])->middleware($izin(IzinTenant::OutletKelola))->name('kelola.meja.pulihkan');
    // F-17 Self-Order QR Meja: sakelar outlet, QR per meja (token dibuat saat pertama ditampilkan), cetak semua QR,
    // dan buat ulang QR (URL lama tidak berlaku).
    Route::post('/outlet/{outlet}/pesan-sendiri', [MejaKontroler::class, 'AturPesanSendiri'])->middleware($izin(IzinTenant::OutletKelola))->name('kelola.outlet.pesan-sendiri');
    Route::get('/outlet/{outlet}/meja/qr', [MejaKontroler::class, 'CetakQr'])->middleware($izin(IzinTenant::OutletLihat))->name('kelola.meja.qr.cetak');
    Route::get('/outlet/{outlet}/meja/{meja}/qr', [MejaKontroler::class, 'Qr'])->middleware($izin(IzinTenant::OutletLihat))->name('kelola.meja.qr');
    Route::post('/outlet/{outlet}/meja/{meja}/qr/buat-ulang', [MejaKontroler::class, 'BuatUlangQr'])->middleware($izin(IzinTenant::OutletKelola))->name('kelola.meja.qr.buat-ulang');

    Route::post('/merek', [MerekKontroler::class, 'Simpan'])->middleware($izin(IzinTenant::OutletKelola))->name('kelola.merek.simpan');
    Route::put('/merek/{merek}', [MerekKontroler::class, 'Ubah'])->middleware($izin(IzinTenant::OutletKelola))->name('kelola.merek.ubah');
    Route::delete('/merek/{merek}', [MerekKontroler::class, 'Hapus'])->middleware($izin(IzinTenant::OutletKelola))->name('kelola.merek.hapus');

    // Tim (D-101): satu daftar & satu formulir untuk akun (pengguna) dan data karyawan.
    Route::get('/tim', [TimKontroler::class, 'Daftar'])->middleware($izinSalahSatu(IzinTenant::KaryawanLihat, IzinTenant::PenggunaLihat))->name('kelola.tim.daftar');
    Route::get('/tim/buat', [TimKontroler::class, 'Buat'])->middleware($izinSalahSatu(IzinTenant::PenggunaUndang, IzinTenant::KaryawanKelola))->name('kelola.tim.buat');
    Route::post('/tim', [TimKontroler::class, 'Simpan'])->middleware($izinSalahSatu(IzinTenant::PenggunaUndang, IzinTenant::KaryawanKelola))->name('kelola.tim.simpan');
    Route::post('/tim/status', [TimKontroler::class, 'Status'])->middleware($izinSalahSatu(IzinTenant::PenggunaNonaktifkan, IzinTenant::KaryawanKelola))->name('kelola.tim.status');

    // Akses & undangan anggota (F-02 langkah 3, §19.1). Daftar & tambah ada di Tim (D-101).
    Route::get('/pengguna/undangan/buat', [PenggunaKontroler::class, 'BuatUndangan'])->middleware($izin(IzinTenant::PenggunaUndang))->name('kelola.pengguna.undangan.formulir');
    Route::post('/pengguna/undangan', [PenggunaKontroler::class, 'Undang'])->middleware($izin(IzinTenant::PenggunaUndang))->name('kelola.pengguna.undangan.buat');
    Route::post('/pengguna/undangan/{undangan}/batalkan', [PenggunaKontroler::class, 'BatalkanUndangan'])->middleware($izin(IzinTenant::PenggunaUndang))->name('kelola.pengguna.undangan.batalkan');
    Route::post('/pengguna/massal', [PenggunaKontroler::class, 'Massal'])->middleware($izin(IzinTenant::PenggunaLihat))->name('kelola.pengguna.massal');
    Route::post('/pengguna/{pengguna}/karyawan', [PenggunaKontroler::class, 'CatatSebagaiKaryawan'])->middleware($izin(IzinTenant::KaryawanKelola))->name('kelola.pengguna.karyawan');
    Route::put('/pengguna/{pengguna}/akses', [PenggunaKontroler::class, 'UbahAkses'])->middleware($izin(IzinTenant::PenggunaUbah))->name('kelola.pengguna.akses');

    Route::get('/peran', [PeranKontroler::class, 'Daftar'])->middleware($izin(IzinTenant::PenggunaLihat))->name('kelola.peran.daftar');
    Route::get('/peran/buat', [PeranKontroler::class, 'Buat'])->middleware($izin(IzinTenant::PeranKelola))->name('kelola.peran.buat');
    Route::post('/peran', [PeranKontroler::class, 'Simpan'])->middleware($izin(IzinTenant::PeranKelola))->name('kelola.peran.simpan');
    Route::put('/peran/{peran}', [PeranKontroler::class, 'Ubah'])->middleware($izin(IzinTenant::PeranKelola))->name('kelola.peran.ubah');
    Route::delete('/peran/{peran}', [PeranKontroler::class, 'Hapus'])->middleware($izin(IzinTenant::PeranKelola))->name('kelola.peran.hapus');

    // Log audit tenant (§25 no. 17).
    Route::get('/log-audit', [LogAuditKontroler::class, 'Daftar'])->middleware($izin(IzinTenant::AuditLihat))->name('kelola.log-audit.daftar');
});
