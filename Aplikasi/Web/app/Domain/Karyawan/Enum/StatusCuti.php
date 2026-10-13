<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Enum;

/** Status catatan cuti/izin: `Aktif` berlaku, `Dibatalkan` tidak lagi dihitung (baris tetap tersimpan). */
enum StatusCuti: string
{
    case Aktif = 'Aktif';
    case Dibatalkan = 'Dibatalkan';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Aktif => 'Berlaku',
            self::Dibatalkan => 'Dibatalkan',
        };
    }
}
