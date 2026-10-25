<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Komunikasi\Enum;

/** Siklus siaran: Terjadwal → Mengirim → Selesai, atau Dibatalkan selama belum mulai dikirim (D-210). */
enum StatusSiaran: string
{
    case Terjadwal = 'Terjadwal';
    case Mengirim = 'Mengirim';
    case Selesai = 'Selesai';
    case Dibatalkan = 'Dibatalkan';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Terjadwal => 'Terjadwal',
            self::Mengirim => 'Sedang dikirim',
            self::Selesai => 'Selesai',
            self::Dibatalkan => 'Dibatalkan',
        };
    }
}
