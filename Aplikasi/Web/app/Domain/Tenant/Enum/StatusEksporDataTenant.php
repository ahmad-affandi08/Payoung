<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Enum;

/**
 * Status ekspor data lengkap usaha (audit E5): Antre → Berjalan → Siap (lalu Kedaluwarsa) atau Gagal.
 */
enum StatusEksporDataTenant: string
{
    case Antre = 'Antre';
    case Berjalan = 'Berjalan';
    case Siap = 'Siap';
    case Gagal = 'Gagal';
    case Kedaluwarsa = 'Kedaluwarsa';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Antre => 'Menunggu giliran',
            self::Berjalan => 'Sedang disiapkan',
            self::Siap => 'Siap diunduh',
            self::Gagal => 'Gagal',
            self::Kedaluwarsa => 'Kedaluwarsa',
        };
    }

    public function CekAktif(): bool
    {
        return $this === self::Antre || $this === self::Berjalan;
    }
}
