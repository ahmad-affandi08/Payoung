<?php

declare(strict_types=1);

namespace App\Domain\Bersama\Unduhan\Enum;

/**
 * Status berkas di Pusat Unduhan (D-124): Menunggu → Diproses → Siap (lalu Kedaluwarsa) atau Gagal.
 */
enum StatusBerkasUnduhan: string
{
    case Menunggu = 'Menunggu';
    case Diproses = 'Diproses';
    case Siap = 'Siap';
    case Gagal = 'Gagal';
    case Kedaluwarsa = 'Kedaluwarsa';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Menunggu => 'Menunggu giliran',
            self::Diproses => 'Sedang disiapkan',
            self::Siap => 'Siap diunduh',
            self::Gagal => 'Gagal',
            self::Kedaluwarsa => 'Kedaluwarsa',
        };
    }

    public function CekAktif(): bool
    {
        return $this === self::Menunggu || $this === self::Diproses;
    }
}
