<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Enum;

enum StatusTopUpSaldoWhatsapp: string
{
    case Menunggu = 'Menunggu';
    case Lunas = 'Lunas';
    case Gagal = 'Gagal';
    case Kedaluwarsa = 'Kedaluwarsa';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Menunggu => 'Menunggu pembayaran',
            self::Lunas => 'Lunas',
            self::Gagal => 'Gagal',
            self::Kedaluwarsa => 'Kedaluwarsa',
        };
    }
}
