<?php

declare(strict_types=1);

use App\Domain\Organisasi\Enum\IzinTenant;
use App\Http\Kontroler\Pemilik\V1\AutentikasiKontroler;
use App\Http\Kontroler\Pemilik\V1\DasborKontroler;
use App\Http\Kontroler\Pemilik\V1\InsightKontroler;
use App\Http\Kontroler\Pemilik\V1\KaryawanKontroler;
use App\Http\Kontroler\Pemilik\V1\LaporanKontroler;
use App\Http\Kontroler\Pemilik\V1\NotifikasiKontroler;
use App\Http\Kontroler\Pemilik\V1\PengumumanKontroler;
use App\Http\Kontroler\Pemilik\V1\PerangkatKontroler;
use App\Http\Kontroler\Pemilik\V1\PersetujuanKontroler;
use App\Http\Perantara\AutentikasiPemilik;
use App\Http\Perantara\IdentifikasiTenantPemilik;
use App\Http\Perantara\WajibFiturPaket;
use App\Http\Perantara\WajibIzinPemilik;
use Illuminate\Support\Facades\Route;

/*
 * API Aplikasi Owner Flutter (PRD §10.2a OWN-01/02/05/08, §16.1, §17.3.4), didaftarkan dari bootstrap/app.php dengan
 * prefix `/api/pemilik/v1` dan grup `api` (tanpa sesi/CSRF). Autentikasi user token lewat `AutentikasiPemilik`; tenant
 * dipilih per permintaan dengan header `X-Tenant` (`IdentifikasiTenantPemilik`). Galat selalu berformat
 * `{"Galat": {"Kode", "Pesan", "Detail"}}`. Kontrak kompatibel mundur 2 versi minor aplikasi. Batas laju
 * `throttle:pemilik-N` = N per menit per rute per pengguna (per IP sebelum masuk), lihat PenyediaAplikasi.
 */

$izin = fn (IzinTenant $izin): string => WajibIzinPemilik::class.':'.$izin->value;

// OWN-01: masuk (email + kata sandi), langkah kedua 2FA. Percobaan kata sandi/kode juga dibatasi di kontroler.
Route::post('/masuk', [AutentikasiKontroler::class, 'Masuk'])->middleware('throttle:pemilik-30')->name('pemilik.masuk');
Route::post('/masuk/dua-faktor', [AutentikasiKontroler::class, 'MasukDuaFaktor'])->middleware('throttle:pemilik-30')->name('pemilik.masuk.dua-faktor');

Route::post('/lupa-kata-sandi', [AutentikasiKontroler::class, 'LupaKataSandi'])->middleware('throttle:pemilik-30')->name('pemilik.lupa-kata-sandi');

// D-57: Masuk dengan Google (menggantikan 2FA). Konfigurasi dibaca aplikasi sebelum menampilkan tombol.
Route::get('/masuk/google/konfigurasi', [AutentikasiKontroler::class, 'KonfigurasiGoogle'])->middleware('throttle:pemilik-30')->name('pemilik.masuk.google.konfigurasi');
Route::post('/masuk/google', [AutentikasiKontroler::class, 'MasukGoogle'])->middleware('throttle:pemilik-30')->name('pemilik.masuk.google');

Route::middleware(AutentikasiPemilik::class)->group(function () use ($izin): void {
    Route::post('/keluar', [AutentikasiKontroler::class, 'Keluar'])->middleware('throttle:pemilik-30')->name('pemilik.keluar');
    Route::get('/profil', [AutentikasiKontroler::class, 'Profil'])->middleware('throttle:pemilik-60')->name('pemilik.profil');

    Route::middleware([IdentifikasiTenantPemilik::class, WajibFiturPaket::class, 'throttle:pemilik-60'])->group(function () use ($izin): void {
        // OWN-02: dasbor (polling 60 detik saat layar aktif, §17.3.3).
        Route::get('/dasbor', [DasborKontroler::class, 'Tampilkan'])->middleware($izin(IzinTenant::LaporanPenjualanLihat))->name('pemilik.dasbor');

        // OWN-05: laporan ringkas penjualan & shift.
        Route::get('/laporan/penjualan', [LaporanKontroler::class, 'Penjualan'])->middleware($izin(IzinTenant::LaporanPenjualanLihat))->name('pemilik.laporan.penjualan');
        Route::get('/laporan/laba-rugi', [LaporanKontroler::class, 'LabaRugi'])->middleware($izin(IzinTenant::LaporanKeuanganLihat))->name('pemilik.laporan.laba-rugi');
        // OWN-09: skor risiko kasir (sama dengan tab Anti-fraud di back-office).
        Route::get('/laporan/anti-fraud', [LaporanKontroler::class, 'AntiFraud'])->middleware($izin(IzinTenant::LaporanPenjualanLihat))->name('pemilik.laporan.anti-fraud');
        // OWN-11: insight mingguan (sama dengan pesan WhatsApp X6).
        Route::get('/insight', [InsightKontroler::class, 'Mingguan'])->middleware($izin(IzinTenant::LaporanPenjualanLihat))->name('pemilik.insight');
        Route::get('/shift', [LaporanKontroler::class, 'Shift'])->middleware($izin(IzinTenant::LaporanPenjualanLihat))->name('pemilik.shift');

        // OWN-03 / X4: persetujuan jarak jauh (izin diperiksa per permintaan: izin yang diminta kasir atau pemilik).
        Route::get('/persetujuan', [PersetujuanKontroler::class, 'Daftar'])->name('pemilik.persetujuan');
        Route::post('/persetujuan/{persetujuan}/setujui', [PersetujuanKontroler::class, 'Setujui'])
            ->where('persetujuan', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')->name('pemilik.persetujuan.setujui');
        Route::post('/persetujuan/{persetujuan}/tolak', [PersetujuanKontroler::class, 'Tolak'])
            ->where('persetujuan', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')->name('pemilik.persetujuan.tolak');

        // OWN-03: pusat notifikasi persisten + pendaftaran token FCM pemasangan Aplikasi Owner.
        Route::get('/notifikasi', [NotifikasiKontroler::class, 'Daftar'])->name('pemilik.notifikasi');
        Route::patch('/notifikasi', [NotifikasiKontroler::class, 'TandaiDibaca'])->name('pemilik.notifikasi.dibaca');
        Route::post('/token-notifikasi', [NotifikasiKontroler::class, 'DaftarkanToken'])->name('pemilik.token-notifikasi');

        // P-10 PGL-19 (v3.47): pengumuman & jadwal pemeliharaan platform; tanpa izin khusus.
        Route::get('/pengumuman', [PengumumanKontroler::class, 'Daftar'])->name('pemilik.pengumuman');

        // OWN-08: status perangkat POS.
        Route::get('/perangkat', [PerangkatKontroler::class, 'Daftar'])->middleware($izin(IzinTenant::PerangkatLihat))->name('pemilik.perangkat');
        Route::post('/perangkat/{perangkat}/cabut', [PerangkatKontroler::class, 'Cabut'])
            ->where('perangkat', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')
            ->middleware([$izin(IzinTenant::PerangkatKelola), 'throttle:pemilik-30'])->name('pemilik.perangkat.cabut');
        // OWN-10: pantau karyawan (kehadiran hari ini, komisi & target bulan berjalan).
        Route::get('/karyawan', [KaryawanKontroler::class, 'Pantau'])->middleware($izin(IzinTenant::KaryawanLihat))->name('pemilik.karyawan');
    });
});
