<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Enum;

/**
 * Status invoice (F-21, D-166): `Draf` → `Terbit` | `Dibatalkan`, `Terbit` → `Dibatalkan`, `Dibatalkan` final.
 *
 * Draf boleh diubah bebas dan belum bernomor, belum berjurnal, belum berpiutang. `Terbit` mengunci isi (aturan #8):
 * koreksi lewat pembatalan (jurnal pembalik) lalu invoice baru. **Status pembayaran tidak ada di sini** (sama seperti
 * `StatusDokumenTerposting`): sisa tagihan, umur, dan lunasnya milik `Piutang`; menyalinnya ke invoice berarti dua
 * sumber kebenaran yang berbeda begitu pelunasannya dibatalkan.
 */
enum StatusInvoice: string
{
    case Draf = 'Draf';
    case Terbit = 'Terbit';
    case Dibatalkan = 'Dibatalkan';

    public function BisaBerubahKe(self $tujuan): bool
    {
        return in_array($tujuan, match ($this) {
            self::Draf => [self::Terbit, self::Dibatalkan],
            self::Terbit => [self::Dibatalkan],
            self::Dibatalkan => [],
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
            self::Terbit => 'Terbit',
            self::Dibatalkan => 'Dibatalkan',
        };
    }
}
