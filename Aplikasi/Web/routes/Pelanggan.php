<?php

declare(strict_types=1);

use App\Domain\Organisasi\Enum\IzinTenant;
use App\Http\Kontroler\Kelola\Pelanggan\DepositPelangganKontroler;
use App\Http\Kontroler\Kelola\Pelanggan\GiftCardKontroler;
use App\Http\Kontroler\Kelola\Pelanggan\ImporPelangganKontroler;
use App\Http\Kontroler\Kelola\Pelanggan\KampanyePesanKontroler;
use App\Http\Kontroler\Kelola\Pelanggan\LoyaltiKontroler;
use App\Http\Kontroler\Kelola\Pelanggan\PelangganKontroler;
use App\Http\Kontroler\Kelola\Pelanggan\SesiPelangganKontroler;
use App\Http\Perantara\SiapkanAuditTenant;
use App\Http\Perantara\WajibIzinTenant;
use Illuminate\Support\Facades\Route;

/*
 * Rute back-office F-16a pelanggan (PRD "Rincian F-16a", D-06). Didaftarkan dari routes/web.php di dalam grup
 * `/kelola`. Lihat daftar & detail: `pelanggan.lihat`; tambah/ubah/arsip: `pelanggan.kelola`. Parameter dibatasi pola
 * ULID dan dicari lewat `MilikTenant` (pelanggan tenant lain = 404).
 */

$izin = static fn (IzinTenant $izin): string => WajibIzinTenant::class.':'.$izin->value;
$ulid = '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}';

