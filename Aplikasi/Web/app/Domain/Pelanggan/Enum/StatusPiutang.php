<?php

declare(strict_types=1);

namespace App\Domain\Pelanggan\Enum;

/** Status piutang penjualan tempo (F-12): ikut sisa = Jumlah − Dibayar − Dikurangi; `Dibatalkan` = penjualan di-void; `DihapusBuku` = sisa tak akan tertagih dibukukan sebagai beban (E8). */
enum StatusPiutang: string
{
    case BelumLunas = 'BelumLunas';
    case DibayarSebagian = 'DibayarSebagian';
    case Lunas = 'Lunas';
    case Dibatalkan = 'Dibatalkan';
    case DihapusBuku = 'DihapusBuku';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::BelumLunas => 'Belum lunas',
            self::DibayarSebagian => 'Dibayar sebagian',
            self::Lunas => 'Lunas',
            self::Dibatalkan => 'Dibatalkan',
            self::DihapusBuku => 'Dihapus-buku',
        };
    }

    public function CekTerbuka(): bool
    {
        return $this === self::BelumLunas || $this === self::DibayarSebagian;
    }
}
