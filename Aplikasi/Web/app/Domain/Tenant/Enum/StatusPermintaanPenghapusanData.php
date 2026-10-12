<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Enum;

/**
 * Status permintaan penghapusan data / penutupan akun (UU PDP, P-07). `Menunggu` = dalam masa tunggu atau menunggu
 * pelaksanaan Super Admin; `Dibatalkan` oleh Owner; `Ditolak` oleh Super Admin (alasan wajib); `Dilaksanakan` final.
 */
enum StatusPermintaanPenghapusanData: string
{
    case Menunggu = 'Menunggu';
    case Dibatalkan = 'Dibatalkan';
    case Ditolak = 'Ditolak';
    case Dilaksanakan = 'Dilaksanakan';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Menunggu => 'Menunggu',
            self::Dibatalkan => 'Dibatalkan',
            self::Ditolak => 'Ditolak',
            self::Dilaksanakan => 'Dilaksanakan',
        };
    }
}
