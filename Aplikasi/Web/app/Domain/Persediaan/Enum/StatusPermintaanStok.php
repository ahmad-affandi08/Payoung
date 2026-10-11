<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Enum;

/**
 * Status permintaan stok (F-05b, D-105). Perubahan hanya lewat `BisaBerubahKe()` dan dicatat di `RiwayatStatusDokumen`:
 * Diajukan → DiprosesSebagian | Selesai | Ditolak (oleh pengirim) | Dibatalkan (oleh peminta); DiprosesSebagian →
 * Selesai | Ditutup. `Selesai` = semua barang sudah dikirim lewat transfer; `Ditutup` = sisanya tidak akan dikirim.
 */
enum StatusPermintaanStok: string
{
    case Diajukan = 'Diajukan';
    case DiprosesSebagian = 'DiprosesSebagian';
    case Selesai = 'Selesai';
    case Ditutup = 'Ditutup';
    case Ditolak = 'Ditolak';
    case Dibatalkan = 'Dibatalkan';

    public function BisaBerubahKe(self $tujuan): bool
    {
        return in_array($tujuan, match ($this) {
            self::Diajukan => [self::DiprosesSebagian, self::Selesai, self::Ditolak, self::Dibatalkan],
            self::DiprosesSebagian => [self::Selesai, self::Ditutup],
            self::Selesai, self::Ditutup, self::Ditolak, self::Dibatalkan => [],
        }, true);
    }

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Diajukan => 'Diajukan',
            self::DiprosesSebagian => 'Diproses sebagian',
            self::Selesai => 'Selesai',
            self::Ditutup => 'Ditutup',
            self::Ditolak => 'Ditolak',
            self::Dibatalkan => 'Dibatalkan',
        };
    }

    /** Masih menunggu barang: bisa dijawab transfer, ditolak/dibatalkan (Diajukan) atau ditutup (DiprosesSebagian). */
    public function CekTerbuka(): bool
    {
        return $this === self::Diajukan || $this === self::DiprosesSebagian;
    }
}
