<?php

declare(strict_types=1);

use App\Domain\Integrasi\Aksi\SegarkanStatusNomorPengirimWhatsapp;
use App\Domain\Integrasi\Enum\FungsiNomorWhatsapp;
use App\Domain\Integrasi\Enum\StatusNomorWhatsapp;
use App\Domain\Integrasi\Model\NomorPengirimWhatsapp;
use App\Domain\Integrasi\Whatsapp\PembuatPengirimWhatsapp;
use App\Domain\Integrasi\Whatsapp\PesanWhatsapp;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Tenant\Enum\JenisOverride;
use App\Domain\Tenant\Model\OverrideTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * D-299 tahap 1: nomor pengirim WhatsApp per outlet. Tenant mendaftarkan nomor, memindai QR, dan memilih fungsinya.
 * Sesi perangkat dipegang platform (Fonnte add-device), token tidak pernah keluar ke klien.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

function SiapkanKonsolWa(bool $denganTokenAkun = true): void
{
    config(['integrasi.Whatsapp' => ['Penyedia' => 'Fonnte', 'Pengaturan' => [], 'Kredensial' => ['Token' => 'token-payoung'] + ($denganTokenAkun ? ['TokenAkun' => 'akun-payoung'] : [])]]);
}

/** @return array{Tenant: mixed, Pemilik: mixed, Outlet: Outlet} */
function TokoNomorWa(): array
{
    $toko = BantuanOrganisasi::BuatTenant('Toko Kelontong Berkah Solo');
    BantuanOrganisasi::AturKonteks($toko['Tenant']->Id);
    OverrideTenant::query()->create([
        'IdTenant' => $toko['Tenant']->Id, 'Jenis' => JenisOverride::Fitur->value, 'Kunci' => 'integrasi.whatsapp', 'Nilai' => null,
        'BerakhirPada' => now()->addDays(60), 'Alasan' => 'Uji nomor pengirim',
        'DibuatOleh' => BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin)->Id,
    ]);

    return $toko + ['Outlet' => Outlet::query()->firstOrFail()];
}

function FakeFonnte(): void
{
    Http::fake([
        'api.fonnte.com/add-device' => Http::sequence()->push(['status' => true, 'token' => 'perangkat-1'])->push(['status' => true, 'token' => 'perangkat-2'])->push(['status' => true, 'token' => 'perangkat-3'])
            ->push(['status' => true, 'token' => 'perangkat-4'])->push(['status' => true, 'token' => 'perangkat-5'])->push(['status' => true, 'token' => 'perangkat-6'])->push(['status' => true, 'token' => 'perangkat-7']),
        'api.fonnte.com/qr' => Http::response(['status' => true, 'url' => 'AAAA']),
        'api.fonnte.com/device' => Http::response(['status' => true, 'device_status' => 'connect']),
        'api.fonnte.com/disconnect' => Http::response(['status' => true]),
        'api.fonnte.com/send' => Http::response(['status' => true, 'id' => ['ID1']]),
    ]);
}

function TambahNomorUji(mixed $tes, array $toko, string $nomor = '081234567890', string $fungsi = 'Umum', string $label = 'Kasir utama'): mixed
{
    return BantuanOrganisasi::Masuk($tes, $toko['Pemilik'], $toko['Tenant']->Id)->post('/kelola/pelanggan/whatsapp/nomor', [
        'UuidOutlet' => $toko['Outlet']->Uuid, 'Label' => $label, 'Fungsi' => $fungsi, 'NomorTelepon' => $nomor,
    ]);
}