Route::middleware([SiapkanAuditTenant::class, $izin(IzinTenant::PelangganLihat)])->prefix('pelanggan')->group(function () use ($izin, $ulid): void {
    Route::get('/', [PelangganKontroler::class, 'Daftar'])->name('kelola.pelanggan.daftar');
    // F-16b: tier pelanggan & pengaturan loyalti.
    Route::get('/tier', [LoyaltiKontroler::class, 'Tier'])->name('kelola.pelanggan.tier.daftar');
    Route::get('/loyalti', [LoyaltiKontroler::class, 'Pengaturan'])->name('kelola.pelanggan.loyalti');
    // F-16d bagian 1: daftar isi deposit & tautan sumber jurnal deposit (sebelum rute {pelanggan}).
    Route::get('/isi-deposit', [DepositPelangganKontroler::class, 'DaftarIsi'])->name('kelola.pelanggan.isi-deposit.daftar');
    Route::get('/isi-deposit/{isiDeposit}', [DepositPelangganKontroler::class, 'TampilkanIsi'])->where('isiDeposit', $ulid)->name('kelola.pelanggan.isi-deposit.tampil');
    Route::get('/mutasi-deposit/{mutasiDeposit}', [DepositPelangganKontroler::class, 'TampilkanMutasi'])->where('mutasiDeposit', $ulid)->name('kelola.pelanggan.mutasi-deposit.tampil');
    // CRM-06 (D-153): gift card & tautan sumber jurnalnya (sebelum rute {pelanggan}).
    Route::get('/gift-card', [GiftCardKontroler::class, 'Daftar'])->name('kelola.pelanggan.gift-card.daftar');
    Route::get('/gift-card/{giftCard}', [GiftCardKontroler::class, 'Rincian'])->where('giftCard', $ulid)->name('kelola.pelanggan.gift-card.rincian');
    Route::get('/mutasi-gift-card/{mutasiGiftCard}', [GiftCardKontroler::class, 'TampilkanMutasi'])->where('mutasiGiftCard', $ulid)->name('kelola.pelanggan.mutasi-gift-card.tampil');
    // F-16d bagian 2: saldo paket sesi & tautan sumber jurnal sesi (sebelum rute {pelanggan}).
    Route::get('/saldo-sesi', [SesiPelangganKontroler::class, 'Daftar'])->name('kelola.pelanggan.saldo-sesi.daftar');
    Route::get('/saldo-sesi/{saldoSesi}', [SesiPelangganKontroler::class, 'Tampilkan'])->where('saldoSesi', $ulid)->name('kelola.pelanggan.saldo-sesi.tampil');
    Route::get('/pemakaian-sesi/{pemakaianSesi}', [SesiPelangganKontroler::class, 'TampilkanPemakaian'])->where('pemakaianSesi', $ulid)->name('kelola.pelanggan.pemakaian-sesi.tampil');
    Route::get('/mutasi-sesi/{mutasiSesi}', [SesiPelangganKontroler::class, 'TampilkanMutasi'])->where('mutasiSesi', $ulid)->name('kelola.pelanggan.mutasi-sesi.tampil');
    Route::get('/{pelanggan}', [PelangganKontroler::class, 'Detail'])->where('pelanggan', $ulid)->name('kelola.pelanggan.detail');

    Route::middleware($izin(IzinTenant::PelangganDepositKelola))->group(function () use ($ulid): void {
        Route::post('/{pelanggan}/deposit/tarik', [DepositPelangganKontroler::class, 'Tarik'])->where('pelanggan', $ulid)->name('kelola.pelanggan.deposit.tarik');
        Route::post('/{pelanggan}/deposit/sesuaikan', [DepositPelangganKontroler::class, 'Sesuaikan'])->where('pelanggan', $ulid)->name('kelola.pelanggan.deposit.sesuaikan');
        Route::post('/isi-deposit/{isiDeposit}/batal', [DepositPelangganKontroler::class, 'BatalIsi'])->where('isiDeposit', $ulid)->name('kelola.pelanggan.isi-deposit.batal');
        Route::post('/gift-card', [GiftCardKontroler::class, 'Jual'])->middleware('throttle:30,1')->name('kelola.pelanggan.gift-card.jual');
        Route::post('/gift-card/{giftCard}/perpanjang', [GiftCardKontroler::class, 'Perpanjang'])->where('giftCard', $ulid)->name('kelola.pelanggan.gift-card.perpanjang');
        Route::post('/gift-card/{giftCard}/hanguskan', [GiftCardKontroler::class, 'Hanguskan'])->where('giftCard', $ulid)->name('kelola.pelanggan.gift-card.hanguskan');
        Route::post('/gift-card/{giftCard}/kembalikan', [GiftCardKontroler::class, 'Kembalikan'])->where('giftCard', $ulid)->name('kelola.pelanggan.gift-card.kembalikan');
    });

    Route::middleware($izin(IzinTenant::PelangganSesiKelola))->group(function () use ($ulid): void {
        Route::post('/saldo-sesi/{saldoSesi}/tutup', [SesiPelangganKontroler::class, 'Tutup'])->where('saldoSesi', $ulid)->name('kelola.pelanggan.saldo-sesi.tutup');
        Route::post('/pemakaian-sesi/{pemakaianSesi}/batal', [SesiPelangganKontroler::class, 'BatalPemakaian'])->where('pemakaianSesi', $ulid)->name('kelola.pelanggan.pemakaian-sesi.batal');
    });

    Route::middleware($izin(IzinTenant::PelangganKelola))->group(function () use ($ulid): void {
        Route::get('/buat', [PelangganKontroler::class, 'Buat'])->name('kelola.pelanggan.buat');
        // v3.36: impor (periksa → terapkan) & ekspor pelanggan; data pribadi, jadi `pelanggan.kelola`.
        // CRM-07 (v3.44): kampanye pesan WhatsApp/email bersegmen; data pribadi & pemasaran, jadi `pelanggan.kelola`.
        Route::get('/kampanye', [KampanyePesanKontroler::class, 'Daftar'])->name('kelola.pelanggan.kampanye.daftar');
        Route::get('/kampanye/buat', [KampanyePesanKontroler::class, 'Buat'])->name('kelola.pelanggan.kampanye.buat');
        Route::post('/kampanye', [KampanyePesanKontroler::class, 'Simpan'])->name('kelola.pelanggan.kampanye.simpan');
        Route::post('/kampanye/pratinjau', [KampanyePesanKontroler::class, 'Pratinjau'])->middleware('throttle:30,1')->name('kelola.pelanggan.kampanye.pratinjau');
        Route::get('/kampanye/{kampanye}', [KampanyePesanKontroler::class, 'Detail'])->where('kampanye', $ulid)->name('kelola.pelanggan.kampanye.detail');
        Route::get('/kampanye/{kampanye}/ubah', [KampanyePesanKontroler::class, 'Ubah'])->where('kampanye', $ulid)->name('kelola.pelanggan.kampanye.ubah');
        Route::put('/kampanye/{kampanye}', [KampanyePesanKontroler::class, 'Perbarui'])->where('kampanye', $ulid)->name('kelola.pelanggan.kampanye.perbarui');
        Route::post('/kampanye/{kampanye}/jalankan', [KampanyePesanKontroler::class, 'Jalankan'])->where('kampanye', $ulid)->middleware('throttle:10,1')->name('kelola.pelanggan.kampanye.jalankan');
        Route::post('/kampanye/{kampanye}/batal', [KampanyePesanKontroler::class, 'Batal'])->where('kampanye', $ulid)->name('kelola.pelanggan.kampanye.batal');
        Route::get('/impor', [ImporPelangganKontroler::class, 'Halaman'])->name('kelola.pelanggan.impor');
        Route::post('/impor', [ImporPelangganKontroler::class, 'Kirim'])->middleware('throttle:20,1')->name('kelola.pelanggan.impor.kirim');
        Route::get('/impor/templat', [ImporPelangganKontroler::class, 'Templat'])->name('kelola.pelanggan.impor.templat');
        Route::get('/ekspor', [ImporPelangganKontroler::class, 'Ekspor'])->middleware('throttle:10,1')->name('kelola.pelanggan.ekspor');
        Route::post('/massal', [PelangganKontroler::class, 'Massal'])->name('kelola.pelanggan.massal');
        Route::post('/', [PelangganKontroler::class, 'Simpan'])->name('kelola.pelanggan.simpan');
        Route::put('/{pelanggan}', [PelangganKontroler::class, 'Perbarui'])->where('pelanggan', $ulid)->name('kelola.pelanggan.perbarui');
        Route::post('/{pelanggan}/arsipkan', [PelangganKontroler::class, 'Arsipkan'])->where('pelanggan', $ulid)->name('kelola.pelanggan.arsipkan');
        Route::post('/{pelanggan}/pulihkan', [PelangganKontroler::class, 'Pulihkan'])->where('pelanggan', $ulid)->name('kelola.pelanggan.pulihkan');
        Route::post('/{pelanggan}/tier', [PelangganKontroler::class, 'AturTier'])->where('pelanggan', $ulid)->name('kelola.pelanggan.tier');
        Route::post('/{pelanggan}/poin', [PelangganKontroler::class, 'SesuaikanPoin'])->where('pelanggan', $ulid)->name('kelola.pelanggan.poin');
        Route::get('/tier/buat', [LoyaltiKontroler::class, 'BuatTier'])->name('kelola.pelanggan.tier.buat');
        Route::post('/tier', [LoyaltiKontroler::class, 'SimpanTier'])->name('kelola.pelanggan.tier.simpan');
        Route::put('/tier/{tier}', [LoyaltiKontroler::class, 'PerbaruiTier'])->where('tier', $ulid)->name('kelola.pelanggan.tier.perbarui');
        Route::post('/tier/{tier}/arsipkan', [LoyaltiKontroler::class, 'ArsipkanTier'])->where('tier', $ulid)->name('kelola.pelanggan.tier.arsipkan');
        Route::post('/tier/{tier}/pulihkan', [LoyaltiKontroler::class, 'PulihkanTier'])->where('tier', $ulid)->name('kelola.pelanggan.tier.pulihkan');
        Route::put('/loyalti', [LoyaltiKontroler::class, 'SimpanPengaturan'])->name('kelola.pelanggan.loyalti.simpan');
    });
});
