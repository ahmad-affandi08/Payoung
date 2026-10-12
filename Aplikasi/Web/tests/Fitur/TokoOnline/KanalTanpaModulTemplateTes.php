<?php

declare(strict_types=1);

use App\Domain\Penjualan\Model\PengaturanTokoOnline;
use App\Domain\Tenant\Model\OutletFitur;
use App\Domain\Tenant\Model\OverrideTenant;
use App\Domain\Tenant\Model\PaketFitur;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanTokoOnline;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/*
 * Kasus nyata (trial paket Bisnis): outlet yang template sektornya sudah diterapkan punya baris `OutletFitur` berisi
 * modul template (`pos.retail`, `stok.dasar`, ...), tetapi tidak pernah `kanal.*` karena kanal bukan modul template.
 * Fitur kanal dari paket/add-on harus tetap aktif; sebelumnya filter modul outlet menolak semuanya dengan pesan
 * "belum aktif di paket", dan galatnya tidak terlihat di formulir.
 */
describe('F-17 kanal tidak ditolak filter modul template outlet', function (): void {
    it('menyimpan pengaturan toko online di outlet ber-template walau kanal.toko-online bukan modul template', function (): void {
        $k = BantuanTokoOnline::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        PengaturanTokoOnline::query()->delete();

        foreach (['pos.retail', 'stok.dasar', 'laporan.dasar'] as $kunci) {
            OutletFitur::query()->create(['IdOutlet' => $k['Outlet']->Id, 'KunciFitur' => $kunci, 'Aktif' => true]);
        }

        BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);
        $this->put('/kelola/toko-online/pengaturan', [
            'Outlet' => $k['Outlet']->Uuid, 'Aktif' => true, 'TokoOnlineAktif' => true,
            'AmbilSendiriAktif' => true, 'KirimAktif' => false, 'BayarSaatAmbilAktif' => true, 'CodAktif' => false,
            'QrisAktif' => false, 'AkunPelangganAktif' => false, 'MinimalPesanan' => '10000.00', 'MenitKedaluwarsa' => 120, 'PesanTutup' => null,
        ])->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(PengaturanTokoOnline::query()->sole()->Aktif)->toBeTrue();

        $this->get($k['AlamatToko'])->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->has('Outlet'));
    });

    it('tetap menolak bila paket benar-benar tidak memuat kanal.toko-online', function (): void {
        $k = BantuanTokoOnline::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        OverrideTenant::query()->where('Kunci', 'kanal.toko-online')->delete();
        PaketFitur::query()->where('KunciFitur', 'kanal.toko-online')->delete();
        PengaturanTokoOnline::query()->delete();

        BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);
        $this->put('/kelola/toko-online/pengaturan', [
            'Outlet' => $k['Outlet']->Uuid, 'Aktif' => true, 'TokoOnlineAktif' => true,
            'AmbilSendiriAktif' => true, 'KirimAktif' => false, 'BayarSaatAmbilAktif' => true, 'CodAktif' => false,
            'QrisAktif' => false, 'AkunPelangganAktif' => false, 'MinimalPesanan' => '10000.00', 'MenitKedaluwarsa' => 120, 'PesanTutup' => null,
        ])->assertSessionHasErrors('Aktif');
    });
});
