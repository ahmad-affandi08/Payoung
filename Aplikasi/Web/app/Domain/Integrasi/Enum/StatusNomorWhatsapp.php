<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Enum;

enum StatusNomorWhatsapp: string
{
    case MenungguPindai = 'MenungguPindai';
    case Terhubung = 'Terhubung';
    case Terputus = 'Terputus';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::MenungguPindai => 'Menunggu pindai QR',
            self::Terhubung => 'Terhubung',
            self::Terputus => 'Terputus',
        };
    }
}
