<?php

declare(strict_types=1);

use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Kueri\AksesPengguna;
use App\Domain\Organisasi\Model\TenantPengguna;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * Audit N+1: data awal POS memuat semua staf perangkat. Izin banyak anggota dibaca dengan satu kueri peran, dan hasilnya
 * harus sama persis dengan `AksesPengguna::Ambil` per anggota.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

it('AmbilIzinBanyak memberi izin yang sama dengan Ambil untuk pemilik dan setiap peran', function (): void {
    ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant('Toko Izin Banyak');
    $kasir = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Kasir);
    $akuntan = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Akuntan);
    BantuanOrganisasi::AturKonteks($tenant->Id);
    $anggota = TenantPengguna::query()->where('IdTenant', $tenant->Id)->get();
    $akses = app(AksesPengguna::class);

    $banyak = $akses->AmbilIzinBanyak($anggota);

    expect($banyak)->toHaveCount(3);

    foreach ([$pemilik, $kasir, $akuntan] as $pengguna) {
        expect($banyak[$pengguna->Id])->toBe($akses->Ambil($tenant->Id, $pengguna->Id)['Izin'] ?? []);
    }

    expect($banyak[$kasir->Id])->not->toBe($banyak[$akuntan->Id])
        ->and(count($banyak[$pemilik->Id]))->toBeGreaterThan(count($banyak[$kasir->Id]))
        ->and($akses->AmbilIzinBanyak([]))->toBe([]);
});
