<?php

declare(strict_types=1);

namespace App\Domain\Dukungan\Enum;

/**
 * Tingkat penanganan tiket (P-09 "Eskalasi L1 → L2 → Teknis"). Hanya naik; tiket baru selalu mulai di L1.
 */
enum TingkatEskalasiTiket: string
{
    case L1 = 'L1';
    case L2 = 'L2';
    case Teknis = 'Teknis';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::L1 => 'L1 | Dukungan',
            self::L2 => 'L2 | Dukungan senior',
            self::Teknis => 'Teknis',
        };
    }

    public function AmbilUrutan(): int
    {
        return match ($this) {
            self::L1 => 1,
            self::L2 => 2,
            self::Teknis => 3,
        };
    }

    public function CekLebihTinggiDari(self $lain): bool
    {
        return $this->AmbilUrutan() > $lain->AmbilUrutan();
    }
}
