<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Kueri;

use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Tenant\Kueri\PengaturanStrukTenant;
use App\Domain\Tenant\Kueri\ProfilTenant;

/**
 * Alamat logo tenant untuk kepala halaman pelanggan (D-238 sampai D-242): rute publik `/{slug}/logo` dengan outlet
 * terpilih (logo outlet menimpa logo usaha). Null bila belum ada logo, jadi kepala halaman memakai inisial toko.
 * Konteks tenant harus sudah dipasang pemanggil karena outlet dicari lewat scope `MilikTenant`.
 */
final class UrlLogoPublik
{
    public function __construct(
        private readonly PengaturanStrukTenant $pengaturan,
        private readonly ProfilTenant $profil,
    ) {}

    public function Ambil(int $idTenant, ?int $idOutlet = null): ?string
    {
        $outlet = $idOutlet === null
            ? Outlet::query()->orderBy('Id')->first(['Id', 'Uuid'])
            : Outlet::query()->whereKey($idOutlet)->first(['Id', 'Uuid']);

        if ($outlet === null || $this->pengaturan->AmbilPathLogoUsaha($idTenant, $outlet->Id) === null) {
            return null;
        }

        return url('/'.$this->profil->AmbilSlug($idTenant).'/logo').'?outlet='.$outlet->Uuid;
    }
}
