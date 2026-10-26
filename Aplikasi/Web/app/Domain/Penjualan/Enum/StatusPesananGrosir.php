<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Enum;

/**
 * Status sales order grosir (F-12, §9.7, D-32). Perubahan hanya lewat `BisaBerubahKe()` dan dicatat di
 * `RiwayatStatusDokumen`:
 *
 * - `Draf` → `Dikonfirmasi` | `Dibatalkan`
 * - `Dikonfirmasi` → `SebagianDikirim` | `Selesai` | `Dibatalkan` (batal hanya selama belum ada surat jalan tercatat)
 * - `SebagianDikirim` → `Selesai` | `Dikonfirmasi` (semua surat jalannya dibatalkan)
 * - `Selesai` → `SebagianDikirim` | `Dikonfirmasi` (surat jalan dibatalkan sehingga tidak lagi terkirim penuh)
 * - `Dibatalkan` final
 *
 * `Selesai` bukan status final karena surat jalan yang belum difakturkan masih bisa dibatalkan (J-12.3), dan saat itu
 * SO harus kembali mencerminkan jumlah yang benar-benar terkirim. Pembatalan SO justru final: koreksi setelah barang
 * berjalan dilakukan lewat retur, bukan dengan menghidupkan kembali pesanannya.
 */
enum StatusPesananGrosir: string
{
    case Draf = 'Draf';
    case Dikonfirmasi = 'Dikonfirmasi';
    case SebagianDikirim = 'SebagianDikirim';
    case Selesai = 'Selesai';
    case Dibatalkan = 'Dibatalkan';

    public function BisaBerubahKe(self $tujuan): bool
    {
        return in_array($tujuan, match ($this) {
            self::Draf => [self::Dikonfirmasi, self::Dibatalkan],
            self::Dikonfirmasi => [self::SebagianDikirim, self::Selesai, self::Dibatalkan],
            self::SebagianDikirim => [self::Selesai, self::Dikonfirmasi],
            self::Selesai => [self::SebagianDikirim, self::Dikonfirmasi],
            self::Dibatalkan => [],
        }, true);
    }

    /** Barisnya masih boleh diubah bebas (hanya Draf). */
    public function CekBolehDiubah(): bool
    {
        return $this === self::Draf;
    }

    /** SO boleh dibuatkan surat jalan (penyerahan barang, BR-12.2). */
    public function CekBolehDikirim(): bool
    {
        return $this === self::Dikonfirmasi || $this === self::SebagianDikirim;
    }

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Draf => 'Draf',
            self::Dikonfirmasi => 'Dikonfirmasi',
            self::SebagianDikirim => 'Terkirim sebagian',
            self::Selesai => 'Selesai',
            self::Dibatalkan => 'Dibatalkan',
        };
    }
}
