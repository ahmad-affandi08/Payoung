<?php

declare(strict_types=1);

namespace App\Domain\Dukungan\Enum;

/** Hasil satu alat bantu dukungan: `TanpaPerubahan` = alat jalan tetapi keadaan sudah benar (tidak ada yang diubah). */
enum StatusHasilAlatDukungan: string
{
    case Berhasil = 'Berhasil';
    case TanpaPerubahan = 'TanpaPerubahan';
    case Gagal = 'Gagal';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Berhasil => 'Berhasil',
            self::TanpaPerubahan => 'Tanpa perubahan',
            self::Gagal => 'Gagal',
        };
    }
}
