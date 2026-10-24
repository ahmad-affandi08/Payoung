<?php

declare(strict_types=1);

namespace App\Domain\Bersama\Tindakan\Kontrak;

/**
 * D-183: penyedia Kotak Tindakan yang hanya bermakna bagi sektor usaha tertentu. `KotakTindakan` melewatinya bila sektor
 * tenant diketahui dan tidak cocok, jadi butir laundry tidak muncul di salon dan butir kasir tidak muncul di usaha jasa
 * profesional. Aturannya sama dengan menu web: awalan cocok per segmen; sektor belum diketahui = semua tampil.
 */
interface TerbatasSektor
{
    /**
     * Awalan kode sektor yang memakai penyedia ini; null = semua sektor.
     *
     * @return list<string>|null
     */
    public function AmbilSektor(): ?array;

    /**
     * Penyedia disembunyikan bila SEMUA sektor tenant ada di daftar ini (mis. `BIZ`: usaha tanpa kasir).
     *
     * @return list<string>
     */
    public function AmbilKecualiSektor(): array;
}
