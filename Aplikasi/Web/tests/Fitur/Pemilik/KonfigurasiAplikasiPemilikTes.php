<?php

declare(strict_types=1);

use App\Domain\Tenant\Model\FlagFitur;
use App\Domain\Tenant\Model\RilisAplikasi;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanAutentikasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * P-10 PGL-18: `GET /api/pemilik/v1/konfigurasi-aplikasi` memberi Aplikasi Owner versi terbaru & minimal (rilis `Pemilik`
 * per platform) dan flag fitur tenant aktif, dengan bentuk yang sama seperti konfigurasi aplikasi POS.
 */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 03:00:00', 'UTC'));
    BantuanPendaftaran::SiapkanPrasyarat();
    Cache::flush();
});

/** @param array<string, mixed> $timpa */
function BuatRilisPemilik(string $platform, string $versi, array $timpa = []): RilisAplikasi
{
    $r = new RilisAplikasi;
    $r->forceFill(array_replace([
        'Uuid' => (string) Str::ulid(),
        'Aplikasi' => 'Pemilik',
        'Platform' => $platform,
        'Kanal' => 'Stabil',
        'Versi' => $versi,
        'Status' => 'Aktif',
        'PersenRollout' => 100,
        'UrlUnduh' => "https://unduh.payoung.id/pemilik-{$platform}-{$versi}",
        'CatatanRilis' => "Perbaikan laporan {$versi}",
    ], $timpa))->save();

    return $r;
}

/** @return array{0: string, 1: string} token Owner dan uuid tenant */
function MasukSebagaiPemilikKonfigurasi(object $uji, string $namaToko): array
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

it('perlu masuk. Versi cadangan config bila belum ada rilis, tanpa header versi tidak dinilai wajib/ada pembaruan', function (): void {
    [$token, $uuidTenant] = MasukSebagaiPemilikKonfigurasi($this, 'Toko Konfigurasi Pemilik');

    $this->getJson('/api/pemilik/v1/konfigurasi-aplikasi')->assertUnauthorized();

    // Header versi dari proses masuk menempel di permintaan uji berikutnya; dikosongkan agar versi aplikasi tidak diketahui.
    $hasil = $this->withToken($token)->withHeader('X-Tenant', $uuidTenant)->withHeader('X-Versi-Aplikasi', '')
        ->getJson('/api/pemilik/v1/konfigurasi-aplikasi')->assertOk()->json();

    expect($hasil['Aplikasi'])->toMatchArray([
        'Platform' => 'Android',
        'VersiSaatIni' => null,
        'VersiTerbaru' => '1.0.0',
        'VersiMinimal' => '1.0.0',
        'AdaPembaruan' => false,
        'WajibPembaruan' => false,
    ])->and($hasil['FlagFitur'])->toBe([]);
});

it('versi di bawah minimal = WajibPembaruan. Di bawah terbaru = AdaPembaruan. Platform dari X-Platform-Aplikasi. Rilis Pos tidak ikut', function (): void {
    [$token, $uuidTenant] = MasukSebagaiPemilikKonfigurasi($this, 'Toko Rilis Pemilik');
    BuatRilisPemilik('Android', '1.4.0', ['VersiMinimum' => '1.2.0', 'VersiMinimumBerlakuPada' => now()->subDay()]);
    BuatRilisPemilik('Ios', '2.0.0', ['VersiMinimum' => '1.9.0', 'VersiMinimumBerlakuPada' => now()->subDay()]);
    BuatRilisPemilik('Android', '9.9.9', ['Aplikasi' => 'Pos']);
    $ambil = fn (string $versi, ?string $platform = null) => $this->withToken($token)->withHeader('X-Tenant', $uuidTenant)
        ->withHeaders(array_filter(['X-Versi-Aplikasi' => $versi, 'X-Platform-Aplikasi' => $platform]))
        ->getJson('/api/pemilik/v1/konfigurasi-aplikasi')->assertOk()->json('Aplikasi');

    expect($ambil('1.1.0'))->toMatchArray(['VersiTerbaru' => '1.4.0', 'VersiMinimal' => '1.2.0', 'AdaPembaruan' => true, 'WajibPembaruan' => true, 'CatatanRilis' => 'Perbaikan laporan 1.4.0'])
        ->and($ambil('1.3.0'))->toMatchArray(['AdaPembaruan' => true, 'WajibPembaruan' => false])
        ->and($ambil('1.4.0'))->toMatchArray(['AdaPembaruan' => false, 'WajibPembaruan' => false])
        ->and($ambil('1.5.0+12', 'Android'))->toMatchArray(['AdaPembaruan' => false, 'WajibPembaruan' => false])
        ->and($ambil('1.5.0', 'Ios'))->toMatchArray(['Platform' => 'Ios', 'VersiTerbaru' => '2.0.0', 'WajibPembaruan' => true])
        ->and($ambil('1.5.0', 'Windows'))->toMatchArray(['Platform' => 'Android', 'VersiTerbaru' => '1.4.0']);
});

it('flag fitur tenant: Global mati = kill switch untuk tenant mana pun. Aturan Tenant lain tidak bocor', function (): void {
    [$token, $uuidTenant, $idTenant] = MasukSebagaiPemilikKonfigurasi($this, 'Toko Flag Pemilik');
    [$tokenLain, $uuidTenantLain] = MasukSebagaiPemilikKonfigurasi($this, 'Toko Flag Lain');

    (new FlagFitur)->forceFill([
        'Uuid' => (string) Str::ulid(), 'Kunci' => 'pemilik.insight', 'Cakupan' => 'Global', 'Nilai' => false, 'Alasan' => 'Insight bermasalah, dimatikan sementara',
    ])->save();
    (new FlagFitur)->forceFill([
        'Uuid' => (string) Str::ulid(), 'Kunci' => 'pemilik.karyawan', 'Cakupan' => 'Tenant', 'IdObjek' => $idTenant, 'Nilai' => false, 'Alasan' => 'Dimatikan untuk tenant ini saja',
    ])->save();
    Cache::flush();

    $ambil = fn (string $tokenUji, string $uuid) => $this->withToken($tokenUji)->withHeader('X-Tenant', $uuid)
        ->getJson('/api/pemilik/v1/konfigurasi-aplikasi')->assertOk()->json('FlagFitur');

    // Aturan Tenant hanya berlaku untuk tenantnya; tenant lain jatuh ke bawaan (hidup).
    expect($ambil($token, $uuidTenant))->toBe(['pemilik.insight' => false, 'pemilik.karyawan' => false])
        ->and($ambil($tokenLain, $uuidTenantLain))->toBe(['pemilik.insight' => false, 'pemilik.karyawan' => true]);
});
