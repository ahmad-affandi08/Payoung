<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Enum;

/** Kanal pesan platform kepada pemilik tenant yang punya templat (PGL-07). Push dan in-app menyusul bersama peristiwanya. */
enum KanalKomunikasi: string
{
    case Email = 'Email';
    case WhatsApp = 'WhatsApp';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Email => 'Email',
            self::WhatsApp => 'WhatsApp',
        };
    }
}
