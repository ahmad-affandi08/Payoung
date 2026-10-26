<?php

declare(strict_types=1);

use App\Domain\Tenant\Model\PengumumanPlatform;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanAutentikasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * P-10 PGL-19 (v3.47): `GET /api/pemilik/v1/pengumuman` memberi Aplikasi Pemilik pengumuman yang berlaku untuk tenant
 * aktif — sasaran platform kosong atau memuat `Pemilik`; yang khusus kasir/web, draf, dan yang lewat masa tampil tidak.
 */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 03:00:00', 'UTC'));
    BantuanPendaftaran::SiapkanPrasyarat();
    Cache::flush();
});

/** @param array<string, mixed> $sasaran */
function BuatPengumumanPemilik(string $judul, string $jenis, array $sasaran, string $status = 'Terbit'): PengumumanPlatform
{
    $p = new PengumumanPlatform;
    $p->forceFill([
        'Uuid' => (string) Str::ulid(),
        'Judul' => $judul,
        'Isi' => 'Isi pengumuman untuk pemilik toko.',
        'Jenis' => $jenis,
        'Sasaran' => $sasaran,
        'TampilMulai' => now()->subHour(),
        'TampilSampai' => now()->addDays(3),
        'Status' => $status,
    ])->save();

    return $p;
}

it('hanya pengumuman terbit untuk semua platform atau platform Pemilik. Perlu masuk & tenant', function (): void {
    $data = BantuanOrganisasi::BuatTenant('Toko Pengumuman Pemilik');
    $data['Pemilik']->forceFill(['EmailDiverifikasiPada' => now()])->save();
    BuatPengumumanPemilik('Fitur laporan baru', 'YangBaru', ['Platform' => ['Pemilik']]);
    BuatPengumumanPemilik('Harga paket berubah', 'Penting', []);
    BuatPengumumanPemilik('Khusus kasir Android', 'Info', ['Platform' => ['Android', 'Web']]);
    BuatPengumumanPemilik('Masih draf', 'Info', [], 'Draf');

    $this->getJson('/api/pemilik/v1/pengumuman')->assertUnauthorized();

    $token = (string) $this->withHeader('X-Versi-Aplikasi', '1.0.0')->postJson('/api/pemilik/v1/masuk', [
        'Email' => $data['Pemilik']->Email,
        'KataSandi' => BantuanAutentikasi::KATA_SANDI,
        'NamaPerangkat' => 'Pixel Owner',
    ])->assertOk()->json('Token');

    $hasil = $this->withToken($token)->withHeader('X-Tenant', $data['Tenant']->Uuid)
        ->getJson('/api/pemilik/v1/pengumuman')->assertOk()->json('Pengumuman');
    expect(array_column($hasil, 'Judul'))->toBe(['Harga paket berubah', 'Fitur laporan baru'])
        ->and($hasil[0]['BolehDitutup'])->toBeFalse()
        ->and($hasil[1]['LabelJenis'])->toBeString();

    $this->travelTo(CarbonImmutable::parse('2026-10-11 03:00:00', 'UTC'));
    Cache::flush();
    expect($this->withToken($token)->withHeader('X-Tenant', $data['Tenant']->Uuid)
        ->getJson('/api/pemilik/v1/pengumuman')->assertOk()->json('Pengumuman'))->toBe([]);
});
