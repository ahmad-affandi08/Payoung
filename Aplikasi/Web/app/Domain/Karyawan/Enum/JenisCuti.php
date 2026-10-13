<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Enum;

/** Jenis ketidakhadiran yang diketahui (F-18 cuti/izin): `Cuti` tahunan, `Sakit`, atau `Izin` keperluan lain. */
enum JenisCuti: string
{
    case Cuti = 'Cuti';
    case Sakit = 'Sakit';
    case Izin = 'Izin';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Cuti => 'Cuti',
            self::Sakit => 'Sakit',
            self::Izin => 'Izin',
        };
    }
}
