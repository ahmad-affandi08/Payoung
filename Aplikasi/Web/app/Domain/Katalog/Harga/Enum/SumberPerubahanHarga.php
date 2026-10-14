<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Harga\Enum;

/**
 * Asal perubahan harga yang dicatat di `RiwayatHarga.Sumber` (BR-03.3): form back-office, impor, panduan awal F-01
 * (tambah produk cepat), generasi varian, aksi cepat Aplikasi Owner (OWN-07), atau sistem (misal satuan produk dihapus).
 */
enum SumberPerubahanHarga: string
{
    case Manual = 'Manual';
    case Impor = 'Impor';
    case PanduanAwal = 'PanduanAwal';
    case Varian = 'Varian';
    case Sistem = 'Sistem';
    case AplikasiOwner = 'AplikasiOwner';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Manual => 'Diubah manual',
            self::Impor => 'Impor produk',
            self::PanduanAwal => 'Panduan awal',
            self::Varian => 'Generasi varian',
            self::Sistem => 'Sistem',
            self::AplikasiOwner => 'Aplikasi Owner',
        };
    }
}
