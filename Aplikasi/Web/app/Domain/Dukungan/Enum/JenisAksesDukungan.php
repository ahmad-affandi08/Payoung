<?php

declare(strict_types=1);

namespace App\Domain\Dukungan\Enum;

/** Asal izin akses dukungan (PGL-16): diberikan Owner, atau dibuka Super Admin tanpa izin untuk keadaan darurat. */
enum JenisAksesDukungan: string
{
    case Berizin = 'Berizin';
    case Darurat = 'Darurat';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Berizin => 'Diizinkan Owner',
            self::Darurat => 'Akses darurat',
        };
    }
}
