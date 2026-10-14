<?php

declare(strict_types=1);

namespace App\Domain\Pelanggan\Enum;

/** Sumber mutasi gift card (CRM-06): `Penjualan` = dipakai membayar; `Kartu` = tindakan atas kartu itu sendiri. */
enum SumberMutasiGiftCard: string
{
    case Penjualan = 'Penjualan';
    case Kartu = 'Kartu';
    case Jadwal = 'Jadwal';
}
