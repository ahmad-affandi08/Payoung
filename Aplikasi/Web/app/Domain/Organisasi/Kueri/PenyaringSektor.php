<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Kueri;

use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Enum\JenisPerangkat;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;

/**
 * D-183: fitur khusus sektor (peran bawaan, izin, jenis perangkat) hanya ditawarkan kepada tenant yang memakai sektornya.
 * Aturan sama dengan `Pustaka/Sektor.ts` di web: awalan cocok per segmen (`SVC` mencakup `SVC-WRK`), dan sektor tenant
 * yang belum diketahui (belum menerapkan template) berarti semua tampil. Yang sudah terpakai (peran beranggota, izin yang
 * dipegang sebuah peran) tidak pernah disembunyikan, jadi tenant yang berganti sektor tidak kehilangan datanya.
 */
final class PenyaringSektor
{
    public function __construct(private readonly SektorOutletTenant $sektor) {}

    /** @param  list<string>|null  $awalan  null = fitur umum */
    public function CekSesuai(?array $awalan): bool
    {
        $kode = $this->sektor->AmbilKode();

        if ($awalan === null || $kode === []) {
            return true;
        }

        foreach ($awalan as $a) {
            foreach ($kode as $k) {
                if ($k === $a || str_starts_with($k, $a.'-')) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Peran bawaan khusus sektor (Pelayan, Salesman, Apoteker) disembunyikan bila sektornya tidak dipakai dan belum beranggota. */
    public function CekPeranTampil(?string $kodePeran, bool $bawaan, int $jumlahAnggota): bool
    {
        if (! $bawaan || $jumlahAnggota > 0) {
            return true;
        }

        $peran = PeranTenantBawaan::tryFrom((string) $kodePeran);

        return $peran === null || $this->CekSesuai($peran->AmbilSektor());
    }

    /**
     * @param  list<string>  $izinTerpakai  kunci izin yang dipegang minimal satu peran tenant
     */
    public function CekIzinTampil(IzinTenant $izin, array $izinTerpakai): bool
    {
        return in_array($izin->value, $izinTerpakai, true) || $this->CekSesuai($izin->AmbilSektor());
    }

    public function CekJenisPerangkatTampil(JenisPerangkat $jenis): bool
    {
        return $this->CekSesuai($jenis->AmbilSektor());
    }
}
