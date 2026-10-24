<?php

declare(strict_types=1);

namespace App\Domain\Bersama\Tindakan\Data;

use Carbon\CarbonImmutable;

/**
 * Siapa yang membuka Kotak Tindakan (D-23 C): tenant, pengguna, izinnya (kunci string, pemilik = semua), outlet yang
 * boleh dilihat (null = semua), dan tanggal bisnis hari ini.
 */
final readonly class DataKonteksTindakan
{
    /**
     * @param  list<string>  $izin
     * @param  list<int>|null  $idOutletBoleh
     * @param  list<string>  $sektor  kode sektor tenant (D-183); kosong = belum diketahui, semua penyedia dijalankan
     */
    public function __construct(
        public int $idTenant,
        public int $idPengguna,
        public bool $pemilik,
        public array $izin,
        public ?array $idOutletBoleh,
        public CarbonImmutable $hariIni,
        public array $sektor = [],
    ) {}

    /**
     * D-183: apakah penyedia berbatas sektor relevan untuk tenant ini (aturan sama dengan `Pustaka/Sektor.ts` di web).
     *
     * @param  list<string>|null  $awalan
     * @param  list<string>  $kecuali
     */
    public function CekSesuaiSektor(?array $awalan, array $kecuali = []): bool
    {
        if ($this->sektor === []) {
            return true;
        }

        $cocok = fn (string $a, string $kode): bool => $kode === $a || str_starts_with($kode, $a.'-');

        if ($kecuali !== [] && array_filter($this->sektor, fn (string $kode): bool => ! array_filter($kecuali, fn (string $a): bool => $cocok($a, $kode))) === []) {
            return false;
        }

        if ($awalan === null) {
            return true;
        }

        foreach ($awalan as $a) {
            foreach ($this->sektor as $kode) {
                if ($cocok($a, $kode)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function CekIzin(string $kunci): bool
    {
        return $this->pemilik || in_array($kunci, $this->izin, true);
    }
}
