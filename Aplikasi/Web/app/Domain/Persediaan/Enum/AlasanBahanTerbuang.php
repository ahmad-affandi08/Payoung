<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Enum;

/** Alasan bahan/menu terbuang (F-05f), untuk analisis food cost. */
enum AlasanBahanTerbuang: string
{
    case Kedaluwarsa = 'Kedaluwarsa';
    case Rusak = 'Rusak';
    case SalahBuat = 'SalahBuat';
    case TidakTerjual = 'TidakTerjual';
    case Tumpah = 'Tumpah';
    case VoidItem = 'VoidItem';
    case Lainnya = 'Lainnya';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Kedaluwarsa => 'Kedaluwarsa / basi',
            self::Rusak => 'Rusak',
            self::SalahBuat => 'Salah buat / dikembalikan tamu',
            self::TidakTerjual => 'Sisa tidak terjual',
            self::Tumpah => 'Tumpah / jatuh',
            self::VoidItem => 'Item dibatalkan setelah dibuat',
            self::Lainnya => 'Lainnya',
        };
    }
}
