<?php

declare(strict_types=1);

use App\Domain\Tenant\Enum\StatusPaket;
use App\Domain\Tenant\Model\Paket;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * D-87: iklan di panel halaman masuk & daftar memakai data katalog asli. Diskon peluncuran hanya dikirim bila harga
 * normal yang lebih mahal memang sudah terjadwal (`katalog:terapkan`), dan tidak ada penawaran bila tidak ada paket
 * berbayar yang dijual.
 */

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-12 09:00:00', 'Asia/Jakarta'));
    BantuanPendaftaran::SiapkanPrasyarat();
});

afterEach(fn () => Carbon::setTestNow());

it('halaman masuk & daftar membawa penawaran termurah dengan diskon peluncuran dari katalog', function (): void {
    $this->artisan('katalog:terapkan')->assertSuccessful();

    $penawaran = fn (AssertableInertia $halaman) => $halaman
        ->where('Penawaran.NamaPaket', 'Starter')
        ->where('Penawaran.HargaBulanan', '59000.00')
        ->where('Penawaran.MasaTrialHari', 14)
        ->where('Penawaran.Promo.HargaBulananNormal', '99000.00')
        ->where('Penawaran.Promo.PersenDiskon', 40)
        ->where('Penawaran.Promo.BerlakuSampai', '2027-01-31')
        ->where('Penawaran.Promo.HargaTerkunci', true)
        // Daftar paket di iklan = paket berbayar berharga tetap dari katalog (GRATIS & ENTERPRISE tidak ikut).
        ->where('Penawaran.Paket.0.Nama', 'Starter')
        ->where('Penawaran.Paket.0.HargaBulanan', '59000.00')
        ->where('Penawaran.Paket.0.HargaNormal', '99000.00')
        ->where('Penawaran.Paket.1.Nama', 'Pro')
        ->where('Penawaran.Paket.2.Nama', 'Bisnis')
        ->has('Penawaran.Paket', 3);

    $this->get('/masuk')->assertOk()->assertInertia(fn (AssertableInertia $halaman) => $penawaran($halaman->component('Autentikasi/Masuk')));
    $this->get('/daftar')->assertOk()->assertInertia(fn (AssertableInertia $halaman) => $penawaran($halaman->component('Autentikasi/Daftar')));
});

it('setelah promo berakhir harga normal tampil tanpa diskon', function (): void {
    $this->artisan('katalog:terapkan')->assertSuccessful();
    Carbon::setTestNow(Carbon::parse('2027-02-03 09:00:00', 'Asia/Jakarta'));

    $this->get('/masuk')->assertOk()->assertInertia(fn (AssertableInertia $halaman) => $halaman
        ->where('Penawaran.HargaBulanan', '99000.00')
        ->where('Penawaran.Promo', null)
        ->where('Penawaran.Paket.0.HargaNormal', null));
});

it('tanpa paket berbayar yang dijual tidak ada penawaran (iklan tidak menyebut harga yang tidak ada)', function (): void {
    Paket::query()->whereNot('Kode', 'GRATIS')->update(['Status' => StatusPaket::Diarsipkan->value]);

    $this->get('/masuk')->assertOk()->assertInertia(fn (AssertableInertia $halaman) => $halaman->where('Penawaran', null));
});
