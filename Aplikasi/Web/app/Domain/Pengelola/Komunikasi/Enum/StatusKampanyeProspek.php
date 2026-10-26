<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Komunikasi\Enum;

/**
 * Siklus kampanye prospek (D-216): Draf → Berjalan ⇄ Dijeda → Selesai, atau Dibatalkan kapan pun sebelum selesai.
 * Penerima ditetapkan saat Draf menjadi Berjalan.
 */
enum StatusKampanyeProspek: string
{
    case Draf = 'Draf';
    case Berjalan = 'Berjalan';
    case Dijeda = 'Dijeda';
    case Selesai = 'Selesai';
    case Dibatalkan = 'Dibatalkan';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Draf => 'Draf',
            self::Berjalan => 'Berjalan',
            self::Dijeda => 'Dijeda',
            self::Selesai => 'Selesai',
            self::Dibatalkan => 'Dibatalkan',
        };
    }

    /** @return list<self> */
    public function AmbilTujuan(): array
    {
        return match ($this) {
            self::Draf => [self::Berjalan, self::Dibatalkan],
            self::Berjalan => [self::Dijeda, self::Dibatalkan],
            self::Dijeda => [self::Berjalan, self::Dibatalkan],
            self::Selesai, self::Dibatalkan => [],
        };
    }
}
