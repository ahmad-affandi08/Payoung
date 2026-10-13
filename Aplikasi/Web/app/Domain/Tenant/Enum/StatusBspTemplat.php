<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Enum;

/**
 * Status persetujuan templat WhatsApp oleh BSP/Meta (P-06): dicatat manual oleh penyusun setelah mengajukan templat ke
 * penyedia. Templat yang `Ditolak` tidak bisa diterbitkan.
 */
enum StatusBspTemplat: string
{
    case MenungguPersetujuan = 'MenungguPersetujuan';
    case Disetujui = 'Disetujui';
    case Ditolak = 'Ditolak';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::MenungguPersetujuan => 'Menunggu persetujuan',
            self::Disetujui => 'Disetujui',
            self::Ditolak => 'Ditolak',
        };
    }
}
