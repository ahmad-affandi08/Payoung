<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Komunikasi\Layanan;

use App\Domain\Pengelola\Komunikasi\Enum\SektorProspek;

/** Mengisi penanda `{sektor}` pada subjek dan isi kampanye prospek dengan nama sektor penerima (D-216). */
final class PenyusunIsiKampanyeProspek
{
    public const PENANDA_SEKTOR = '{sektor}';

    public static function Isi(string $teks, SektorProspek $sektor): string
    {
        return str_replace(self::PENANDA_SEKTOR, mb_strtolower($sektor->AmbilLabel()), $teks);
    }
}
