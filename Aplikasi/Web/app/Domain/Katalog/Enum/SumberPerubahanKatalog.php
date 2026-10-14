<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Enum;

/**
 * Asal perubahan katalog (F-03): form back-office, impor Excel/CSV (tanpa audit per produk; audit per potongan),
 * panduan awal F-01, atau sistem (produk yang disiapkan fitur lain, mis. selisih minimum charge, D-144; tidak
 * menghabiskan kuota `BatasSku`).
 */
enum SumberPerubahanKatalog: string
{
    case Manual = 'Manual';
    case Impor = 'Impor';
    case PanduanAwal = 'PanduanAwal';
    case Sistem = 'Sistem';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Impor => 'Impor',
            self::PanduanAwal => 'Panduan awal',
            self::Sistem => 'Sistem',
        };
    }
}
