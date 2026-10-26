<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\Meja;
use App\Domain\Tenant\Model\OverrideTenant;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPesanSendiri;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-17 Self-Order QR Meja di back-office: sakelar outlet (izin outlet.kelola, butuh fitur kanal.self-order untuk
 * menghidupkan), QR per meja (token dibuat saat pertama ditampilkan), halaman cetak QR, buat ulang QR (audit, URL lama
 * tidak berlaku), dan isolasi tenant.
 */

beforeEach(fn () => BantuanPendaftaran::SiapkanPrasyarat());

describe('F-17 QR meja & sakelar pesan sendiri', function (): void {
    it('sakelar outlet: tampil di detail, hidup/mati dengan audit, tanpa fitur hanya bisa dimatikan. Kasir 403', function (): void {
        $k = BantuanPesanSendiri::Siapkan($this);
        $alamat = "/kelola/outlet/{$k['Outlet']->Uuid}";
        BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);

        $this->get($alamat)->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('PesanSendiri', ['FiturAktif' => true, 'Aktif' => true]));
        $this->post("{$alamat}/pesan-sendiri", ['Aktif' => false])->assertSessionHasNoErrors()->assertRedirect();
        expect($k['Outlet']->refresh()->PesanSendiriAktif)->toBeFalse();
        $this->post("{$alamat}/pesan-sendiri", ['Aktif' => true])->assertSessionHasNoErrors();
        expect($k['Outlet']->refresh()->PesanSendiriAktif)->toBeTrue()
            ->and(LogAudit::query()->where('Peristiwa', 'outlet.pesan-sendiri.ubah')->count())->toBe(2);

        OverrideTenant::query()->where('IdTenant', $k['Tenant']->Id)->delete();
        BantuanPesanSendiri::CabutFiturDariPaket();
        $this->get($alamat)->assertInertia(fn (AssertableInertia $h) => $h->where('PesanSendiri', ['FiturAktif' => false, 'Aktif' => true]));
        $this->post("{$alamat}/pesan-sendiri", ['Aktif' => false])->assertSessionHasNoErrors();
        $this->post("{$alamat}/pesan-sendiri", ['Aktif' => true])->assertSessionHasErrors('Umum');
        expect($k['Outlet']->refresh()->PesanSendiriAktif)->toBeFalse();

        $kasir = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Kasir);
        BantuanOrganisasi::Masuk($this, $kasir, $k['Tenant']->Id);
        $this->post("{$alamat}/pesan-sendiri", ['Aktif' => false])->assertForbidden();
    });

    it('QR meja: token dibuat saat pertama dilihat, URL publik & SVG. Halaman cetak memuat meja aktif', function (): void {
        $k = BantuanPesanSendiri::Siapkan($this);
        $baru = Meja::query()->create(['IdOutlet' => $k['Outlet']->Id, 'Nama' => '12', 'Kapasitas' => 4]);
        Meja::query()->create(['IdOutlet' => $k['Outlet']->Id, 'Nama' => 'Lama', 'Kapasitas' => 4, 'Status' => 'Diarsipkan']);
        expect($baru->TokenPesanSendiri)->toBeNull();
        BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);

        $respons = $this->getJson("/kelola/outlet/{$k['Outlet']->Uuid}/meja/{$baru->Uuid}/qr")->assertOk()->assertJsonPath('NamaMeja', '12');
        $token = $baru->refresh()->TokenPesanSendiri;
        expect($token)->toMatch('/^[A-Za-z0-9]{32}$/')
            ->and($respons->json('Url'))->toBe(url("/{$k['Slug']}/meja/{$token}"))
            ->and($respons->json('QrSvg'))->toContain('<svg');
        // Dilihat lagi: token sama.
        $this->getJson("/kelola/outlet/{$k['Outlet']->Uuid}/meja/{$baru->Uuid}/qr")->assertJsonPath('Url', url("/{$k['Slug']}/meja/{$token}"));

        $this->get("/kelola/outlet/{$k['Outlet']->Uuid}/meja/qr")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Outlet/QrMeja')
            ->where('Outlet.Nama', $k['Outlet']->Nama)
            ->where('NamaUsaha', 'Kedai Kopi Senja Rasa Nusantara')
            ->where('PesanSendiriAktif', true)
            ->has('Meja', 3)
            ->where('Meja.0.Nama', '7')
            ->where('Meja.0.Url', url($k['Alamat']))
            ->where('Meja.2.Nama', '12'));
    });

    it('buat ulang QR: token baru, audit, URL lama 404 dan URL baru bisa memesan. Kasir 403', function (): void {
        $k = BantuanPesanSendiri::Siapkan($this);
        BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);

        $this->post("/kelola/outlet/{$k['Outlet']->Uuid}/meja/{$k['Meja']->Uuid}/qr/buat-ulang")->assertSessionHasNoErrors()->assertRedirect();
        $tokenBaru = $k['Meja']->refresh()->TokenPesanSendiri;
        expect($tokenBaru)->not->toBe($k['TokenMeja'])
            ->and(LogAudit::query()->where('Peristiwa', 'meja.token-pesan-sendiri.buat-ulang')->sole()->NilaiBaru)->toBe(['AdaToken' => true]);

        $this->get($k['Alamat'])->assertNotFound();
        $this->postJson("{$k['Alamat']}/pesan", BantuanPesanSendiri::Kiriman([[$k['Nasi'], 1]]))->assertNotFound()->assertJsonPath('Galat.Kode', 'MejaTidakDitemukan');
        $this->get("/{$k['Slug']}/meja/{$tokenBaru}")->assertOk();
        $this->postJson("/{$k['Slug']}/meja/{$tokenBaru}/pesan", BantuanPesanSendiri::Kiriman([[$k['Nasi'], 1]]))->assertCreated();

        $kasir = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Kasir);
        BantuanOrganisasi::Masuk($this, $kasir, $k['Tenant']->Id);
        $this->post("/kelola/outlet/{$k['Outlet']->Uuid}/meja/{$k['Meja']->Uuid}/qr/buat-ulang")->assertForbidden();
    });

    it('isolasi tenant: QR & buat ulang meja tenant lain 404', function (): void {
        $a = BantuanPesanSendiri::Siapkan($this);
        ['Tenant' => $b, 'Pemilik' => $pemilikB] = BantuanOrganisasi::BuatTenant('Warung Bakso Pak Kumis');
        BantuanOrganisasi::Masuk($this, $pemilikB, $b->Id);

        $this->getJson("/kelola/outlet/{$a['Outlet']->Uuid}/meja/{$a['Meja']->Uuid}/qr")->assertNotFound();
        $this->post("/kelola/outlet/{$a['Outlet']->Uuid}/meja/{$a['Meja']->Uuid}/qr/buat-ulang")->assertNotFound();
        $this->get("/kelola/outlet/{$a['Outlet']->Uuid}/meja/qr")->assertNotFound();
        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        expect($a['Meja']->refresh()->TokenPesanSendiri)->toBe($a['TokenMeja']);
    });
});
