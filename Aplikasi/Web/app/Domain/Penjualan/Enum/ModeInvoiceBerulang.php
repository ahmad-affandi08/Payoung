<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Enum;

/** Apa yang dilakukan sistem tiap siklus: hanya membuat draf, menerbitkan, atau menerbitkan lalu mengirim lewat WhatsApp. */
enum ModeInvoiceBerulang: string
{
    case BuatDraf = 'BuatDraf';
    case Terbitkan = 'Terbitkan';
    case TerbitkanDanKirim = 'TerbitkanDanKirim';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::BuatDraf => 'Buat draf saja',
            self::Terbitkan => 'Terbitkan otomatis',
            self::TerbitkanDanKirim => 'Terbitkan dan kirim WhatsApp',
        };
    }

    public function Menerbitkan(): bool
    {
        return $this !== self::BuatDraf;
    }
}
