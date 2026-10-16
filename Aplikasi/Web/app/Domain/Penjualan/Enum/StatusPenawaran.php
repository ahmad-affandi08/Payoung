<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Enum;

/**
 * Status dokumen penawaran (F-21 bagian 3, D-168): `Draf` → `Terbit` → `Diterima` | `Ditolak`; `Draf`/`Terbit`/`Diterima`
 * → `Dibatalkan`. `Ditolak` dan `Dibatalkan` final. Kedaluwarsa dan "sudah dijadikan invoice" **tidak** ada di sini:
 * keduanya diturunkan dari tanggal dan `IdInvoice` oleh `StatusTampilPenawaran`, supaya tidak ada tugas jadwal yang
 * menulis status dan tidak ada dua sumber kebenaran.
 */
enum StatusPenawaran: string
{
    case Draf = 'Draf';
    case Terbit = 'Terbit';
    case Diterima = 'Diterima';
    case Ditolak = 'Ditolak';
    case Dibatalkan = 'Dibatalkan';

    public function BisaBerubahKe(self $tujuan): bool
    {
        return in_array($tujuan, match ($this) {
            self::Draf => [self::Terbit, self::Dibatalkan],
            self::Terbit => [self::Diterima, self::Ditolak, self::Dibatalkan],
            self::Diterima => [self::Dibatalkan],
            self::Ditolak, self::Dibatalkan => [],
        }, true);
    }

    public function CekBolehDiubah(): bool
    {
        return $this === self::Draf;
    }

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Draf => 'Draf',
            self::Terbit => 'Menunggu keputusan',
            self::Diterima => 'Diterima',
            self::Ditolak => 'Ditolak',
            self::Dibatalkan => 'Dibatalkan',
        };
    }
}
