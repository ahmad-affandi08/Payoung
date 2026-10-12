<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Enum;

/** Cara uang QRIS tanpa penjualan ditutup (E8): dikembalikan ke pelanggan, atau diakui sebagai pendapatan lain. */
enum HasilPenutupanTagihanQris: string
{
    case Dikembalikan = 'Dikembalikan';
    case DiakuiPendapatan = 'DiakuiPendapatan';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Dikembalikan => 'Dikembalikan ke pelanggan',
            self::DiakuiPendapatan => 'Diakui sebagai pendapatan lain',
        };
    }
}
