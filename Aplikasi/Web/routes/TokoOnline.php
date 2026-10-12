<?php

declare(strict_types=1);

use App\Domain\Organisasi\Enum\IzinTenant;
use App\Http\Kontroler\Kelola\TokoOnline\TokoOnlineKontroler;
use App\Http\Perantara\SiapkanAuditTenant;
use App\Http\Perantara\WajibIzinTenant;
use Illuminate\Support\Facades\Route;

$ulid = '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}';

Route::middleware([SiapkanAuditTenant::class, WajibIzinTenant::class.':'.IzinTenant::TokoOnlineKelola->value])->prefix('toko-online')->group(function () use ($ulid): void {
    Route::get('/', [TokoOnlineKontroler::class, 'Tampilkan'])->name('kelola.toko-online');
    Route::get('/poster', [TokoOnlineKontroler::class, 'Poster'])->name('kelola.toko-online.poster');
    Route::put('/pengaturan', [TokoOnlineKontroler::class, 'SimpanPengaturan'])->name('kelola.toko-online.pengaturan');
    Route::post('/zona', [TokoOnlineKontroler::class, 'SimpanZona'])->name('kelola.toko-online.zona.simpan');
    Route::put('/zona/{zona}', [TokoOnlineKontroler::class, 'PerbaruiZona'])->where('zona', $ulid)->name('kelola.toko-online.zona.perbarui');
    Route::post('/pesanan/{pesanan}/status', [TokoOnlineKontroler::class, 'UbahStatusPesanan'])->where('pesanan', $ulid)->name('kelola.toko-online.pesanan.status');
    // F-17 bagian 2 (J-17.2): pengembalian uang muka memindahkan uang dari kas/bank, jadi izinnya akuntansi.
    Route::post('/pesanan/{pesanan}/kembalikan-uang', [TokoOnlineKontroler::class, 'KembalikanUang'])
        ->middleware(WajibIzinTenant::class.':'.IzinTenant::AkuntansiKelola->value)
        ->where('pesanan', $ulid)->name('kelola.toko-online.pesanan.kembalikan-uang');
});

Route::middleware([SiapkanAuditTenant::class, WajibIzinTenant::class.':'.IzinTenant::PengirimanKelola->value])->prefix('pengiriman')->group(function () use ($ulid): void {
    Route::get('/', [TokoOnlineKontroler::class, 'Tampilkan'])->name('kelola.pengiriman');
    Route::post('/kurir', [TokoOnlineKontroler::class, 'SimpanKurir'])->name('kelola.pengiriman.kurir.simpan');
    Route::put('/kurir/{kurir}', [TokoOnlineKontroler::class, 'PerbaruiKurir'])->where('kurir', $ulid)->name('kelola.pengiriman.kurir.perbarui');
    Route::post('/{pengiriman}/status', [TokoOnlineKontroler::class, 'UbahStatusPengiriman'])->where('pengiriman', $ulid)->name('kelola.pengiriman.status');
    // F-10 (v3.49): tautan portal kurir & foto bukti serah terima dari kurir.
    Route::post('/kurir/{kurir}/tautan-portal', [TokoOnlineKontroler::class, 'BuatTautanPortal'])->where('kurir', $ulid)->name('kelola.pengiriman.kurir.tautan-portal');
    Route::delete('/kurir/{kurir}/tautan-portal', [TokoOnlineKontroler::class, 'CabutTautanPortal'])->where('kurir', $ulid)->name('kelola.pengiriman.kurir.tautan-portal.cabut');
    Route::get('/{pengiriman}/bukti', [TokoOnlineKontroler::class, 'UnduhBukti'])->where('pengiriman', $ulid)->name('kelola.pengiriman.bukti');
});