describe('D-299 nomor pengirim WhatsApp per outlet', function (): void {
    it('menambah nomor lewat sesi platform tanpa tenant mengisi token, token terenkripsi dan tidak ikut ke halaman', function (): void {
        SiapkanKonsolWa();
        FakeFonnte();
        $toko = TokoNomorWa();

        TambahNomorUji($this, $toko)->assertRedirect('/kelola/pelanggan/whatsapp');

        $nomor = NomorPengirimWhatsapp::query()->firstOrFail();
        expect($nomor->NomorTelepon)->toBe('6281234567890')
            ->and($nomor->Status)->toBe(StatusNomorWhatsapp::MenungguPindai)
            ->and($nomor->Aktif)->toBeFalse()
            ->and($nomor->TokenPerangkat)->toBe('perangkat-1')
            ->and(DB::table('NomorPengirimWhatsapp')->value('TokenPerangkat'))->not->toBe('perangkat-1');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/add-device') && $r->header('Authorization')[0] === 'akun-payoung');

        $respons = BantuanOrganisasi::Masuk($this, $toko['Pemilik'], $toko['Tenant']->Id)->get('/kelola/pelanggan/whatsapp')->assertOk();
        expect($respons->getContent())->not->toContain('perangkat-1');
    });

    it('menolak nomor tidak sah, nomor ganda, dan lebih dari enam nomor per outlet', function (): void {
        SiapkanKonsolWa();
        FakeFonnte();
        $toko = TokoNomorWa();

        TambahNomorUji($this, $toko, '12345')->assertSessionHasErrors('NomorTelepon');

        foreach (range(1, 6) as $i) {
            TambahNomorUji($this, $toko, '08123456780'.$i, label: "Nomor {$i}")->assertSessionHasNoErrors();
        }

        TambahNomorUji($this, $toko, '081234567801')->assertSessionHasErrors('NomorTelepon');
        TambahNomorUji($this, $toko, '081234567899')->assertSessionHasErrors('NomorTelepon');
        expect(NomorPengirimWhatsapp::query()->count())->toBe(6);
    });

    it('QR diminta dari penyedia dan nomor menjadi terhubung dan aktif begitu penyedia melapor terhubung', function (): void {
        SiapkanKonsolWa();
        FakeFonnte();
        $toko = TokoNomorWa();
        TambahNomorUji($this, $toko);
        $nomor = NomorPengirimWhatsapp::query()->firstOrFail();
        $sesi = BantuanOrganisasi::Masuk($this, $toko['Pemilik'], $toko['Tenant']->Id);

        $sesi->postJson("/kelola/pelanggan/whatsapp/nomor/{$nomor->Uuid}/qr")->assertOk()
            ->assertJson(['Berhasil' => true, 'Terhubung' => false, 'KodeQr' => 'data:image/png;base64,AAAA']);

        $sesi->postJson("/kelola/pelanggan/whatsapp/nomor/{$nomor->Uuid}/status")->assertOk()->assertJson(['Terhubung' => true]);

        $nomor->refresh();
        expect($nomor->Status)->toBe(StatusNomorWhatsapp::Terhubung)->and($nomor->Aktif)->toBeTrue()->and($nomor->TerhubungPada)->not->toBeNull();
    });

    it('nomor yang terputus ditandai terputus dan dimatikan supaya tidak dipilih sebagai pengirim', function (): void {
        SiapkanKonsolWa();
        $toko = TokoNomorWa();
        $nomor = NomorPengirimWhatsapp::query()->create([
            'IdTenant' => $toko['Tenant']->Id, 'IdOutlet' => $toko['Outlet']->Id, 'Label' => 'Kasir', 'NomorTelepon' => '6281200000001',
            'Penyedia' => 'Fonnte', 'TokenPerangkat' => 'perangkat-x', 'Status' => StatusNomorWhatsapp::Terhubung, 'Aktif' => true, 'TerhubungPada' => now(),
        ]);
        Http::fake(['api.fonnte.com/device' => Http::response(['status' => true, 'device_status' => 'disconnect'])]);

        app(SegarkanStatusNomorPengirimWhatsapp::class)->Jalankan($nomor);

        $nomor->refresh();
        expect($nomor->Status)->toBe(StatusNomorWhatsapp::Terputus)->and($nomor->Aktif)->toBeFalse();
        expect(app(PembuatPengirimWhatsapp::class)->AmbilNomorPengirim($toko['Outlet']->Id, FungsiNomorWhatsapp::Pelanggan))->toBeNull();
    });

    it('memilih nomor outlet yang melayani fungsinya, mengirim lewat token perangkat nomor itu', function (): void {
        SiapkanKonsolWa();
        FakeFonnte();
        $toko = TokoNomorWa();
        $buat = fn (string $token, string $fungsi, string $nomor, bool $aktif = true) => NomorPengirimWhatsapp::query()->create([
            'IdTenant' => $toko['Tenant']->Id, 'IdOutlet' => $toko['Outlet']->Id, 'Label' => $token, 'NomorTelepon' => $nomor, 'Fungsi' => $fungsi,
            'Penyedia' => 'Fonnte', 'TokenPerangkat' => $token, 'Status' => StatusNomorWhatsapp::Terhubung, 'Aktif' => $aktif,
        ]);
        $buat('token-karyawan', 'Karyawan', '6281200000011');
        $buat('token-mati', 'Pelanggan', '6281200000012', aktif: false);
        $buat('token-pelanggan', 'Pelanggan', '6281200000013');

        $pembuat = app(PembuatPengirimWhatsapp::class);
        $pembuat->AmbilAktif($toko['Outlet']->Id, FungsiNomorWhatsapp::Pelanggan)->Kirim(new PesanWhatsapp('081234567890', 'Halo'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/send') && $r->header('Authorization')[0] === 'token-pelanggan');

        $pembuat->AmbilAktif($toko['Outlet']->Id, FungsiNomorWhatsapp::Karyawan)->Kirim(new PesanWhatsapp('081234567890', 'Laporan'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/send') && $r->header('Authorization')[0] === 'token-karyawan');
    });

    it('tanpa akun sesi di konsol nomor tidak bisa ditambah, dan semua rute hilang bila konsol belum mengaktifkan WhatsApp', function (): void {
        SiapkanKonsolWa(denganTokenAkun: false);
        $toko = TokoNomorWa();

        TambahNomorUji($this, $toko)->assertSessionHasErrors();
        expect(NomorPengirimWhatsapp::query()->count())->toBe(0);

        config(['integrasi.Whatsapp' => ['Penyedia' => null]]);
        BantuanOrganisasi::Masuk($this, $toko['Pemilik'], $toko['Tenant']->Id)->get('/kelola/pelanggan/whatsapp')->assertNotFound();
        TambahNomorUji($this, $toko)->assertNotFound();
    });

    it('nomor tenant lain tidak bisa dibuka atau diubah (isolasi tenant)', function (): void {
        SiapkanKonsolWa();
        FakeFonnte();
        $a = TokoNomorWa();
        TambahNomorUji($this, $a);
        $nomorA = NomorPengirimWhatsapp::query()->firstOrFail();

        $b = BantuanOrganisasi::BuatTenant('Toko Sembako Maju Jaya');
        OverrideTenant::query()->create([
            'IdTenant' => $b['Tenant']->Id, 'Jenis' => JenisOverride::Fitur->value, 'Kunci' => 'integrasi.whatsapp', 'Nilai' => null,
            'BerakhirPada' => now()->addDays(60), 'Alasan' => 'Uji isolasi',
            'DibuatOleh' => BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin)->Id,
        ]);

        BantuanOrganisasi::Masuk($this, $b['Pemilik'], $b['Tenant']->Id)
            ->postJson("/kelola/pelanggan/whatsapp/nomor/{$nomorA->Uuid}/qr")->assertNotFound();
    });

    it('perintah whatsapp:periksa-sambungan menandai nomor yang terputus dan membiarkan yang masih terhubung', function (): void {
        SiapkanKonsolWa();
        $toko = TokoNomorWa();
        $buat = fn (string $token, string $nomor) => NomorPengirimWhatsapp::query()->create([
            'IdTenant' => $toko['Tenant']->Id, 'IdOutlet' => $toko['Outlet']->Id, 'Label' => $token, 'NomorTelepon' => $nomor,
            'Penyedia' => 'Fonnte', 'TokenPerangkat' => $token, 'Status' => StatusNomorWhatsapp::Terhubung, 'Aktif' => true,
        ]);
        $putus = $buat('perangkat-putus', '6281200000051');
        $baik = $buat('perangkat-baik', '6281200000052');
        Http::fake(fn ($r) => Http::response(['status' => true, 'device_status' => $r->header('Authorization')[0] === 'perangkat-putus' ? 'disconnect' : 'connect']));

        $this->artisan('whatsapp:periksa-sambungan')->assertSuccessful();

        expect($putus->refresh()->Status)->toBe(StatusNomorWhatsapp::Terputus)->and($putus->Aktif)->toBeFalse()
            ->and($baik->refresh()->Status)->toBe(StatusNomorWhatsapp::Terhubung)->and($baik->Aktif)->toBeTrue();
    });
});
