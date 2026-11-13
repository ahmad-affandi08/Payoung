<?php

declare(strict_types=1);

use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Tenant\Enum\JenisOverride;
use App\Domain\Tenant\Layanan\PemeriksaFiturTenant;
use App\Domain\Tenant\Model\OverrideTenant;
use App\Domain\Tenant\Model\PaketFitur;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Tenant\BantuanAutentikasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * D-299: aplikasi Kasir dan Owner menyembunyikan semua yang berkaitan dengan WhatsApp kecuali `Whatsapp.Tersedia` true.
 * Flag itu = layanan WhatsApp platform aktif di konsol DAN paket tenant memuat `integrasi.whatsapp`
 * (`KetersediaanWhatsapp::TersediaUntuk`). Dibaca dari `data-awal` & `konfigurasi-aplikasi` (Kasir) dan
 * `konfigurasi-aplikasi` (Owner).
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    config(['integrasi.Whatsapp' => null]);
});

function AktifkanWhatsappPlatformUji(): void
{
    config(['integrasi.Whatsapp' => ['Penyedia' => 'Fonnte', 'Pengaturan' => [], 'Kredensial' => ['Token' => 'token-payoung']]]);
}

function BeriFiturWhatsappUji(int $idTenant): void
{
    BantuanOrganisasi::AturKonteks($idTenant);
    OverrideTenant::query()->create([
        'IdTenant' => $idTenant, 'Jenis' => JenisOverride::Fitur, 'Kunci' => PemeriksaFiturTenant::KUNCI_WHATSAPP,
        'BerakhirPada' => now()->addDays(30), 'Alasan' => 'Uji ketersediaan WhatsApp aplikasi',
        'DibuatOleh' => BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin)->Id,
    ]);
    BantuanOrganisasi::AturKonteks($idTenant);
}

function CabutFiturWhatsappPaketUji(int $idTenant): void
{
    PaketFitur::query()->where('KunciFitur', PemeriksaFiturTenant::KUNCI_WHATSAPP)->delete();
    OverrideTenant::query()->where('Kunci', PemeriksaFiturTenant::KUNCI_WHATSAPP)->delete();
    BantuanOrganisasi::AturKonteks($idTenant);
}

describe('D-299 flag Whatsapp untuk aplikasi Kasir', function (): void {
    it('false saat konsol belum mengaktifkan WhatsApp walau paket memuat fiturnya', function (): void {
        $k = BantuanKasir::Siapkan($this);
        BeriFiturWhatsappUji($k['Tenant']->Id);

        $this->withToken($k['Token'])->getJson('/api/pos/v1/data-awal')->assertOk()->assertJsonPath('Whatsapp.Tersedia', false);
        $this->withToken($k['Token'])->getJson('/api/pos/v1/konfigurasi-aplikasi')->assertOk()->assertJsonPath('Whatsapp.Tersedia', false);
    });

    it('false saat konsol aktif tetapi paket tenant tidak memuat fitur WhatsApp', function (): void {
        $k = BantuanKasir::Siapkan($this);
        AktifkanWhatsappPlatformUji();
        CabutFiturWhatsappPaketUji($k['Tenant']->Id);

        $this->withToken($k['Token'])->getJson('/api/pos/v1/data-awal')->assertOk()->assertJsonPath('Whatsapp.Tersedia', false);
        $this->withToken($k['Token'])->getJson('/api/pos/v1/konfigurasi-aplikasi')->assertOk()->assertJsonPath('Whatsapp.Tersedia', false);
    });

    it('true saat konsol aktif dan paket memuat fitur WhatsApp', function (): void {
        $k = BantuanKasir::Siapkan($this);
        AktifkanWhatsappPlatformUji();
        BeriFiturWhatsappUji($k['Tenant']->Id);

        $this->withToken($k['Token'])->getJson('/api/pos/v1/data-awal')->assertOk()->assertJsonPath('Whatsapp.Tersedia', true);
        $this->withToken($k['Token'])->getJson('/api/pos/v1/konfigurasi-aplikasi')->assertOk()->assertJsonPath('Whatsapp.Tersedia', true);
    });
});

describe('D-299 flag Whatsapp untuk Aplikasi Owner', function (): void {
    /** @return array{0: string, 1: string, 2: int} token, uuid tenant, id tenant */
    function MasukPemilikWhatsappUji(object $uji, string $namaToko): array
    {
        $data = BantuanOrganisasi::BuatTenant($namaToko);
        $data['Pemilik']->forceFill(['EmailDiverifikasiPada' => now()])->save();
        $token = (string) $uji->withHeader('X-Versi-Aplikasi', '1.0.0')->postJson('/api/pemilik/v1/masuk', [
            'Email' => $data['Pemilik']->Email,
            'KataSandi' => BantuanAutentikasi::KATA_SANDI,
            'NamaPerangkat' => 'Pixel Owner',
        ])->assertOk()->json('Token');

        return [$token, $data['Tenant']->Uuid, $data['Tenant']->Id];
    }

    it('false saat konsol belum aktif, false saat paket tanpa fitur, true saat keduanya terpenuhi', function (): void {
        [$token, $uuidTenant, $idTenant] = MasukPemilikWhatsappUji($this, 'Toko Owner WhatsApp');
        $baca = fn () => $this->withToken($token)->withHeader('X-Tenant', $uuidTenant)->getJson('/api/pemilik/v1/konfigurasi-aplikasi')->assertOk();

        BeriFiturWhatsappUji($idTenant);
        $baca()->assertJsonPath('Whatsapp.Tersedia', false);

        AktifkanWhatsappPlatformUji();
        $baca()->assertJsonPath('Whatsapp.Tersedia', true);

        CabutFiturWhatsappPaketUji($idTenant);
        $baca()->assertJsonPath('Whatsapp.Tersedia', false);
    });
});
