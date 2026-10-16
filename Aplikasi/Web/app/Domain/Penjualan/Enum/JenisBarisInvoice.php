<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Enum;

use App\Domain\Akuntansi\Enum\PeranAkun;

/**
 * Jenis baris invoice (F-21, D-166): `Jasa` dikreditkan ke Pendapatan Jasa, `Barang` (barang tanpa stok) ke Penjualan.
 * Barang berstok tidak boleh ada di invoice karena menagihnya berarti menggerakkan stok & HPP, dan itu urusan surat
 * jalan grosir (BR-12.2).
 */
enum JenisBarisInvoice: string
{
    case Jasa = 'Jasa';
    case Barang = 'Barang';

    public function AmbilPeranPendapatan(): PeranAkun
    {
        return match ($this) {
            self::Jasa => PeranAkun::PendapatanJasa,
            self::Barang => PeranAkun::Penjualan,
        };
    }

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Jasa => 'Jasa',
            self::Barang => 'Barang',
        };
    }
}
