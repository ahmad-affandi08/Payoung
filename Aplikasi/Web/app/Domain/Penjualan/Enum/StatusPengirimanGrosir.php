<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Enum;

/**
 * Status lacak pengiriman surat jalan grosir (F-12 §9.7). Terpisah dari `StatusDokumenTerposting`: itu status
 * akuntansi dokumen (penyerahan diakui, tidak boleh bergerak lagi), ini kabar lapangan yang memang berubah-ubah.
 *
 * - `Disiapkan` → `DalamPerjalanan` | `Diterima` (diambil sendiri / serah langsung di tempat)
 * - `DalamPerjalanan` → `Diterima` | `Gagal` | `Disiapkan` (batal berangkat)
 * - `Gagal` → `DalamPerjalanan` (kirim ulang) | `Diterima`
 * - `Diterima` final: barang yang sudah diterima dan perlu kembali memakai retur, bukan menghidupkan status.
 */
enum StatusPengirimanGrosir: string
{
    case Disiapkan = 'Disiapkan';
    case DalamPerjalanan = 'DalamPerjalanan';
    case Diterima = 'Diterima';
    case Gagal = 'Gagal';

    public function BisaBerubahKe(self $tujuan): bool
    {
        return in_array($tujuan, match ($this) {
            self::Disiapkan => [self::DalamPerjalanan, self::Diterima],
            self::DalamPerjalanan => [self::Diterima, self::Gagal, self::Disiapkan],
            self::Gagal => [self::DalamPerjalanan, self::Diterima],
            self::Diterima => [],
        }, true);
    }

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Disiapkan => 'Disiapkan',
            self::DalamPerjalanan => 'Dalam perjalanan',
            self::Diterima => 'Diterima',
            self::Gagal => 'Gagal kirim',
        };
    }
}
