<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Enum;

/**
 * Status satu baris berkas impor stok awal (DesainF05a B.4): Valid (siap), Galat (ditolak), Diterapkan (sudah masuk
 * ke dokumen stok awal Draf `ImporStokAwalBaris.IdStokAwal`).
 */
enum StatusBarisImporStokAwal: string
{
    case Valid = 'Valid';
    case Galat = 'Galat';
    case Diterapkan = 'Diterapkan';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Valid => 'Valid',
            self::Galat => 'Ada masalah',
            self::Diterapkan => 'Masuk draf',
        };
    }
}
