<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Enum;

/**
 * Jenis invoice (F-21, D-166): `Penjualan` = invoice biasa yang mengakui pendapatan; `UangMuka` = invoice DP yang
 * mengakui kewajiban (Uang Muka Pelanggan) dan PPN keluaran, dipotong dari invoice akhir.
 */
enum JenisInvoice: string
{
    case Penjualan = 'Penjualan';
    case UangMuka = 'UangMuka';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Penjualan => 'Invoice',
            self::UangMuka => 'Invoice uang muka (DP)',
        };
    }
}
