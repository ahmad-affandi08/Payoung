<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Enum;

/**
 * Status permintaan tukar shift (F-18, D-251): menunggu rekan, menunggu pengelola, lalu disetujui atau ditolak. Pemohon
 * boleh membatalkan selama belum diputuskan.
 */
enum StatusTukarShift: string
{
    case MenungguRekan = 'MenungguRekan';
    case MenungguPengelola = 'MenungguPengelola';
    case Disetujui = 'Disetujui';
    case Ditolak = 'Ditolak';
    case Dibatalkan = 'Dibatalkan';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::MenungguRekan => 'Menunggu rekan',
            self::MenungguPengelola => 'Menunggu pengelola',
            self::Disetujui => 'Disetujui',
            self::Ditolak => 'Ditolak',
            self::Dibatalkan => 'Dibatalkan',
        };
    }

    /** Masih bisa berubah (belum final). */
    public function CekTerbuka(): bool
    {
        return $this === self::MenungguRekan || $this === self::MenungguPengelola;
    }

    /** @return list<string> */
    public static function NilaiTerbuka(): array
    {
        return [self::MenungguRekan->value, self::MenungguPengelola->value];
    }
}
