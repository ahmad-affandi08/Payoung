<?php

declare(strict_types=1);

use App\Domain\Integrasi\Enum\PenyediaWhatsapp;
use App\Domain\Integrasi\Model\WhatsappTenant;
use App\Domain\Integrasi\Whatsapp\KetersediaanWhatsapp;
use App\Domain\Integrasi\Whatsapp\PembuatPengirimWhatsapp;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Tenant\Enum\JenisOverride;
use App\Domain\Tenant\Model\OverrideTenant;
use App\Domain\Tenant\Model\PaketFitur;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * D-299: pintu penjaga layanan WhatsApp tenant. Bila konsol belum mengaktifkan WhatsApp platform, atau paket tenant tidak
 * memuatnya, tidak ada pengirim sama sekali (walau tenant sudah menyimpan nomor sendiri), props bersama membawa
 * `WhatsappTersedia` false, dan halaman khusus WhatsApp tidak ada (404).
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

function AktifkanWhatsappPlatform(bool $aktif): void
{
    config(['integrasi.Whatsapp' => $aktif
        ? ['Penyedia' => 'Fonnte', 'Pengaturan' => [], 'Kredensial' => ['Token' => 'token-payoung']]
        : ['Penyedia' => null]]);
}

/** @return array{Tenant: mixed, Pemilik: mixed} */
function TokoGerbangWa(bool $denganFiturPaket = true): array
{
    $toko = BantuanOrganisasi::BuatTenant('Toko Kelontong Berkah Solo');
    BantuanOrganisasi::AturKonteks($toko['Tenant']->Id);

    if (! $denganFiturPaket) {
        PaketFitur::query()->where('KunciFitur', 'integrasi.whatsapp')->delete();
    }

    if ($denganFiturPaket) {
        OverrideTenant::query()->create([
            'IdTenant' => $toko['Tenant']->Id, 'Jenis' => JenisOverride::Fitur->value, 'Kunci' => 'integrasi.whatsapp', 'Nilai' => null,
            'BerakhirPada' => now()->addDays(60), 'Alasan' => 'Uji pintu WhatsApp',
            'DibuatOleh' => BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin)->Id,
        ]);
    }

    return $toko;
}

function PasangNomorSendiriAktif(int $idTenant): void
{
    WhatsappTenant::query()->create([
        'Penyedia' => PenyediaWhatsapp::Fonnte, 'Pengaturan' => [], 'Kredensial' => ['Token' => 'tokenSendiri-1234'],
        'PetunjukKredensial' => ['Token' => '••••1234'], 'StatusUji' => 'Berhasil', 'Aktif' => true, 'IdTenant' => $idTenant,
    ]);
}

describe('D-299 pintu penjaga WhatsApp tenant', function (): void {
    it('tidak tersedia dan tanpa pengirim bila konsol belum mengaktifkan WhatsApp, walau tenant punya nomor sendiri aktif', function (): void {
        AktifkanWhatsappPlatform(false);
        $toko = TokoGerbangWa();
        PasangNomorSendiriAktif($toko['Tenant']->Id);

        expect(app(KetersediaanWhatsapp::class)->PlatformAktif())->toBeFalse()
            ->and(app(KetersediaanWhatsapp::class)->TersediaUntuk($toko['Tenant']->Id))->toBeFalse()
            ->and(app(PembuatPengirimWhatsapp::class)->AmbilAktif())->toBeNull()
            ->and(app(PembuatPengirimWhatsapp::class)->AmbilMilikTenant())->toBeNull();
    });

    it('tidak tersedia bila konsol aktif tetapi paket tenant tidak memuat fitur WhatsApp', function (): void {
        AktifkanWhatsappPlatform(true);
        $toko = TokoGerbangWa(denganFiturPaket: false);

        expect(app(KetersediaanWhatsapp::class)->PlatformAktif())->toBeTrue()
            ->and(app(KetersediaanWhatsapp::class)->TersediaUntuk($toko['Tenant']->Id))->toBeFalse()
            ->and(app(PembuatPengirimWhatsapp::class)->AmbilAktif())->toBeNull();
    });

    it('tersedia bila konsol aktif dan paket memuat fitur, dan pengirim platform tetap bisa dibuat tanpa konteks tenant', function (): void {
        AktifkanWhatsappPlatform(true);
        $toko = TokoGerbangWa();

        expect(app(KetersediaanWhatsapp::class)->TersediaUntuk($toko['Tenant']->Id))->toBeTrue()
            ->and(app(PembuatPengirimWhatsapp::class)->AmbilAktif())->not->toBeNull()
            ->and(app(KetersediaanWhatsapp::class)->TersediaUntuk(null))->toBeTrue();

        AktifkanWhatsappPlatform(false);

        expect(app(KetersediaanWhatsapp::class)->TersediaUntuk(null))->toBeFalse()
            ->and(app(PembuatPengirimWhatsapp::class)->AmbilAktifPlatform())->toBeNull();
    });

    it('membagikan WhatsappTersedia dan WhatsappPlatformAktif ke halaman tenant sesuai keadaan konsol dan paket', function (): void {
        $toko = TokoGerbangWa();

        AktifkanWhatsappPlatform(false);
        BantuanOrganisasi::Masuk($this, $toko['Pemilik'], $toko['Tenant']->Id)->get('/kelola')->assertOk()
            ->assertInertia(fn (AssertableInertia $h) => $h->where('WhatsappTersedia', false)->where('WhatsappPlatformAktif', false));

        AktifkanWhatsappPlatform(true);
        BantuanOrganisasi::Masuk($this, $toko['Pemilik'], $toko['Tenant']->Id)->get('/kelola')->assertOk()
            ->assertInertia(fn (AssertableInertia $h) => $h->where('WhatsappTersedia', true)->where('WhatsappPlatformAktif', true));
    });

    it('platform aktif tetapi paket tanpa fitur: platform aktif true namun tersedia false (menu bergembok, bukan tombol)', function (): void {
        AktifkanWhatsappPlatform(true);
        $toko = TokoGerbangWa(denganFiturPaket: false);

        BantuanOrganisasi::Masuk($this, $toko['Pemilik'], $toko['Tenant']->Id)->get('/kelola')->assertOk()
            ->assertInertia(fn (AssertableInertia $h) => $h->where('WhatsappTersedia', false)->where('WhatsappPlatformAktif', true));
    });

    it('halaman khusus WhatsApp tidak ada (404) bila tidak tersedia dan terbuka bila tersedia', function (): void {
        $toko = TokoGerbangWa();
        $alamat = ['/kelola/pengaturan/whatsapp', '/kelola/pelanggan/kampanye'];

        AktifkanWhatsappPlatform(false);

        foreach ($alamat as $a) {
            BantuanOrganisasi::Masuk($this, $toko['Pemilik'], $toko['Tenant']->Id)->get($a)->assertNotFound();
        }

        AktifkanWhatsappPlatform(true);

        foreach ($alamat as $a) {
            BantuanOrganisasi::Masuk($this, $toko['Pemilik'], $toko['Tenant']->Id)->get($a)->assertOk();
        }
    });
});
