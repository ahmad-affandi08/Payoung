<?php

declare(strict_types=1);

namespace App\Domain\Bengkel\Enum;

/**
 * Jenis unit yang diservis (D-142). `Kendaraan` memakai plat nomor, KM, rangka & mesin; `Perangkat` (HP, laptop,
 * televisi, peralatan elektronik) memakai IMEI / nomor seri yang boleh kosong. Keduanya satu alur perintah kerja.
 */
enum JenisUnitServis: string
{
    case Kendaraan = 'Kendaraan';
    case Perangkat = 'Perangkat';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Kendaraan => 'Kendaraan',
            self::Perangkat => 'Perangkat elektronik',
        };
    }
}
