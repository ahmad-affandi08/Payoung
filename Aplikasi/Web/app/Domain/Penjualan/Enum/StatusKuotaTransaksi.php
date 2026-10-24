<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Enum;

/**
 * Keadaan kuota transaksi penjualan per bulan (P-04, BR-P04.3): `TanpaBatas` (paket tanpa batas atau tenant tanpa
 * langganan), `Normal`, `HampirHabis` (≥ 80% terpakai), dan `Habis` (terpakai ≥ batas). Nilainya bagian kontrak API
 * `konfigurasi-aplikasi` (`Kuota.Transaksi.Status`) dan tidak boleh diganti diam-diam.
 */
enum StatusKuotaTransaksi: string
{
    case TanpaBatas = 'TanpaBatas';
    case Normal = 'Normal';
    case HampirHabis = 'HampirHabis';
    case Habis = 'Habis';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::TanpaBatas => 'Tanpa batas',
            self::Normal => 'Aman',
            self::HampirHabis => 'Hampir habis',
            self::Habis => 'Habis',
        };
    }
}
