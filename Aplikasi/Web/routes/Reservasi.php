<?php

declare(strict_types=1);

use App\Domain\Organisasi\Enum\IzinTenant;
use App\Http\Kontroler\Kelola\Reservasi\ReservasiKontroler;
use App\Http\Kontroler\Kelola\Reservasi\ReservasiMejaKontroler;
use App\Http\Perantara\SiapkanAuditTenant;
use App\Http\Perantara\WajibIzinTenant;
use Illuminate\Support\Facades\Route;

/*
 * Rute back-office F-07 mode service: reservasi layanan (izin `reservasi.kelola`, dibatasi outlet akses). Didaftarkan
 * dari routes/web.php di dalam grup `/kelola`.
 */

$ulid = '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}';

Route::middleware([SiapkanAuditTenant::class, WajibIzinTenant::class.':'.IzinTenant::ReservasiKelola->value])->prefix('reservasi')->group(function () use ($ulid): void {
    Route::get('/', [ReservasiKontroler::class, 'Daftar'])->name('kelola.reservasi.daftar');
    Route::get('/slot', [ReservasiKontroler::class, 'Slot'])->name('kelola.reservasi.slot');
    Route::post('/', [ReservasiKontroler::class, 'Simpan'])->name('kelola.reservasi.simpan');
    Route::put('/pengaturan', [ReservasiKontroler::class, 'SimpanPengaturan'])->name('kelola.reservasi.pengaturan');
    Route::post('/{reservasi}/status', [ReservasiKontroler::class, 'UbahStatus'])->where('reservasi', $ulid)->name('kelola.reservasi.status');
    Route::post('/{reservasi}/jadwal-ulang', [ReservasiKontroler::class, 'JadwalUlang'])->where('reservasi', $ulid)->name('kelola.reservasi.jadwal-ulang');
});

// FnB reservasi meja + uang muka (DP) (§9.1, D-145): izin yang sama dengan reservasi layanan.
Route::middleware([SiapkanAuditTenant::class, WajibIzinTenant::class.':'.IzinTenant::ReservasiKelola->value])->prefix('reservasi-meja')->group(function () use ($ulid): void {
    Route::get('/', [ReservasiMejaKontroler::class, 'Daftar'])->name('kelola.reservasi-meja.daftar');
    Route::post('/', [ReservasiMejaKontroler::class, 'Simpan'])->name('kelola.reservasi-meja.simpan');
    Route::put('/{reservasi}', [ReservasiMejaKontroler::class, 'Perbarui'])->where('reservasi', $ulid)->name('kelola.reservasi-meja.perbarui');
    Route::post('/{reservasi}/status', [ReservasiMejaKontroler::class, 'UbahStatus'])->where('reservasi', $ulid)->name('kelola.reservasi-meja.status');
    Route::post('/{reservasi}/uang-muka', [ReservasiMejaKontroler::class, 'CatatUangMuka'])->where('reservasi', $ulid)->name('kelola.reservasi-meja.uang-muka');
    Route::post('/{reservasi}/uang-muka/selesai', [ReservasiMejaKontroler::class, 'SelesaikanUangMuka'])->where('reservasi', $ulid)->name('kelola.reservasi-meja.uang-muka.selesai');
});
