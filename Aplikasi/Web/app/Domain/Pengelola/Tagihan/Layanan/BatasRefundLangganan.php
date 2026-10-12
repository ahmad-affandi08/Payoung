<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Tagihan\Layanan;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Pengelola\Tagihan\Model\PengaturanPlatform;

/**
 * Batas refund tanpa persetujuan kedua (BR-P08.2, D-113). Nilainya disimpan di `PengaturanPlatform` dan diubah Super
 * Admin dari konsol (`UbahBatasRefundLangganan`); selama belum pernah diubah dipakai nilai bawaan Rp 1.000.000.
 */
final class BatasRefundLangganan
{
    public const KUNCI = 'tagihan.batas-refund-tanpa-persetujuan';

    public const BAWAAN = '1000000';

    public function Ambil(): Uang
    {
        $nilai = PengaturanPlatform::query()->where('Kunci', self::KUNCI)->value('Nilai');

        return Uang::Dari(is_string($nilai) && $nilai !== '' ? $nilai : self::BAWAAN);
    }
}
