<?php

declare(strict_types=1);

use App\Domain\Organisasi\Enum\IzinTenant;
use App\Http\Kontroler\Kelola\Piutang\PiutangKontroler;
use App\Http\Perantara\EksporDiLatarBelakang;
use App\Http\Perantara\SiapkanAuditTenant;
use App\Http\Perantara\WajibIzinTenant;
use Illuminate\Support\Facades\Route;

/*
 * Rute back-office F-12 piutang pelanggan (PRD "Rincian F-12", D-06). Didaftarkan dari routes/web.php di dalam grup
 * `/kelola`. Lihat piutang & pelunasan: `pelanggan.lihat`; posting & batalkan pelunasan: `akuntansi.kelola`.
 */

$izin = static fn (IzinTenant $izin): string => WajibIzinTenant::class.':'.$izin->value;
$ulid = '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}';

Route::middleware([SiapkanAuditTenant::class, $izin(IzinTenant::PelangganLihat)])->prefix('piutang')->group(function () use ($izin, $ulid): void {
    Route::get('/', [PiutangKontroler::class, 'Piutang'])->name('kelola.piutang.daftar');
    Route::get('/ekspor', [PiutangKontroler::class, 'EksporPiutang'])->middleware(EksporDiLatarBelakang::class)->name('kelola.piutang.ekspor');
    Route::get('/pelunasan', [PiutangKontroler::class, 'Daftar'])->name('kelola.piutang.pelunasan.daftar');
    // v3.37: nota tagihan pelanggan (cetak/PDF semua piutang terbuka satu pelanggan).
    Route::get('/tagihan/{pelanggan}', [PiutangKontroler::class, 'NotaTagihan'])->where('pelanggan', $ulid)->name('kelola.piutang.tagihan');

    Route::middleware($izin(IzinTenant::AkuntansiKelola))->group(function () use ($ulid): void {
        Route::get('/pelunasan/buat', [PiutangKontroler::class, 'Buat'])->name('kelola.piutang.pelunasan.buat');
        Route::post('/pelunasan', [PiutangKontroler::class, 'Simpan'])->name('kelola.piutang.pelunasan.simpan');
        Route::post('/pelunasan/{pelunasan}/batalkan', [PiutangKontroler::class, 'Batalkan'])->where('pelunasan', $ulid)->name('kelola.piutang.pelunasan.batalkan');
        // F-21 bagian 5: nomor bukti potong PPh yang diterima belakangan dari pelanggan.
        Route::post('/pelunasan/{pelunasan}/bukti-potong', [PiutangKontroler::class, 'CatatBuktiPotong'])->where('pelunasan', $ulid)->name('kelola.piutang.pelunasan.bukti-potong');
        Route::post('/{piutang}/hapus-buku', [PiutangKontroler::class, 'HapusBuku'])->where('piutang', $ulid)->name('kelola.piutang.hapus-buku.simpan');
        Route::post('/hapus-buku/{hapusBuku}/batalkan', [PiutangKontroler::class, 'BatalkanHapusBuku'])->where('hapusBuku', $ulid)->name('kelola.piutang.hapus-buku.batalkan');
    });

    // E8: hapus-buku piutang yang tidak akan tertagih.
    Route::get('/hapus-buku', [PiutangKontroler::class, 'DaftarHapusBuku'])->name('kelola.piutang.hapus-buku.daftar');

    // D-23 D: pengingat piutang ke pelanggan (kirim sekarang & pengaturan otomatis).
    Route::middleware($izin(IzinTenant::PelangganKelola))->group(function () use ($ulid): void {
        Route::put('/pengingat-otomatis', [PiutangKontroler::class, 'SimpanPengingat'])->name('kelola.piutang.pengingat.pengaturan');
        Route::post('/pengingat-massal', [PiutangKontroler::class, 'KirimPengingatMassal'])->name('kelola.piutang.pengingat.massal');
        Route::post('/{piutang}/pengingat', [PiutangKontroler::class, 'KirimPengingat'])->where('piutang', $ulid)->name('kelola.piutang.pengingat.kirim');
    });

    Route::get('/pelunasan/{pelunasan}', [PiutangKontroler::class, 'Detail'])->where('pelunasan', $ulid)->name('kelola.piutang.pelunasan.detail');
});
