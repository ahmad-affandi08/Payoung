<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Enum;

/**
 * Status permintaan turun paket (BR-P04.4): Menunggu → Diterapkan (paket sudah berganti dan kapasitas disesuaikan)
 * atau Dibatalkan (Owner membatalkan, atau tidak lagi relevan karena langganan berubah). Keduanya final.
 */
enum StatusPermintaanTurunPaket: string
{
    case Menunggu = 'Menunggu';
    case Diterapkan = 'Diterapkan';
    case Dibatalkan = 'Dibatalkan';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Menunggu => 'Menunggu perpanjangan berikutnya',
            self::Diterapkan => 'Sudah berlaku',
            self::Dibatalkan => 'Dibatalkan',
        };
    }
}
