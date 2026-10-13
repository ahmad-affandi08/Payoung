<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Enum;

/** Bahasa templat komunikasi (PGL-07): Indonesia (bawaan) dan Inggris. */
enum BahasaKomunikasi: string
{
    case Indonesia = 'id';
    case Inggris = 'en';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Indonesia => 'Bahasa Indonesia',
            self::Inggris => 'English',
        };
    }
}
