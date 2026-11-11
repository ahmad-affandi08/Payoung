<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Harga\Enum;

/** Nasib usulan harga yang melewati batas aman: menunggu, diterapkan, ditolak pemilik, atau digantikan usulan yang lebih baru. */
enum StatusUsulanHarga: string
{
    case Menunggu = 'Menunggu';
    case Diterapkan = 'Diterapkan';
    case Ditolak = 'Ditolak';
    case Digantikan = 'Digantikan';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Menunggu => 'Menunggu keputusan',
            self::Diterapkan => 'Diterapkan',
            self::Ditolak => 'Ditolak',
            self::Digantikan => 'Digantikan usulan baru',
        };
    }
}
