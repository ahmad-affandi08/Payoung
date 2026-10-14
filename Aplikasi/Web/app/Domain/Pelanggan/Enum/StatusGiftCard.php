<?php

declare(strict_types=1);

namespace App\Domain\Pelanggan\Enum;

/**
 * Status gift card (CRM-06, D-153). `Aktif` = punya saldo dan belum lewat masa berlaku; `Habis` = saldo nol karena
 * dipakai (kembali `Aktif` bila pemakaian di-void); `Hangus` = sisa saldo diakui pendapatan lain (kedaluwarsa atau
 * dihanguskan); `Dibatalkan` = penjualan kartu dibatalkan dan uangnya dikembalikan.
 */
enum StatusGiftCard: string
{
    case Aktif = 'Aktif';
    case Habis = 'Habis';
    case Hangus = 'Hangus';
    case Dibatalkan = 'Dibatalkan';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Aktif => 'Aktif',
            self::Habis => 'Habis dipakai',
            self::Hangus => 'Hangus',
            self::Dibatalkan => 'Dibatalkan',
        };
    }
}
