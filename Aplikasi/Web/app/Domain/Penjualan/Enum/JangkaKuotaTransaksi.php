<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Enum;

/**
 * Jangka kuota transaksi penjualan (P-04, BR-P04.8): per bulan kalender (`Paket.KuotaTransaksiBulanan`) atau per hari
 * bisnis (`Paket.KuotaTransaksiHarian`). Keduanya batas paket yang berdiri sendiri; yang tercapai lebih dulu menahan
 * transaksi baru. Nilainya bagian kontrak API (`Kuota.Transaksi.Jangka`) dan tidak boleh diganti diam-diam.
 */
enum JangkaKuotaTransaksi: string
{
    case Bulan = 'Bulan';
    case Hari = 'Hari';

    /** Kolom batas di `Paket::KOLOM_BATAS`. */
    public function AmbilKolomBatas(): string
    {
        return match ($this) {
            self::Bulan => 'KuotaTransaksiBulanan',
            self::Hari => 'KuotaTransaksiHarian',
        };
    }

    /** "bulan ini" / "hari ini" untuk kalimat ke pengguna. */
    public function AmbilSebutan(): string
    {
        return match ($this) {
            self::Bulan => 'bulan ini',
            self::Hari => 'hari ini',
        };
    }

    /** "per bulan" / "per hari". */
    public function AmbilSatuan(): string
    {
        return match ($this) {
            self::Bulan => 'per bulan',
            self::Hari => 'per hari',
        };
    }

    /** Kode butir Kotak Tindakan. */
    public function AmbilKodeTindakan(): string
    {
        return match ($this) {
            self::Bulan => 'kuota.transaksi',
            self::Hari => 'kuota.transaksi-harian',
        };
    }
}
