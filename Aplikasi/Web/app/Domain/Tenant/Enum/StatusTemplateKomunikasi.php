<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Enum;

/** Status satu versi templat komunikasi (PGL-07): draf atau terbit (terbit tidak pernah diubah, BR-P06.1). */
enum StatusTemplateKomunikasi: string
{
    case Draf = 'Draf';
    case Terbit = 'Terbit';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Draf => 'Draf',
            self::Terbit => 'Terbit',
        };
    }
}
