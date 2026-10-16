<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Kueri;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Tenant\Kueri\ProfilTenant;

/**
 * Kode template sektor yang dipakai tenant aktif: sektor tiap outlet ditambah jenis usaha tambahan yang dipilih di
 * panduan awal (usaha campuran, F-01 `SektorLain`). Dipakai untuk sasaran pengumuman per sektor (P-10 PGL-19) dan
 * untuk menyaring menu & isian khusus sektor di back-office (D-38, D-48).
 */
final class SektorOutletTenant
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly ProfilTenant $profil,
    ) {}

    /** @return list<string> */
    public function AmbilKode(): array
    {
        /** @var list<string> $kode */
        $kode = array_map(
            'strval',
            Outlet::query()->whereNotNull('TemplateSektor')->pluck('TemplateSektor')->all(),
        );

        $idTenant = $this->konteks->Ambil();

        return $idTenant === null || $kode === [] ? array_values(array_unique($kode)) : $this->GabungSektorLain($idTenant, $kode);
    }

    /**
     * D-167: usaha Perusahaan = semua sektor yang dipakai tenant berawalan `BIZ-` (jasa profesional, kontraktor,
     * manufaktur). Mereka menagih lewat invoice dan penjualan grosir, bukan kasir, jadi Beranda, panduan, dan menu tidak
     * menawarkan kasir. Tenant tanpa sektor, atau campuran dengan sektor lain (mis. konsultan yang juga punya toko), bukan.
     */
    public function CekHanyaPerusahaan(): bool
    {
        $kode = $this->AmbilKode();

        return $kode !== [] && array_filter($kode, fn (string $k): bool => ! str_starts_with($k, 'BIZ-')) === [];
    }

    /**
     * Sektor satu outlet (aplikasi kasir, D-48): template outlet itu ditambah jenis usaha tambahan tenant. Kosong bila
     * outlet belum menerapkan template (aplikasi menampilkan semua fitur).
     *
     * @return list<string>
     */
    public function AmbilKodeUntukOutlet(int $idTenant, int $idOutlet): array
    {
        $template = Outlet::query()->whereKey($idOutlet)->value('TemplateSektor');

        return is_string($template) && $template !== '' ? $this->GabungSektorLain($idTenant, [$template]) : [];
    }

    /**
     * @param  list<string>  $kode
     * @return list<string>
     */
    private function GabungSektorLain(int $idTenant, array $kode): array
    {
        $tambahan = $this->profil->Ambil($idTenant)['Pengaturan']['Sektor'] ?? [];

        foreach (is_array($tambahan) ? $tambahan : [] as $lain) {
            if (is_string($lain) && $lain !== '') {
                $kode[] = $lain;
            }
        }

        return array_values(array_unique($kode));
    }
}
