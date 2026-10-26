<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Enum;

/**
 * Status dokumen stok awal (F-05a, DesainF05a B.4). Perubahan hanya lewat `BisaBerubahKe()` dan dicatat di
 * `RiwayatStatusDokumen`: Draf → Memproses | Diposting | Dibuang; Memproses → Diposting | Draf (posting antrean
 * gagal); Diposting → Dibatalkan. Dibatalkan dan Dibuang final. Dokumen Diposting tidak bisa diubah; koreksinya
 * hanya pembatalan penuh.
 */
enum StatusStokAwal: string
{
    case Draf = 'Draf';
    case Memproses = 'Memproses';
    case Diposting = 'Diposting';
    case Dibatalkan = 'Dibatalkan';
    case Dibuang = 'Dibuang';

    public function BisaBerubahKe(self $tujuan): bool
    {
        return in_array($tujuan, match ($this) {
            self::Draf => [self::Memproses, self::Diposting, self::Dibuang],
            self::Memproses => [self::Diposting, self::Draf],
            self::Diposting => [self::Dibatalkan],
            self::Dibatalkan, self::Dibuang => [],
        }, true);
    }

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Draf => 'Draf',
            self::Memproses => 'Sedang dicatat',
            self::Diposting => 'Tercatat',
            self::Dibatalkan => 'Dibatalkan',
            self::Dibuang => 'Dibuang',
        };
    }

    /** Status final: tidak ada perubahan lagi. */
    public function CekFinal(): bool
    {
        return $this === self::Dibatalkan || $this === self::Dibuang;
    }
}
