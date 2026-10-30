<?php

declare(strict_types=1);

use App\Domain\Organisasi\Enum\IzinTenant;
use App\Http\Kontroler\Kelola\Karyawan\AbsenHpKaryawanKontroler;
use App\Http\Kontroler\Kelola\Karyawan\AbsenSayaKontroler;
use App\Http\Kontroler\Kelola\Karyawan\AbsensiKontroler;
use App\Http\Kontroler\Kelola\Karyawan\AturanKehadiranKontroler;
use App\Http\Kontroler\Kelola\Karyawan\CutiKaryawanKontroler;
use App\Http\Kontroler\Kelola\Karyawan\JadwalKerjaKontroler;
use App\Http\Kontroler\Kelola\Karyawan\KaryawanKontroler;
use App\Http\Kontroler\Kelola\Karyawan\KasbonKontroler;
use App\Http\Kontroler\Kelola\Karyawan\KomisiKontroler;
use App\Http\Kontroler\Kelola\Karyawan\RekapGajiKontroler;
use App\Http\Kontroler\Kelola\Karyawan\ShiftKerjaKontroler;
use App\Http\Kontroler\Kelola\Karyawan\TargetPenjualanKontroler;
use App\Http\Perantara\EksporDiLatarBelakang;
use App\Http\Perantara\SiapkanAuditTenant;
use App\Http\Perantara\TolakKirimGanda;
use App\Http\Perantara\WajibIzinTenant;
use Illuminate\Support\Facades\Route;

/*
 * Rute back-office F-18 karyawan (PRD "Rincian F-18 bagian 1", D-06). Didaftarkan dari routes/web.php di dalam grup
 * `/kelola`. Lihat karyawan, jadwal, absensi & swafoto, komisi: `karyawan.lihat`; ubah karyawan, jadwal & aturan
 * komisi (termasuk halaman penuh `/buat`): `karyawan.kelola`.
 */

$izin = static fn (IzinTenant $izin): string => WajibIzinTenant::class.':'.$izin->value;
$ulid = '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}';

// D-180: halaman Absen berlogin. Tanpa izin back-office: syaratnya karyawan aktif yang ikut absensi dan tertaut ke akun
// ini (dicek kontroler; selain itu 404). Dibatasi per akun supaya tebakan massal tidak menekan server wajah.
Route::middleware([SiapkanAuditTenant::class])->prefix('absen')->group(function () use ($ulid): void {
    Route::get('/', [AbsenSayaKontroler::class, 'Tampilkan'])->middleware('throttle:60,1')->name('kelola.absen');
    // D-211: PWA, halaman Absen bisa dipasang ke layar utama HP.
    Route::get('/manifest', [AbsenSayaKontroler::class, 'Manifest'])->middleware('throttle:60,1')->name('kelola.absen.manifest');
    Route::get('/pekerja-layanan', [AbsenSayaKontroler::class, 'PekerjaLayanan'])->middleware('throttle:60,1')->name('kelola.absen.pekerja-layanan');
    Route::post('/wajah', [AbsenSayaKontroler::class, 'DaftarWajah'])->middleware('throttle:absensi-web')->name('kelola.absen.wajah');
    Route::post('/cek-wajah', [AbsenSayaKontroler::class, 'CekWajah'])->middleware('throttle:cek-wajah')->name('kelola.absen.cek-wajah');
    Route::post('/masuk', [AbsenSayaKontroler::class, 'Masuk'])->middleware('throttle:absensi-web')->name('kelola.absen.masuk');
    Route::post('/keluar', [AbsenSayaKontroler::class, 'Keluar'])->middleware('throttle:absensi-web')->name('kelola.absen.keluar');
    Route::post('/minta-koreksi', [AbsenSayaKontroler::class, 'MintaKoreksi'])->middleware('throttle:10,1')->name('kelola.absen.minta-koreksi');
    // D-251: tukar shift antar karyawan dari halaman Absen berlogin.
    Route::post('/tukar-shift', [AbsenSayaKontroler::class, 'AjukanTukarShift'])->middleware('throttle:20,1')->name('kelola.absen.tukar-shift.ajukan');
    Route::post('/tukar-shift/{tukarShift}/tanggapi', [AbsenSayaKontroler::class, 'TanggapiTukarShift'])->where('tukarShift', $ulid)->middleware('throttle:20,1')->name('kelola.absen.tukar-shift.tanggapi');
    Route::post('/tukar-shift/{tukarShift}/batal', [AbsenSayaKontroler::class, 'BatalkanTukarShift'])->where('tukarShift', $ulid)->middleware('throttle:20,1')->name('kelola.absen.tukar-shift.batal');
});

