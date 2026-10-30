<?php

declare(strict_types=1);

use App\Domain\Organisasi\Kueri\KeanggotaanPengguna;
use App\Domain\Tenant\Enum\StatusTenant;
use App\Domain\Tenant\Model\Tenant;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * Audit N+1: perintah terjadwal yang menyapu semua tenant (tiap jam, bahkan tiap 5 menit) tidak boleh lagi mengunjungi
 * tenant yang akunnya sudah ditutup (UU PDP P-07).
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

it('AmbilSemuaIdTenant hanya memuat tenant yang belum ditutup', function (): void {
    $buka = BantuanOrganisasi::BuatTenant('Warung Masih Buka')['Tenant'];
    $tutup = BantuanOrganisasi::BuatTenant('Warung Sudah Tutup')['Tenant'];
    Tenant::query()->whereKey($tutup->Id)->update(['Status' => StatusTenant::Ditutup->value]);

    $semua = app(KeanggotaanPengguna::class)->AmbilSemuaIdTenant();

    expect($semua)->toContain($buka->Id)->not->toContain($tutup->Id);
});
