<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Penjualan\Enum\JenisMetodePembayaran;
use App\Domain\Penjualan\Layanan\KesiapanQrisOnline;
use App\Domain\Penjualan\Model\PengaturanTokoOnline;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanGerbangTenant;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Penjualan\BantuanPesanSendiri;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * D-338: tombol "Bayar QRIS" di halaman meja butuh sakelar toko, gerbang aktif, dan metode QRIS dinamis aktif. Pengelola
 * kini diberi tahu sebab yang kurang (CariHambatan) dan bisa menghidupkan sakelarnya dari halaman outlet.
 */

beforeEach(fn () => BantuanPendaftaran::SiapkanPrasyarat());

describe('D-338 kesiapan QRIS pesan dari meja', function (): void {
    it('CariHambatan menyebut gerbang dan metode QRIS dinamis yang belum ada. Siap bila keduanya aktif', function (): void {
        $k = BantuanPesanSendiri::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $kesiapan = app(KesiapanQrisOnline::class);

        expect(array_column($kesiapan->CariHambatan(), 'Kode'))->toBe(['GerbangBelumAktif', 'MetodeQrisBelumAktif'])
            ->and($kesiapan->CekSiap())->toBeFalse()
            ->and($kesiapan->CariHambatan()[0]['Pesan'])->toContain('Gerbang pembayaran toko belum aktif')
            ->and($kesiapan->CariHambatan()[0]['Tautan'])->toStartWith('/kelola/');

        BantuanGerbangTenant::Aktifkan($k['Tenant']->Id);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(array_column($kesiapan->CariHambatan(), 'Kode'))->toBe(['MetodeQrisBelumAktif'])->and($kesiapan->CekSiap())->toBeFalse();

        BantuanPenjualan::BuatMetode(JenisMetodePembayaran::QrisDinamis, 'QRIS Otomatis');
        expect($kesiapan->CariHambatan())->toBe([])->and($kesiapan->CekSiap())->toBeTrue();
    });

    it('halaman outlet membawa status sakelar, sebab yang kurang, dan izin mengubah', function (): void {
        $k = BantuanPesanSendiri::Siapkan($this);
        $alamat = "/kelola/outlet/{$k['Outlet']->Uuid}";
        BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);

        $this->get($alamat)->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('PesanSendiriQris.Aktif', false)
            ->where('PesanSendiriQris.BolehUbah', true)
            ->has('PesanSendiriQris.Hambatan', 2)
            ->where('PesanSendiriQris.Hambatan.0.Kode', 'GerbangBelumAktif'));
    });

    it('menghidupkan ditolak dengan alasan selama QRIS belum siap. Setelah siap berhasil dengan audit, mematikan selalu boleh', function (): void {
        $k = BantuanPesanSendiri::Siapkan($this);
        $alamat = "/kelola/outlet/{$k['Outlet']->Uuid}/pesan-sendiri/qris";
        BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);

        $this->post($alamat, ['Aktif' => true])->assertSessionHasErrors('Umum');
        expect(PengaturanTokoOnline::query()->value('QrisAktif'))->not->toBeTrue();

        BantuanGerbangTenant::Aktifkan($k['Tenant']->Id);
        BantuanPenjualan::BuatMetode(JenisMetodePembayaran::QrisDinamis, 'QRIS Otomatis');
        BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);

        $this->post($alamat, ['Aktif' => true])->assertSessionHasNoErrors()->assertRedirect();
        expect(PengaturanTokoOnline::query()->value('QrisAktif'))->toBeTrue();
        // Diulang dengan nilai sama tidak menambah audit.
        $this->post($alamat, ['Aktif' => true])->assertSessionHasNoErrors();
        $this->post($alamat, ['Aktif' => false])->assertSessionHasNoErrors();
        expect(PengaturanTokoOnline::query()->value('QrisAktif'))->toBeFalse();

        $log = LogAudit::query()->where('Peristiwa', 'toko-online.pengaturan')->orderBy('Id')->get();
        expect($log)->toHaveCount(2)
            ->and($log[0]->NilaiBaru)->toMatchArray(['QrisAktif' => true])
            ->and($log[1]->NilaiBaru)->toMatchArray(['QrisAktif' => false]);
    });

    it('halaman Toko online membawa sebab QRIS belum bisa dipakai pembeli', function (): void {
        $k = BantuanPesanSendiri::Siapkan($this);
        BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);

        $this->get('/kelola/toko-online')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/TokoOnline/Daftar')
            ->has('QrisHambatan', 2)
            ->where('QrisHambatan.1.Kode', 'MetodeQrisBelumAktif'));
    });

    it('tamu melihat tombol QRIS begitu sakelar hidup dan QRIS siap', function (): void {
        $k = BantuanPesanSendiri::Siapkan($this);
        BantuanGerbangTenant::Aktifkan($k['Tenant']->Id);
        BantuanPenjualan::BuatMetode(JenisMetodePembayaran::QrisDinamis, 'QRIS Otomatis');
        BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);

        $this->post("/kelola/outlet/{$k['Outlet']->Uuid}/pesan-sendiri/qris", ['Aktif' => true])->assertSessionHasNoErrors();
        $this->get($k['Alamat'])->assertInertia(fn (AssertableInertia $h) => $h->where('Pembayaran.Qris', true));
    });

    it('kasir tidak berizin toko online: 403 dan sakelar tidak berubah', function (): void {
        $k = BantuanPesanSendiri::Siapkan($this);
        $kasir = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Kasir);
        BantuanOrganisasi::Masuk($this, $kasir, $k['Tenant']->Id);

        $this->post("/kelola/outlet/{$k['Outlet']->Uuid}/pesan-sendiri/qris", ['Aktif' => true])->assertForbidden();
        expect(PengaturanTokoOnline::query()->value('QrisAktif'))->not->toBeTrue();
    });
});
