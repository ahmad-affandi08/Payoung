<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Tenant\Enum;

/** Hasil skor kesehatan tenant (P-07 PGL-09): `Sehat` (≥ 70), `PerluPerhatian` (40–69), `Berisiko` (< 40). */
enum KategoriKesehatan: string
{
    case Sehat = 'Sehat';
    case PerluPerhatian = 'PerluPerhatian';
    case Berisiko = 'Berisiko';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Sehat => 'Sehat',
            self::PerluPerhatian => 'Perlu perhatian',
            self::Berisiko => 'Berisiko',
        };
    }
}
