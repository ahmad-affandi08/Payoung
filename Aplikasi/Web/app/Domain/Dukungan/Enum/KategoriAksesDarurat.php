<?php

declare(strict_types=1);

namespace App\Domain\Dukungan\Enum;

/** Alasan sah akses darurat tanpa izin Owner (P-09 langkah 5). */
enum KategoriAksesDarurat: string
{
    case InsidenKeamanan = 'InsidenKeamanan';
    case PermintaanHukum = 'PermintaanHukum';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::InsidenKeamanan => 'Insiden keamanan',
            self::PermintaanHukum => 'Permintaan hukum',
        };
    }
}
