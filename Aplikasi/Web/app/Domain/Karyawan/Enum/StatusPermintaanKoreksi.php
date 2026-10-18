<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Enum;

/** Status "Minta koreksi ke manajer" (F-18, D-180): menunggu, sudah dicatat/ditutup pengelola, atau ditolak. */
enum StatusPermintaanKoreksi: string
{
    case Menunggu = 'Menunggu';
    case Selesai = 'Selesai';
    case Ditolak = 'Ditolak';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Menunggu => 'Menunggu',
            self::Selesai => 'Selesai',
            self::Ditolak => 'Ditolak',
        };
    }
}
