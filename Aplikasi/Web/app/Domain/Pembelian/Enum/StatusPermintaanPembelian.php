<?php

declare(strict_types=1);

namespace App\Domain\Pembelian\Enum;

/**
 * Status permintaan pembelian (F-04, PR): Draf → Diajukan | Dibatalkan; Diajukan → Disetujui | Draf (ditolak, bisa
 * direvisi) | Dibatalkan; Disetujui → DijadikanPo | Dibatalkan. DijadikanPo & Dibatalkan final.
 */
enum StatusPermintaanPembelian: string
{
    case Draf = 'Draf';
    case Diajukan = 'Diajukan';
    case Disetujui = 'Disetujui';
    case DijadikanPo = 'DijadikanPo';
    case Dibatalkan = 'Dibatalkan';

    public function BisaBerubahKe(self $tujuan): bool
    {
        return in_array($tujuan, match ($this) {
            self::Draf => [self::Diajukan, self::Disetujui, self::Dibatalkan],
            self::Diajukan => [self::Disetujui, self::Draf, self::Dibatalkan],
            self::Disetujui => [self::DijadikanPo, self::Dibatalkan],
            self::DijadikanPo, self::Dibatalkan => [],
        }, true);
    }

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Draf => 'Draf',
            self::Diajukan => 'Menunggu persetujuan',
            self::Disetujui => 'Disetujui',
            self::DijadikanPo => 'Sudah jadi PO',
            self::Dibatalkan => 'Dibatalkan',
        };
    }
}
