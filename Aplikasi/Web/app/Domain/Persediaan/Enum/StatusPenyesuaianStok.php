<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Enum;

/**
 * Status penyesuaian stok (F-05b). Perubahan hanya lewat `BisaBerubahKe()` dan dicatat di `RiwayatStatusDokumen`:
 * Draf → MenungguPersetujuan | Diposting | Dibatalkan; MenungguPersetujuan → Diposting (disetujui) | Draf (ditolak).
 * Diposting & Dibatalkan final; koreksi dokumen tercatat = penyesuaian baru.
 */
enum StatusPenyesuaianStok: string
{
    case Draf = 'Draf';
    case MenungguPersetujuan = 'MenungguPersetujuan';
    case Diposting = 'Diposting';
    case Dibatalkan = 'Dibatalkan';

    public function BisaBerubahKe(self $tujuan): bool
    {
        return in_array($tujuan, match ($this) {
            self::Draf => [self::MenungguPersetujuan, self::Diposting, self::Dibatalkan],
            self::MenungguPersetujuan => [self::Diposting, self::Draf],
            self::Diposting, self::Dibatalkan => [],
        }, true);
    }

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Draf => 'Draf',
            self::MenungguPersetujuan => 'Menunggu persetujuan',
            self::Diposting => 'Tercatat',
            self::Dibatalkan => 'Dibatalkan',
        };
    }
}
