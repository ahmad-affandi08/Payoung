<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Enum;

/**
 * Status klaim pembayaran dari pelanggan (F-21 bagian 4, D-169): `Menunggu` → `Diterima` (penagih mencatat pembayarannya)
 * | `Ditolak` (penagih tidak menemukan uangnya). Keduanya final. "Tidak diperlukan lagi" karena invoice sudah lunas atau
 * dibatalkan diturunkan oleh tampilan, tidak disimpan.
 */
enum StatusKonfirmasiPembayaran: string
{
    case Menunggu = 'Menunggu';
    case Diterima = 'Diterima';
    case Ditolak = 'Ditolak';

    public function BisaBerubahKe(self $tujuan): bool
    {
        return $this === self::Menunggu && $tujuan !== self::Menunggu;
    }

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Menunggu => 'Menunggu verifikasi',
            self::Diterima => 'Diterima',
            self::Ditolak => 'Ditolak',
        };
    }
}
