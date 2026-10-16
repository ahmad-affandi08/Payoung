<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Enum;

/** Keputusan atas penawaran: `Terima` atau `Tolak` (dari pelanggan di halaman publik, atau dikonfirmasi penagih). */
enum KeputusanPenawaran: string
{
    case Terima = 'Terima';
    case Tolak = 'Tolak';

    public function AmbilStatus(): StatusPenawaran
    {
        return match ($this) {
            self::Terima => StatusPenawaran::Diterima,
            self::Tolak => StatusPenawaran::Ditolak,
        };
    }
}
