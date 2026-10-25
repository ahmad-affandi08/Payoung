<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Komunikasi\Enum;

/** Saluran siaran Payoung ke pemilik tenant (D-210). Notifikasi dalam aplikasi memakai Pengumuman platform (P-10). */
enum KanalSiaran: string
{
    case WhatsApp = 'WhatsApp';
    case Email = 'Email';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::WhatsApp => 'WhatsApp',
            self::Email => 'Email',
        };
    }
}
