<?php

declare(strict_types=1);

use App\Http\Kontroler\Kelola\UnduhanKontroler;
use App\Http\Perantara\SiapkanAuditTenant;
use Illuminate\Support\Facades\Route;

/*
 * D-124 Pusat Unduhan: berkas ekspor laporan besar milik pengguna yang sedang masuk. Tanpa izin khusus, karena baris
 * yang tampil selalu milik pengguna itu sendiri; izin ekspor diperiksa di rute laporan asalnya saat berkas diminta.
 * Didaftarkan dari routes/web.php di dalam grup `/kelola`.
 */
$ulid = '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}';

Route::middleware(SiapkanAuditTenant::class)->group(function () use ($ulid): void {
    Route::get('/unduhan', [UnduhanKontroler::class, 'Daftar'])->name('kelola.unduhan.daftar');
    Route::get('/unduhan/{uuidBerkas}/unduh', [UnduhanKontroler::class, 'Unduh'])->where('uuidBerkas', $ulid)->middleware('throttle:30,1')->name('kelola.unduhan.unduh');
    Route::delete('/unduhan/{uuidBerkas}', [UnduhanKontroler::class, 'Hapus'])->where('uuidBerkas', $ulid)->name('kelola.unduhan.hapus');
});
