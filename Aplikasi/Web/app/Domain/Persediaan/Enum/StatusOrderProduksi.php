<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Enum;

/**
 * Status order produksi (F-05e). Perubahan hanya lewat `BisaBerubahKe()` dan dicatat di `RiwayatStatusDokumen`:
 * Draf → Diposting | Dibatalkan; Diposting → Dibatalkan (mutasi & jurnal pembalik, selama hasil belum terpakai).
 */
enum StatusOrderProduksi: string
{
    case Draf = 'Draf';
    case Diposting = 'Diposting';
    case Dibatalkan = 'Dibatalkan';

    public function BisaBerubahKe(self $tujuan): bool
    {
        return in_array($tujuan, match ($this) {
            self::Draf => [self::Diposting, self::Dibatalkan],
            self::Diposting => [self::Dibatalkan],
            self::Dibatalkan => [],
        }, true);
    }

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Draf => 'Draf',
            self::Diposting => 'Tercatat',
            self::Dibatalkan => 'Dibatalkan',
        };
    }
}
