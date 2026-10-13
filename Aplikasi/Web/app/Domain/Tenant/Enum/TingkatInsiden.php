<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Enum;

/** Tingkat insiden layanan (P-11 PGL-21): `Kecil` gangguan terbatas, `Besar` fitur utama terganggu, `Kritis` layanan tidak bisa dipakai. */
enum TingkatInsiden: string
{
    case Kecil = 'Kecil';
    case Besar = 'Besar';
    case Kritis = 'Kritis';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Kecil => 'Kecil',
            self::Besar => 'Besar',
            self::Kritis => 'Kritis',
        };
    }

    /** Kondisi komponen di halaman status: Kecil = gangguan sebagian, Besar/Kritis = gangguan besar. */
    public function AmbilKondisiKomponen(): KondisiKomponen
    {
        return $this === self::Kecil ? KondisiKomponen::Sebagian : KondisiKomponen::Besar;
    }
}
