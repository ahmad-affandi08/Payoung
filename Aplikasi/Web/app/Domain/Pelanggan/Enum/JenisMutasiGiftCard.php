<?php

declare(strict_types=1);

namespace App\Domain\Pelanggan\Enum;

/**
 * Jenis baris buku gift card (CRM-06, D-153). Positif: `Jual`, `BatalPakai`. Negatif: `Pakai`, `Hangus`, `Kembali`.
 */
enum JenisMutasiGiftCard: string
{
    case Jual = 'Jual';
    case Pakai = 'Pakai';
    case BatalPakai = 'BatalPakai';
    case Hangus = 'Hangus';
    case Kembali = 'Kembali';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Jual => 'Kartu dijual',
            self::Pakai => 'Dipakai belanja',
            self::BatalPakai => 'Dikembalikan karena void',
            self::Hangus => 'Sisa saldo hangus',
            self::Kembali => 'Dibatalkan, uang dikembalikan',
        };
    }
}
