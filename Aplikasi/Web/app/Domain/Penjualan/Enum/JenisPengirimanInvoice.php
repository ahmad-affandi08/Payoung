<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Enum;

/** Isi pesan WhatsApp: `Invoice` (kirim tagihan baru) atau `Pengingat` (ingatkan invoice yang belum lunas). */
enum JenisPengirimanInvoice: string
{
    case Invoice = 'Invoice';
    case Pengingat = 'Pengingat';
}