Route::middleware([SiapkanAuditTenant::class, $izin(IzinTenant::KaryawanLihat)])->prefix('karyawan')->group(function () use ($izin, $ulid): void {
    Route::get('/jadwal', [JadwalKerjaKontroler::class, 'Tampil'])->name('kelola.karyawan.jadwal');
    Route::get('/absensi', [AbsensiKontroler::class, 'Daftar'])->name('kelola.karyawan.absensi.daftar');
    // F-18 bagian 5 (D-44): aturan kehadiran (jadwal ↔ absensi ↔ notifikasi).
    Route::get('/aturan-kehadiran', [AturanKehadiranKontroler::class, 'Tampil'])->name('kelola.karyawan.aturan-kehadiran');
    // F-18 bagian 2: aturan & laporan komisi.
    Route::get('/komisi', [KomisiKontroler::class, 'Aturan'])->name('kelola.karyawan.komisi');
    Route::get('/komisi/laporan', [KomisiKontroler::class, 'Laporan'])->name('kelola.karyawan.komisi.laporan');
    Route::get('/komisi/laporan/ekspor', [KomisiKontroler::class, 'EksporLaporan'])->middleware(EksporDiLatarBelakang::class)->name('kelola.karyawan.komisi.laporan.ekspor');
    // F-18 bagian 3: kasbon karyawan (J-18.1).
    Route::get('/kasbon', [KasbonKontroler::class, 'Daftar'])->name('kelola.karyawan.kasbon');
    // F-18 EMP-03: cuti/izin/sakit karyawan (dicatat pengelola; cuti berbayar bukan "tidak masuk" di rekap gaji).
    Route::get('/cuti', [CutiKaryawanKontroler::class, 'Daftar'])->name('kelola.karyawan.cuti');
    // F-18 bagian 3: target penjualan & progres.
    Route::get('/target', [TargetPenjualanKontroler::class, 'Tampil'])->name('kelola.karyawan.target');
    Route::get('/absensi/{absensi}/swafoto/{jenis}', [AbsensiKontroler::class, 'Swafoto'])->where(['absensi' => $ulid, 'jenis' => 'masuk|keluar'])->name('kelola.karyawan.absensi.swafoto');

    Route::middleware($izin(IzinTenant::KaryawanKelola))->group(function () use ($ulid): void {
        // v3.34: koreksi & tambah absensi manual.
        Route::post('/absensi', [AbsensiKontroler::class, 'Tambah'])->name('kelola.karyawan.absensi.tambah');
        Route::put('/aturan-kehadiran', [AturanKehadiranKontroler::class, 'Simpan'])->name('kelola.karyawan.aturan-kehadiran.simpan');
        // F-18 bagian 4 (D-37, K37): kalibrasi ambang kemiripan wajah absensi web.
        Route::get('/absensi/kalibrasi-wajah', [AbsensiKontroler::class, 'KalibrasiWajah'])->name('kelola.karyawan.absensi.kalibrasi-wajah');
        Route::put('/absensi/{absensi}', [AbsensiKontroler::class, 'Koreksi'])->where('absensi', $ulid)->name('kelola.karyawan.absensi.koreksi');
        // D-180: absensi yang lokasinya janggal ditandai sudah dicek; permintaan koreksi dari karyawan ditutup.
        Route::post('/absensi/{absensi}/dicek', [AbsensiKontroler::class, 'TandaiDicek'])->where('absensi', $ulid)->name('kelola.karyawan.absensi.dicek');
        Route::post('/absensi/permintaan-koreksi/{koreksi}/tutup', [AbsensiKontroler::class, 'TutupPermintaan'])->where('koreksi', $ulid)->name('kelola.karyawan.absensi.permintaan-koreksi.tutup');
        Route::put('/{karyawan}', [KaryawanKontroler::class, 'Perbarui'])->where('karyawan', $ulid)->name('kelola.karyawan.perbarui');
        // F-18 bagian 4 (D-37, D-180): wajah terdaftar karyawan (absen lewat login di /kelola/absen).
        Route::get('/{karyawan}/absen-hp', [AbsenHpKaryawanKontroler::class, 'Tampilkan'])->where('karyawan', $ulid)->name('kelola.karyawan.absen-hp');
        Route::post('/{karyawan}/tautan-absen', [AbsenHpKaryawanKontroler::class, 'BuatTautan'])->where('karyawan', $ulid)->name('kelola.karyawan.tautan-absen.buat');
        Route::delete('/{karyawan}/tautan-absen', [AbsenHpKaryawanKontroler::class, 'CabutTautan'])->where('karyawan', $ulid)->name('kelola.karyawan.tautan-absen.cabut');
        Route::post('/{karyawan}/wajah/tinjau', [AbsenHpKaryawanKontroler::class, 'Tinjau'])->where('karyawan', $ulid)->name('kelola.karyawan.wajah.tinjau');
        Route::delete('/{karyawan}/wajah', [AbsenHpKaryawanKontroler::class, 'HapusWajah'])->where('karyawan', $ulid)->name('kelola.karyawan.wajah.hapus');
        Route::get('/{karyawan}/wajah/foto/{indeks}', [AbsenHpKaryawanKontroler::class, 'Foto'])->where(['karyawan' => $ulid, 'indeks' => '[0-9]'])->name('kelola.karyawan.wajah.foto');
        Route::put('/jadwal', [JadwalKerjaKontroler::class, 'Simpan'])->name('kelola.karyawan.jadwal.simpan');
        Route::post('/jadwal/salin', [JadwalKerjaKontroler::class, 'Salin'])->name('kelola.karyawan.jadwal.salin');
        // F-18 D-251: isi massal, pola berulang, kebutuhan staf, umumkan lewat WhatsApp, shift bernama, tukar shift.
        Route::post('/jadwal/isi-massal', [JadwalKerjaKontroler::class, 'IsiMassal'])->middleware('throttle:30,1')->name('kelola.karyawan.jadwal.isi-massal');
        Route::put('/jadwal/pola/{karyawan}', [JadwalKerjaKontroler::class, 'SimpanPola'])->where('karyawan', $ulid)->name('kelola.karyawan.jadwal.pola.simpan');
        Route::post('/jadwal/terapkan-pola', [JadwalKerjaKontroler::class, 'TerapkanPola'])->middleware('throttle:30,1')->name('kelola.karyawan.jadwal.terapkan-pola');
        Route::put('/jadwal/kebutuhan', [JadwalKerjaKontroler::class, 'SimpanKebutuhan'])->name('kelola.karyawan.jadwal.kebutuhan');
        Route::post('/jadwal/umumkan', [JadwalKerjaKontroler::class, 'Umumkan'])->middleware('throttle:6,1')->name('kelola.karyawan.jadwal.umumkan');
        Route::post('/jadwal/shift', [ShiftKerjaKontroler::class, 'Simpan'])->name('kelola.karyawan.jadwal.shift.simpan');
        Route::put('/jadwal/shift/{shift}', [ShiftKerjaKontroler::class, 'Perbarui'])->where('shift', $ulid)->name('kelola.karyawan.jadwal.shift.perbarui');
        Route::delete('/jadwal/shift/{shift}', [ShiftKerjaKontroler::class, 'Hapus'])->where('shift', $ulid)->name('kelola.karyawan.jadwal.shift.hapus');
        Route::post('/jadwal/tukar-shift/{permintaanTukar}/putuskan', [ShiftKerjaKontroler::class, 'PutuskanTukar'])->where('permintaanTukar', $ulid)->name('kelola.karyawan.jadwal.tukar-shift.putuskan');
        Route::get('/komisi/buat', [KomisiKontroler::class, 'Buat'])->name('kelola.karyawan.komisi.buat');
        Route::post('/komisi', [KomisiKontroler::class, 'Simpan'])->name('kelola.karyawan.komisi.simpan');
        Route::put('/komisi/{aturan}', [KomisiKontroler::class, 'Perbarui'])->where('aturan', $ulid)->name('kelola.karyawan.komisi.perbarui');
        Route::post('/komisi/{aturan}/arsipkan', [KomisiKontroler::class, 'Arsipkan'])->where('aturan', $ulid)->name('kelola.karyawan.komisi.arsipkan');
        Route::post('/komisi/{aturan}/pulihkan', [KomisiKontroler::class, 'Pulihkan'])->where('aturan', $ulid)->name('kelola.karyawan.komisi.pulihkan');
        Route::post('/cuti', [CutiKaryawanKontroler::class, 'Simpan'])->middleware('throttle:60,1')->name('kelola.karyawan.cuti.simpan');
        Route::post('/cuti/{cuti}/batal', [CutiKaryawanKontroler::class, 'Batalkan'])->where('cuti', $ulid)->name('kelola.karyawan.cuti.batal');
        Route::post('/kasbon', [KasbonKontroler::class, 'Simpan'])->middleware(['throttle:60,1', TolakKirimGanda::class])->name('kelola.karyawan.kasbon.simpan');
        Route::post('/kasbon/{kasbon}/pelunasan', [KasbonKontroler::class, 'Lunasi'])->where('kasbon', $ulid)->name('kelola.karyawan.kasbon.pelunasan');
        Route::post('/kasbon/{kasbon}/batal', [KasbonKontroler::class, 'Batalkan'])->where('kasbon', $ulid)->name('kelola.karyawan.kasbon.batal');
        // F-18 bagian 3: rekap gaji bulanan (memuat gaji, jadi seluruhnya `karyawan.kelola`).
        Route::get('/gaji', [RekapGajiKontroler::class, 'Daftar'])->name('kelola.karyawan.gaji');
        Route::post('/gaji', [RekapGajiKontroler::class, 'Simpan'])->middleware('throttle:30,1')->name('kelola.karyawan.gaji.simpan');
        Route::get('/gaji/{rekap}', [RekapGajiKontroler::class, 'Detail'])->where('rekap', $ulid)->name('kelola.karyawan.gaji.detail');
        Route::get('/gaji/{rekap}/ekspor', [RekapGajiKontroler::class, 'Ekspor'])->where('rekap', $ulid)->name('kelola.karyawan.gaji.ekspor');
        // v3.35: slip gaji per karyawan (cetak/PDF), `?karyawan=` untuk satu orang.
        Route::get('/gaji/{rekap}/slip', [RekapGajiKontroler::class, 'Slip'])->where('rekap', $ulid)->name('kelola.karyawan.gaji.slip');
        Route::put('/gaji/{rekap}/baris/{karyawan}', [RekapGajiKontroler::class, 'UbahBaris'])->where(['rekap' => $ulid, 'karyawan' => $ulid])->name('kelola.karyawan.gaji.baris');
        Route::post('/gaji/{rekap}/tambahan-massal', [RekapGajiKontroler::class, 'TambahanMassal'])->where('rekap', $ulid)->name('kelola.karyawan.gaji.tambahan-massal');
        Route::post('/gaji/{rekap}/bayar', [RekapGajiKontroler::class, 'Bayar'])->where('rekap', $ulid)->name('kelola.karyawan.gaji.bayar');
        Route::put('/target', [TargetPenjualanKontroler::class, 'Simpan'])->name('kelola.karyawan.target.simpan');
        Route::delete('/target/{target}', [TargetPenjualanKontroler::class, 'Hapus'])->where('target', $ulid)->name('kelola.karyawan.target.hapus');
        Route::delete('/gaji/{rekap}', [RekapGajiKontroler::class, 'Hapus'])->where('rekap', $ulid)->name('kelola.karyawan.gaji.hapus');
    });
});
