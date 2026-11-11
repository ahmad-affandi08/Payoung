<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Harga\Enum;

/** Arah perubahan yang boleh dilakukan harga otomatis: naik dan turun, atau hanya naik (harga tidak pernah turun sendiri). */
enum ArahHargaOtomatis: string
{
    case NaikTurun = 'NaikTurun';
    case HanyaNaik = 'HanyaNaik';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::NaikTurun => 'Naik dan turun mengikuti harga beli',
            self::HanyaNaik => 'Hanya naik, tidak pernah turun sendiri',
        };
    }
}
