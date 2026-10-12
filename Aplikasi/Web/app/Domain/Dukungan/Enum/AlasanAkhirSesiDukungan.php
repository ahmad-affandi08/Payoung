<?php

declare(strict_types=1);

namespace App\Domain\Dukungan\Enum;

/** Sebab sebuah sesi akses dukungan berakhir. */
enum AlasanAkhirSesiDukungan: string
{
    case Selesai = 'Selesai';
    case Dicabut = 'Dicabut';
    case Kedaluwarsa = 'Kedaluwarsa';
    case Digantikan = 'Digantikan';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Selesai => 'Diakhiri petugas',
            self::Dicabut => 'Izin dicabut',
            self::Kedaluwarsa => 'Waktu habis',
            self::Digantikan => 'Digantikan sesi baru',
        };
    }
}
