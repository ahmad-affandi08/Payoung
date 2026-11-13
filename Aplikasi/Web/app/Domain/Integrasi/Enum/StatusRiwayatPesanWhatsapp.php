<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Enum;

enum StatusRiwayatPesanWhatsapp: string
{
    case Terkirim = 'Terkirim';
    case Gagal = 'Gagal';
}
