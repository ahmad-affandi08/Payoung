<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Enum;

/** Dari mana pesanan online datang: toko online `/{slug}`, kios pesan sendiri di outlet (F-17 bagian 4), atau QR meja yang dibayar QRIS dari HP tamu (SLS-04). */
enum SumberPesananOnline: string
{
    case Web = 'Web';
    case Kios = 'Kios';
    case Meja = 'Meja';
}
