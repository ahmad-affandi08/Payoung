<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Enum;

/**
 * Status pengiriman invoice/pengingatnya lewat WhatsApp: `Diantrekan` → `Terkirim` | `Gagal` (percobaan habis atau
 * WhatsApp mati) | `Dibatalkan` (invoice dibatalkan/lunas sebelum terkirim). Status akhir tidak berubah lagi.
 */
enum StatusPengirimanInvoice: string
{
    case Diantrekan = 'Diantrekan';
    case Terkirim = 'Terkirim';
    case Gagal = 'Gagal';
    case Dibatalkan = 'Dibatalkan';

    public function BisaBerubahKe(self $tujuan): bool
    {
        return $this === self::Diantrekan && $tujuan !== self::Diantrekan;
    }

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Diantrekan => 'Sedang dikirim',
            self::Terkirim => 'Terkirim',
            self::Gagal => 'Gagal',
            self::Dibatalkan => 'Dibatalkan',
        };
    }
}
