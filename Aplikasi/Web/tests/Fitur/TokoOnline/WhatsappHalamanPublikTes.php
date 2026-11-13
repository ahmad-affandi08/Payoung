<?php

declare(strict_types=1);

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Pemenuhan\Model\PengaturanReservasi;
use App\Domain\Tenant\Layanan\PemeriksaFiturTenant;
use App\Domain\Tenant\Model\OverrideTenant;
use App\Domain\Tenant\Model\PaketFitur;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanTokoOnline;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * D-299: halaman publik tenant (toko online, akun pembeli, reservasi online, berhenti berlangganan) membawa prop
 * `WhatsappTersedia`, dan jalur yang meminta kode masuk lewat WhatsApp ditolak bila layanan WhatsApp tidak aktif di
 * konsol atau tidak ada di paket tenant. Checkout tamu tetap berjalan.
 */

const NOMOR_UJI_D298 = '0812-7777-1234';

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Http::fake(['api.fonnte.com/send' => Http::response(['status' => true, 'id' => ['9001']])]);
});

function AturWhatsappPlatformUji(bool $aktif): void
{
    config(['integrasi.Whatsapp' => $aktif
        ? ['Penyedia' => 'Fonnte', 'Pengaturan' => [], 'Kredensial' => ['Token' => 'rahasia-uji']]
        : null]);
}

/** Cabut fitur WhatsApp dari paket dan dari override tenant (paket tidak memuatnya). */
function CabutFiturWhatsappTenantUji(int $idTenant): void
{
    PaketFitur::query()->where('KunciFitur', PemeriksaFiturTenant::KUNCI_WHATSAPP)->delete();
    OverrideTenant::query()->where('Kunci', PemeriksaFiturTenant::KUNCI_WHATSAPP)->delete();
    BantuanOrganisasi::AturKonteks($idTenant);
}

it('toko online dan akun: WhatsappTersedia true hanya bila konsol aktif dan paket memuat fitur', function (): void {
    $k = BantuanTokoOnline::Siapkan($this);

    AturWhatsappPlatformUji(true);
    $this->get($k['AlamatToko'])->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->where('WhatsappTersedia', true)->where('Akun.Aktif', true));
    $this->get($k['AlamatToko'].'/akun')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->component('Publik/AkunTokoOnline')->where('WhatsappTersedia', true)->where('AkunAktif', true));

    // Konsol belum mengaktifkan WhatsApp.
    AturWhatsappPlatformUji(false);
    $this->get($k['AlamatToko'])->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->where('WhatsappTersedia', false)->where('Akun.Aktif', false)->where('Akun.Pelanggan', null));
    $this->get($k['AlamatToko'].'/akun')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->where('WhatsappTersedia', false)->where('AkunAktif', false)->where('Pelanggan', null));

    // Konsol aktif tetapi paket tidak memuat fitur WhatsApp.
    AturWhatsappPlatformUji(true);
    CabutFiturWhatsappTenantUji($k['Tenant']->Id);
    app(KonteksTenant::class)->Kosongkan();
    $this->get($k['AlamatToko'])->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->where('WhatsappTersedia', false)->where('Akun.Aktif', false));
});

it('permintaan kode masuk WhatsApp dan jalur akun lain ditolak bila WhatsApp tidak tersedia, checkout tamu tetap jalan', function (): void {
    $k = BantuanTokoOnline::Siapkan($this);

    AturWhatsappPlatformUji(false);
    $this->postJson($k['AlamatToko'].'/akun/kode', ['NoHp' => NOMOR_UJI_D298])->assertStatus(409)->assertJsonPath('Galat.Kode', 'MasukBelumTersedia');
    $this->postJson($k['AlamatToko'].'/akun/masuk', ['NoHp' => NOMOR_UJI_D298, 'Kode' => '123456'])->assertStatus(409)->assertJsonPath('Galat.Kode', 'MasukBelumTersedia');
    $this->postJson($k['AlamatToko'].'/pesan', BantuanTokoOnline::Kiriman($k))->assertCreated();

    // Konsol aktif, paket tidak memuat fitur: tetap ditolak.
    AturWhatsappPlatformUji(true);
    CabutFiturWhatsappTenantUji($k['Tenant']->Id);
    app(KonteksTenant::class)->Kosongkan();
    $this->postJson($k['AlamatToko'].'/akun/kode', ['NoHp' => NOMOR_UJI_D298])->assertStatus(409)->assertJsonPath('Galat.Kode', 'MasukBelumTersedia');

    Http::assertNothingSent();
});

it('reservasi online membawa WhatsappTersedia menurut konsol dan paket', function (): void {
    $k = BantuanTokoOnline::Siapkan($this);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    PengaturanReservasi::query()->create(['OnlineAktif' => true, 'KonfirmasiOtomatis' => false, 'MinimalMenitSebelum' => 60]);
    app(KonteksTenant::class)->Kosongkan();
    $alamat = $k['AlamatToko'].'/reservasi';

    AturWhatsappPlatformUji(true);
    $this->get($alamat)->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->component('Publik/Reservasi')->where('WhatsappTersedia', true));

    AturWhatsappPlatformUji(false);
    $this->get($alamat)->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('WhatsappTersedia', false));
});
