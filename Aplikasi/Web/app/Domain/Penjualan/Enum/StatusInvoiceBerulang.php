<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Enum;

/**
 * Status templat invoice berulang: `Aktif` ⇄ `Dijeda`, dan `Selesai` (habis jadwalnya atau dihentikan; tidak bisa
 * dihidupkan lagi, buat templat baru bila perlu).
 */
enum StatusInvoiceBerulang: string
{
    case Aktif = 'Aktif';
    case Dijeda = 'Dijeda';
    case Selesai = 'Selesai';

    public function BisaBerubahKe(self $tujuan): bool
    {
        return match ($this) {
            self::Aktif => in_array($tujuan, [self::Dijeda, self::Selesai], true),
            self::Dijeda => in_array($tujuan, [self::Aktif, self::Selesai], true),
            self::Selesai => false,
        };
    }

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Aktif => 'Aktif',
            self::Dijeda => 'Dijeda',
            self::Selesai => 'Selesai',
        };
    }
}
