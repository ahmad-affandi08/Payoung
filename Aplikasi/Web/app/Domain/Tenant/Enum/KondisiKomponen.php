<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Enum;

/** Kondisi satu komponen layanan di halaman status publik. Urutan nilai `Bobot()` menentukan yang terburuk. */
enum KondisiKomponen: string
{
    case Normal = 'Normal';
    case Sebagian = 'Sebagian';
    case Besar = 'Besar';

    public function Bobot(): int
    {
        return match ($this) {
            self::Normal => 0,
            self::Sebagian => 1,
            self::Besar => 2,
        };
    }

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Normal => 'Normal',
            self::Sebagian => 'Gangguan sebagian',
            self::Besar => 'Gangguan besar',
        };
    }
}
